<?php
namespace Opencart\Catalog\Controller\Extension\Paynl;

require_once __DIR__ . '/../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\Config;
use PayNL\Sdk\Exception\PayException as SdkPayException;
use PayNL\Sdk\Model\Request\OrderCaptureRequest;
use PayNL\Sdk\Model\Request\OrderStatusRequest;
use PayNL\Sdk\Model\Request\OrderVoidRequest;

/**
 * Class Paynl
 *
 * Shared catalog hook controller - not itself a payment method (kept
 * outside catalog/controller/payment/ deliberately, same reasoning as
 * its admin counterpart). Handles auto void/capture when an order's
 * status changes to cancelled/voided/shipped/completed, ported from
 * OC3's ControllerExtensionPaymentPaynl::paynlOnOrderStatusChange().
 *
 * Registered on catalog/controller/api/order/after - this OC4.1
 * install has no separate api/order/history route the way OC3 (and
 * presumably an older OC4 minor version) did; every api/order
 * sub-action (customer, cart, payment_address, confirm, history_add,
 * etc.) goes through one controller's index(), selected internally by
 * a ?call= GET parameter, and admin's own order page reaches it via
 * beheer/controller/sale/order.php's call() method, which simulates a
 * storefront request through a throwaway store instance - confirmed
 * by reading that file directly rather than assuming OC3's hook point
 * still applies unchanged. Since this event now fires for every
 * api/order sub-action, not just history changes, the handler guards
 * on call=history_add explicitly before doing anything.
 *
 * Also handles fast-checkout button injection (addFastCheckoutButtons/
 * addFastCheckoutMiniCartButtons/addFastCheckoutProductPageButtons),
 * ported from the same OC3 file. These inject button markup into the
 * already-rendered page HTML via regex, matched against the checkout
 * button's route=checkout/checkout href generically rather than one
 * exact hardcoded string - any OC4 theme (native or custom, like this
 * store's own bespoke checkout) can render that markup differently,
 * so an exact literal match would be fragile and theme-specific in
 * a way a portable package shouldn't assume. If buttons don't appear
 * on a given theme, the anchor pattern in each add*Buttons() method
 * is the place to adjust - this is a real, inherent limitation of DOM-string-injection-based extensions in
 * general (the OC3 original had the same fragility against its own
 * default theme), not something fully solvable without forking each
 * theme's own templates.
 *
 * @package Opencart\Catalog\Controller\Extension\Paynl
 */
class Paynl extends \Opencart\System\Engine\Controller {
	/**
	 * Order status IDs this hook cares about: 3=Shipped, 5=Complete,
	 * 7=Cancelled, 16=Voided (native OpenCart default status IDs).
	 */
	private const RELEVANT_STATUS_IDS = [3, 5, 7, 16];

	/**
	 * Paynl On Order Status Change
	 *
	 * Registered on catalog/controller/api/order/history/after
	 * (installed by AdminController::install()) - fires whenever an
	 * order's status is changed via the admin order history panel
	 * (which goes through the catalog-side API endpoint, not an
	 * admin-side one).
	 *
	 * @param string               $route
	 * @param array<string, mixed> $args
	 * @param mixed                $output
	 *
	 * @return void
	 */
	public function paynlOnOrderStatusChange(string &$route, array &$args, &$output): void {
		if (($this->request->get['call'] ?? '') !== 'history_add') {
			return;
		}

		$order_id = $this->request->post['order_id'] ?? null;
		$order_status_id = $this->request->post['order_status_id'] ?? null;

		if (empty($order_id) || !in_array((int)$order_status_id, self::RELEVANT_STATUS_IDS, true)) {
			return;
		}

		$order_id = (int)$order_id;
		$order_status_id = (int)$order_status_id;

		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (empty($order_info) || empty($order_info['payment_method']['code']) || strpos($order_info['payment_method']['code'], 'paynl') === false) {
			return;
		}

		$payment_code = $order_info['payment_method']['code'];

		$auto_void = $this->config->get('payment_paynl_general_auto_void');
		$auto_capture = $this->config->get('payment_paynl_general_auto_capture');

		if (!$auto_void && !$auto_capture) {
			return;
		}

		$this->load->model('extension/paynl/payment/' . $payment_code);
		$model_key = 'model_extension_paynl_payment_' . $payment_code;
		/** @var \Opencart\System\Engine\Proxy $method_model */
		$method_model = $this->registry->get($model_key);

		$transaction = $method_model->getTransactionFromOrderId($order_id);
		$transaction_id = $transaction['id'] ?? null;

		if (empty($transaction_id)) {
			return;
		}

		$pay_config = new Config($this->registry);

		try {
			$status_request = new OrderStatusRequest($transaction_id);
			$status_request->setConfig($pay_config->getConfig());
			$pay_transaction = $status_request->start();
		} catch (SdkPayException $e) {
			return;
		}

		if ($pay_transaction->getStatusName() !== 'AUTHORIZE') {
			return;
		}

		if (($order_status_id == 7 || $order_status_id == 16) && $auto_void) {
			$this->doAutoVoid($transaction_id, $order_id, $order_status_id, $pay_config);
		} elseif (($order_status_id == 3 || $order_status_id == 5) && $auto_capture) {
			$this->doAutoCapture($transaction_id, $order_id, $order_status_id, $pay_config);
		}
	}

	/**
	 * Do Auto Void
	 *
	 * @param string $transaction_id
	 * @param int    $order_id
	 * @param int    $order_status_id
	 * @param Config $pay_config
	 *
	 * @return void
	 */
	private function doAutoVoid(string $transaction_id, int $order_id, int $order_status_id, Config $pay_config): void {
		try {
			$void_request = new OrderVoidRequest($transaction_id);
			$void_request->setConfig($pay_config->getConfig());
			$void_request->start();
			$message = 'Pay. Auto-Void completed';
		} catch (SdkPayException $e) {
			$message = 'Pay. Auto-Void: something went wrong. ' . $e->getMessage();
		}

		$this->load->model('checkout/order');
		$this->model_checkout_order->addHistory($order_id, $order_status_id, $message, false);
	}

	/**
	 * Do Auto Capture
	 *
	 * @param string $transaction_id
	 * @param int    $order_id
	 * @param int    $order_status_id
	 * @param Config $pay_config
	 *
	 * @return void
	 */
	private function doAutoCapture(string $transaction_id, int $order_id, int $order_status_id, Config $pay_config): void {
		try {
			$capture_request = new OrderCaptureRequest($transaction_id);
			$capture_request->setConfig($pay_config->getConfig());
			$capture_request->start();
			$message = 'Pay. Auto-Capture completed';
		} catch (SdkPayException $e) {
			$message = 'Pay. Auto-Capture: something went wrong. ' . $e->getMessage();
		}

		$this->load->model('checkout/order');
		$this->model_checkout_order->addHistory($order_id, $order_status_id, $message, false);
	}

	/**
	 * Add Fast Checkout Buttons
	 *
	 * Registered on catalog/view/checkout/cart/after.
	 *
	 * @param string               $route
	 * @param array<string, mixed> $data
	 * @param string               $output
	 *
	 * @return void
	 */
	public function addFastCheckoutButtons(string &$route, array &$data, string &$output): void {
		if (!$this->isButtonAllowed('cart') || empty($this->cart->getProducts())) {
			return;
		}

		$buttons_html = $this->buildButtonsHtml($this->getFastCheckoutButtons('cart'));

		if ($buttons_html === '') {
			return;
		}

		// Anchoring on the checkout button's href (e.g. route=checkout/checkout)
		// was tried first but confirmed live not to work reliably: any store
		// with SEO-friendly URLs enabled (a standard OC4 feature, on by
		// default in many themes, including this one) renders that link as
		// a clean path like /checkout instead, with no raw route= parameter
		// anywhere in the markup to match against. Falling back to a
		// virtually universal anchor instead - sacrifices precise inline
		// placement next to the checkout button, but is far more reliable
		// across arbitrary themes than guessing at button markup.
		$output = str_replace('</body>', $buttons_html . '</body>', $output);
	}

	/**
	 * Add Fast Checkout Mini Cart Buttons
	 *
	 * Registered on catalog/view/common/cart/after.
	 *
	 * @param string               $route
	 * @param array<string, mixed> $data
	 * @param string               $output
	 *
	 * @return void
	 */
	public function addFastCheckoutMiniCartButtons(string &$route, array &$data, string &$output): void {
		if (!$this->isButtonAllowed('mini_cart')) {
			return;
		}

		$buttons_html = $this->buildButtonsHtml($this->getFastCheckoutButtons('mini_cart'));

		if ($buttons_html === '') {
			return;
		}

		$output = str_replace('</body>', $buttons_html . '</body>', $output);
	}

	/**
	 * Add Fast Checkout Product Page Buttons
	 *
	 * Registered on catalog/view/product/product/after.
	 *
	 * @param string               $route
	 * @param array<string, mixed> $data
	 * @param string               $output
	 *
	 * @return void
	 */
	public function addFastCheckoutProductPageButtons(string &$route, array &$data, string &$output): void {
		if (!$this->isButtonAllowed('product')) {
			return;
		}

		$buttons_html = $this->buildButtonsHtml($this->getFastCheckoutButtons('product'));

		if ($buttons_html === '') {
			return;
		}

		// The "Add to Cart" button is the natural anchor on a product
		// page rather than a checkout link (there usually isn't one
		// visible on this page at all).
		$pattern = '/(<button[^>]+id="button-cart"[^>]*>.*?<\/button>)/is';

		if (preg_match($pattern, $output)) {
			$output = preg_replace($pattern, '$1' . $buttons_html, $output, 1);
		} else {
			$output = str_replace('</body>', $buttons_html . '</body>', $output);
		}
	}

	/**
	 * Get Fast Checkout Buttons
	 *
	 * @param string $page One of 'cart', 'mini_cart', 'product' -
	 *                     matches the button_places values stored per
	 *                     method (AdminController's Fast Checkout tab).
	 *
	 * @return array<int, string>
	 */
	private function getFastCheckoutButtons(string $page): array {
		$this->load->model('setting/extension');
		$results = $this->model_setting_extension->getExtensionsByType('payment');
		$buttons = [];

		foreach ($results as $result) {
			$code = $result['code'];

			if (!$this->config->get('payment_' . $code . '_status')) {
				continue;
			}

			if (!$this->config->get('payment_' . $code . '_display_fast_checkout')) {
				continue;
			}

			$available_places = $this->config->get('payment_' . $code . '_button_places');

			if (empty($available_places) || !is_array($available_places) || !in_array($page, $available_places, true)) {
				continue;
			}

			$only_guests = (bool)$this->config->get('payment_' . $code . '_only_guest');

			if ($only_guests && $this->customer->isLogged()) {
				continue;
			}

			$layout = $this->getFastCheckoutButtonLayout($code);

			if ($layout !== null) {
				$buttons[] = $layout;
			}
		}

		return $buttons;
	}

	/**
	 * Get Fast Checkout Button Layout
	 *
	 * Only iDEAL has real fast-checkout button markup, matching the
	 * OC3 original's own actual scope (its switch statement only had
	 * cases for paynl_ideal and paynl_paypal - PayPal's fast-checkout
	 * was deliberately not ported here, see PaymentController's own
	 * docblock, since it needs its own separate PayPal Developer app
	 * credentials and a client-side JS SDK integration). Any other
	 * method with the Fast Checkout toggle enabled in its own settings
	 * simply gets no button rendered - the settings screen doesn't
	 * currently restrict which methods can toggle it on, but only
	 * iDEAL actually produces a working button today.
	 *
	 * @param string $method_code
	 *
	 * @return string|null
	 */
	private function getFastCheckoutButtonLayout(string $method_code): ?string {
		if ($method_code === 'paynl_ideal') {
			$url = $this->url->link('extension/paynl/payment/' . $method_code . '.initFastCheckout');

			return '<div class="paynl-fast-checkout-btn" style="margin-top:8px;">'
				. '<a href="' . htmlspecialchars($url) . '" data-method="' . htmlspecialchars($method_code) . '" class="btn btn-lg btn-primary" style="width:100%;">'
				. 'Fast Checkout (iDEAL)'
				. '</a></div>';
		}

		return null;
	}

	/**
	 * Build Buttons Html
	 *
	 * @param array<int, string> $buttons
	 *
	 * @return string
	 */
	private function buildButtonsHtml(array $buttons): string {
		if (empty($buttons)) {
			return '';
		}

		return '<div class="paynl-fast-checkout-buttons">' . implode('', $buttons) . '</div>';
	}

	/**
	 * Is Button Allowed
	 *
	 * @param string $place_name
	 *
	 * @return bool
	 */
	private function isButtonAllowed(string $place_name): bool {
		foreach ($this->getButtonPlacesConfigKeys() as $config_key) {
			$places = $this->config->get($config_key);

			if (is_array($places) && in_array($place_name, $places, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get Button Places Config Keys
	 *
	 * @return array<int, string>
	 */
	private function getButtonPlacesConfigKeys(): array {
		// Only iDEAL has real fast-checkout button markup today - see
		// getFastCheckoutButtonLayout()'s own docblock for why.
		return [
			'payment_paynl_ideal_button_places'
		];
	}
}

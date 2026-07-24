<?php
namespace Opencart\System\Library\Extension\Paynl;

require_once __DIR__ . '/paynl/vendor/autoload.php';

use PayNL\Sdk\Exception\PayException as SdkPayException;
use PayNL\Sdk\Model\Request\OrderCaptureRequest;
use PayNL\Sdk\Model\Request\OrderVoidRequest;
use PayNL\Sdk\Model\Request\TransactionRefundRequest;

/**
 * Class AdminController
 *
 * Shared base for every paynl_<method> admin controller, ported from
 * OC3's Pay_Controller_Admin. Each concrete method's admin controller
 * (e.g. PaynlIdeal) extends this directly and sets $paymentOptionId /
 * $paymentMethodName / $defaultLabel / $fastCheckout.
 *
 * Renders the FULL settings screen (shared Pay. account credentials -
 * API token/Service ID/test mode/etc - plus that method's own fields:
 * status, sort order, geo zone, min/max amount, label, instructions).
 * This mirrors the original plugin's actual behaviour faithfully: there
 * is no separate "general settings" page in the source plugin either -
 * every individual method's settings page shows both, and saving any
 * one of them updates the shared payment_paynl_general_* settings.
 *
 * Deliberately NOT ported: the original's "send suggestion to Pay.nl
 * support" (emails webshop@pay.nl on the store's behalf) and "check for
 * plugin updates" (calls the GitHub releases API for the OC3 plugin
 * specifically, which wouldn't even apply here) - both are phone-home
 * features unrelated to payment processing, not appropriate to include
 * silently in a portable package without the store owner's explicit
 * awareness/decision.
 *
 * @package Opencart\System\Extension\Paynl\Library
 */
class AdminController extends \Opencart\System\Engine\Controller {
	/**
	 * @var int
	 */
	protected int $paymentOptionId = 0;

	/**
	 * @var string
	 */
	protected string $paymentMethodName = '';

	/**
	 * @var string
	 */
	protected string $defaultLabel = '';

	/**
	 * @var bool
	 */
	protected bool $fastCheckout = false;

	/**
	 * @var array<string, mixed>
	 */
	protected array $error = [];

	public const BUTTON_PLACES = [
		['value' => 'Cart', 'key' => 'cart'],
		['value' => 'Mini cart', 'key' => 'mini_cart'],
		['value' => 'Product', 'key' => 'product']
	];

	/**
	 * Config Get
	 *
	 * Reads a general Pay. setting, falling back to this method's own
	 * same-named setting if no general value is stored yet.
	 *
	 * @param string $field
	 *
	 * @return mixed
	 */
	private function configGet(string $field) {
		$config_value = $this->config->get('payment_paynl_general_' . $field);

		if (is_null($config_value)) {
			return $this->config->get('payment_' . $this->paymentMethodName . '_' . $field);
		}

		return $config_value;
	}

	/**
	 * Own Model
	 *
	 * Loads and returns this method's own catalog-of-shared-logic model
	 * (an instance of Library\Model via the method's own concrete model
	 * class). createTables()/refreshPaymentOptions()/getPaymentOption()
	 * don't depend on which specific method loaded them, so any method's
	 * own model works for these shared calls - no separate model needed.
	 *
	 * @return \Opencart\System\Engine\Proxy
	 */
	private function ownModel(): \Opencart\System\Engine\Proxy {
		$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);

		return $this->registry->get('model_extension_paynl_payment_' . $this->paymentMethodName);
	}

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		// Every code path below this point that can reach a Pay.nl API
		// call (refreshPaymentOptions() via validateGeneral(), and the
		// refund/capture/void actions further down) uses the bundled
		// Guzzle vendor library - see PaymentController::confirm()'s own
		// comment for the full story of why this specific fix (not
		// output buffering, not the error_display config, not PHP's own
		// error_reporting()) is what's actually needed to stop its
		// curl_close() deprecation notice from corrupting a JSON
		// response. Registered once here, for the whole method, rather
		// than repeated at each individual call site.
		$previous_handler = set_error_handler(function (int $code, string $message, string $file, int $line) use (&$previous_handler): bool {
			if ($code === E_DEPRECATED || $code === E_USER_DEPRECATED) {
				return true;
			}

			return $previous_handler ? (bool)$previous_handler($code, $message, $file, $line) : false;
		});

		$this->load->language('extension/paynl/payment/' . $this->paymentMethodName);

		$data = [];

		$strings_to_translate = [
			'entry_status',
			'button_save',
			'button_cancel',
			'text_enabled',
			'text_disabled',
			'text_yes',
			'text_no',
			'entry_geo_zone',
			'text_confirm_start_tooltip',
			'text_confirm_start',
			'text_send_statusupdates_tooltip',
			'text_send_statusupdates',
			'entry_sort_order',
			'text_status_pending',
			'text_status_pending_tooltip',
			'text_status_complete',
			'text_status_complete_tooltip',
			'text_status_canceled',
			'text_status_canceled_tooltip',
			'text_minimum_amount',
			'text_maximum_amount',
			'text_payment_instructions',
			'text_payment_instructions_tooltip',
			'text_display_icon',
			'text_display_icon_tooltip'
		];

		foreach ($strings_to_translate as $string) {
			$data[$string] = $this->language->get($string);
		}

		$this->load->model('setting/setting');
		$this->document->setTitle($this->language->get('heading_title'));

		$settings = $this->model_setting_setting->getSetting('payment_' . $this->paymentMethodName);
		$settings = array_merge($settings, $this->request->post);
		$request_method = $this->request->server['REQUEST_METHOD'];

		$data['availability_fast_checkout'] = false;

		// Legally forbidden on some methods (iDEAL, standard consumer
		// cards, SEPA, etc.) per Dutch/EU surcharge rules - see
		// Model::SURCHARGE_FORBIDDEN's own docblock for the reasoning.
		// The template only renders these fields at all when this is
		// true, and the surcharge total extension separately re-checks
		// this itself before ever applying a stored value, as a second
		// layer of protection against a merchant ending up illegally
		// surcharging one of these.
		$data['surcharge_allowed'] = !in_array($this->paymentMethodName, Model::SURCHARGE_FORBIDDEN, true);

		if ($this->fastCheckout === true) {
			$data['availability_fast_checkout'] = true;
			$data['fast_checkout'] = 'payment_' . $this->paymentMethodName . '_display_fast_checkout';

			$default_shipping = 'payment_' . $this->paymentMethodName . '_default_shipping';
			$data['fast_checkout_default_shipping_name'] = $default_shipping;
			$data['fast_checkout_default_shipping'] = $settings[$default_shipping] ?? '';

			$only_guest = 'payment_' . $this->paymentMethodName . '_only_guest';
			$data['fast_checkout_only_guest_name'] = $only_guest;
			$data['fast_checkout_only_guest'] = $settings[$only_guest] ?? '';

			$button_places = 'payment_' . $this->paymentMethodName . '_button_places';
			$data['fast_checkout_button_places_name'] = $button_places;
			$data['fast_checkout_checked_button_places'] = $settings[$button_places] ?? '';

			$data['button_places_list'] = self::BUTTON_PLACES;

			$this->load->model('setting/extension');

			$installed_shipping_methods = $this->model_setting_extension->getExtensionsByType('shipping');

			$data['shipping_methods'] = [];

			foreach ($installed_shipping_methods as $shipping_extension) {
				$code = $shipping_extension['code'];

				if ($this->config->get('shipping_' . $code . '_status')) {
					$data['shipping_methods'][] = [
						'code'  => $code,
						'title' => $this->config->get('shipping_' . $code . '_title') ?: ucfirst($code)
					];
				}
			}
		}

		if ($request_method == 'POST') {
			$general_valid = $this->validateGeneral();

			if ($general_valid) {
				$settings_general = [
					'payment_paynl_general_apitoken'              => $settings['payment_paynl_general_apitoken'],
					'payment_paynl_general_serviceid'              => $settings['payment_paynl_general_serviceid'],
					'payment_paynl_general_tokencode'              => $settings['payment_paynl_general_tokencode'],
					'payment_paynl_general_testmode'               => $settings['payment_paynl_general_testmode'],
					'payment_paynl_general_gateway'                => trim($settings['payment_paynl_general_gateway'] ?? ''),
					'payment_paynl_general_prefix'                 => $settings['payment_paynl_general_prefix'],
					'payment_paynl_general_refund_processing'      => $settings['payment_paynl_general_refund_processing'],
					'payment_paynl_general_auto_void'              => $settings['payment_paynl_general_auto_void'],
					'payment_paynl_general_auto_capture'           => $settings['payment_paynl_general_auto_capture'],
					'payment_paynl_general_follow_payment_method'  => $settings['payment_paynl_general_follow_payment_method'],
					'payment_paynl_general_display_icon'           => $settings['payment_paynl_general_display_icon'],
					'payment_paynl_general_custom_exchange_url'    => $settings['payment_paynl_general_custom_exchange_url'],
					'payment_paynl_general_test_ip'                => $settings['payment_paynl_general_test_ip'],
					'payment_paynl_general_logging'                => $settings['payment_paynl_general_logging']
				];

				$this->model_setting_setting->editSetting('payment_paynl_general', $settings_general);

				foreach ($settings_general as $key => $value) {
					$this->config->set($key, $value);
				}
			}

			$method_valid = $this->validatePaymentMethod();

			if ($method_valid) {
				$this->model_setting_setting->editSetting('payment_' . $this->paymentMethodName, $settings);
			}

			if ($general_valid && $method_valid) {
				$data['success_message'] = $this->language->get('text_success');
			}
		} elseif (!empty($this->request->get['action'])) {
			if ($this->request->get['action'] == 'refund') {
				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($this->refund()));

				return;
			} elseif ($this->request->get['action'] == 'capture') {
				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($this->capture()));

				return;
			} elseif ($this->request->get['action'] == 'void') {
				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($this->void()));

				return;
			}
		}

		// Fast-checkout catalog hooks (addFastCheckoutButtons etc.) are
		// now built - see the shared catalog Paynl hook controller.
		// Registered against the native OC4 route names only - this is
		// correct and sufficient for a store using OC4's own default
		// theme (or any theme that doesn't override product/product or
		// checkout/cart). If your theme rewrites those routes to its
		// own controller (some custom themes do, via an event hook
		// that changes which route actually renders - check your
		// theme's own extension code for anything registering on
		// controller/product/product/before or
		// controller/checkout/cart/before), these events won't fire on
		// those pages, and you'll need to register additional
		// paynl_fast_checkout_* events pointed at your theme's actual
		// view routes - see README.md's "Known limitations" section.
		if ($data['availability_fast_checkout'] == true) {
			$this->load->model('setting/event');

			$fast_checkout_events = [
				'paynl_fast_checkout'             => ['catalog/view/checkout/cart/after', 'extension/paynl/paynl.addFastCheckoutButtons'],
				'paynl_fast_checkout_minicart'     => ['catalog/view/common/cart/after', 'extension/paynl/paynl.addFastCheckoutMiniCartButtons'],
				'paynl_fast_checkout_product_page' => ['catalog/view/product/product/after', 'extension/paynl/paynl.addFastCheckoutProductPageButtons']
			];

			foreach ($fast_checkout_events as $code => [$trigger, $action]) {
				if (!$this->model_setting_event->getEventByCode($code)) {
					$this->model_setting_event->addEvent([
						'code'        => $code,
						'description' => 'Pay. fast checkout button (' . $this->paymentMethodName . ')',
						'trigger'     => $trigger,
						'action'      => $action,
						'status'      => 1,
						'sort_order'  => 0
					]);
				}
			}
		}

		foreach ($settings as $key => $setting) {
			$key = str_replace('payment_' . $this->paymentMethodName . '_', '', $key);
			$data[$key] = $setting;
		}

		$data['apitoken'] = $settings['payment_paynl_general_apitoken'] ?? $this->configGet('apitoken');
		$data['serviceid'] = $settings['payment_paynl_general_serviceid'] ?? $this->configGet('serviceid');
		$data['tokencode'] = $settings['payment_paynl_general_tokencode'] ?? $this->configGet('tokencode');
		$data['testmode'] = $this->configGet('testmode');
		$data['gateway'] = $this->configGet('gateway');
		$data['prefix'] = $this->configGet('prefix');
		$data['refund_processing'] = $this->configGet('refund_processing');
		$data['auto_void'] = $this->configGet('auto_void');
		$data['auto_capture'] = $this->configGet('auto_capture');
		$data['follow_payment_method'] = $this->configGet('follow_payment_method');
		$data['custom_exchange_url'] = $this->configGet('custom_exchange_url');
		$data['test_ip'] = $this->configGet('test_ip');
		$data['logging'] = $this->configGet('logging');
		$data['display_icon'] = $this->configGet('display_icon');
		$data['text_edit'] = 'Pay. - ' . $this->defaultLabel;

		$data['error_warning'] = $this->error['warning'] ?? '';
		$data['error_tokencode'] = $this->error['tokencode'] ?? '';
		$data['error_apitoken'] = $this->error['apitoken'] ?? '';
		$data['error_serviceid'] = $this->error['serviceid'] ?? '';
		$data['error_status'] = $this->error['status'] ?? '';

		$data['payment_method_name'] = 'payment_' . $this->paymentMethodName;

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		if (empty($data['label'])) {
			$data['label'] = $this->defaultLabel;
		}

		$data['confirm_on_start'] = $data['confirm_on_start'] ?? 1;
		$data['send_status_updates'] = $data['send_status_updates'] ?? '1';
		$data['surcharge_fixed'] = $data['surcharge_fixed'] ?? '0';
		$data['surcharge_percentage'] = $data['surcharge_percentage'] ?? '0';
		$data['completed_status'] = !empty($data['completed_status']) ? $data['completed_status'] : 2;
		$data['canceled_status'] = !empty($data['canceled_status']) ? $data['canceled_status'] : 7;
		$data['refunded_status'] = !empty($data['refunded_status']) ? $data['refunded_status'] : 11;
		$data['pending_status'] = !empty($data['pending_status']) ? $data['pending_status'] : 1;
		$data['heading_title'] = $this->document->getTitle();

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['action'] = $this->url->link('extension/paynl/payment/' . $this->paymentMethodName, 'user_token=' . $this->session->data['user_token']);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

		$data['breadcrumbs'] = [
			[
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
			],
			[
				'text' => $this->language->get('text_extension'),
				'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
			],
			[
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link('extension/paynl/payment/' . $this->paymentMethodName, 'user_token=' . $this->session->data['user_token'])
			]
		];

		$data['url'] = $this->url->link('extension/paynl/payment/' . $this->paymentMethodName, 'user_token=' . $this->session->data['user_token']);
		$data['current_IP'] = $this->request->server['REMOTE_ADDR'];

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/paynl/payment/paynl_form', $data));
	}

	/**
	 * Validate General
	 *
	 * @return bool
	 */
	public function validateGeneral(): bool {
		$post = $this->request->post;
		$api_token = $post['payment_paynl_general_apitoken'] ?? null;
		$service_id = $post['payment_paynl_general_serviceid'] ?? null;
		$token_code = $post['payment_paynl_general_tokencode'] ?? null;

		if (!$this->user->hasPermission('modify', 'extension/paynl/payment/' . $this->paymentMethodName)) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (empty($service_id)) {
			$this->error['serviceid'] = $this->language->get('error_no_serviceid');
		} elseif (!preg_match('/SL-\d{4}-\d{4}/', $service_id)) {
			$this->error['serviceid'] = $this->language->get('error_wrong_serviceid');
		}

		if (empty($api_token)) {
			$this->error['apitoken'] = $this->language->get('error_no_apitoken');
		} elseif (strlen($api_token) < 40) {
			$this->error['apitoken'] = $this->language->get('error_wrong_apitoken');
		}

		if (empty($token_code)) {
			$this->error['tokencode'] = $this->language->get('text_tokencode');
		} elseif (!preg_match('/AT-\d{4}-\d{4}/', $token_code)) {
			$this->error['tokencode'] = $this->language->get('error_wrong_tokencode');
		}

		try {
			if (!empty($service_id) && !empty($api_token) && !empty($token_code)) {
				$this->ownModel()->refreshPaymentOptions($service_id, $api_token, $token_code);
			}
		} catch (SdkPayException $e) {
			$this->error['warning'] = method_exists($e, 'getFriendlyMessage') ? $e->getFriendlyMessage() : $e->getMessage();
		} catch (\Exception $e) {
			$this->error['warning'] = $e->getMessage();
		}

		return empty($this->error);
	}

	/**
	 * Validate Payment Method
	 *
	 * @return bool
	 */
	public function validatePaymentMethod(): bool {
		try {
			$payment_option = $this->ownModel()->getPaymentOption($this->paymentOptionId);
			$status = $this->request->post['payment_' . $this->paymentMethodName . '_status'] ?? 0;

			if (!$payment_option && $status == 1) {
				$this->error['status'] = $this->language->get('error_not_activated');
			}
		} catch (\Exception $e) {
			$this->error['apitoken'] = $e->getMessage();
		}

		return empty($this->error);
	}

	/**
	 * Install
	 *
	 * Called automatically the first time this (or any other paynl_*)
	 * method is installed via Marketplace > Extensions > Payments.
	 *
	 * @return void
	 */
	public function install(): void {
		$this->ownModel()->createTables();

		if ($this->config->get('payment_paynl_general_prefix') === null) {
			$this->load->model('setting/setting');

			$settings = $this->model_setting_setting->getSetting('payment_' . $this->paymentMethodName);

			$settings_general = [
				'payment_paynl_general_apitoken'             => $this->config->get('payment_paynl_general_apitoken'),
				'payment_paynl_general_serviceid'            => $this->config->get('payment_paynl_general_serviceid'),
				'payment_paynl_general_tokencode'            => $this->config->get('payment_paynl_general_tokencode'),
				'payment_paynl_general_testmode'             => $this->config->get('payment_paynl_general_testmode'),
				'payment_paynl_general_gateway'              => $this->config->get('payment_paynl_general_gateway'),
				'payment_paynl_general_prefix'                => 'Order ',
				'payment_paynl_general_refund_processing'    => $this->config->get('payment_paynl_general_refund_processing'),
				'payment_paynl_general_auto_void'            => $this->config->get('payment_paynl_general_auto_void'),
				'payment_paynl_general_auto_capture'         => $this->config->get('payment_paynl_general_auto_capture'),
				'payment_paynl_general_follow_payment_method' => 1,
				'payment_paynl_general_display_icon'         => $this->config->get('payment_paynl_general_display_icon'),
				'payment_paynl_general_custom_exchange_url'  => $this->config->get('payment_paynl_general_custom_exchange_url'),
				'payment_paynl_general_test_ip'              => $this->config->get('payment_paynl_general_test_ip'),
				'payment_paynl_general_logging'              => $this->config->get('payment_paynl_general_logging')
			];

			$this->model_setting_setting->editSetting('payment_paynl_general', $settings_general);
			$this->model_setting_setting->editSetting('payment_' . $this->paymentMethodName, $settings);
		}

		$this->load->model('setting/event');

		if (!$this->model_setting_event->getEventByCode('paynl_on_order_status_change')) {
			$this->model_setting_event->addEvent([
				'code'        => 'paynl_on_order_status_change',
				'description' => 'Pay. auto void/capture on order status change',
				'trigger'     => 'catalog/controller/api/order/after',
				'action'      => 'extension/paynl/paynl.paynlOnOrderStatusChange',
				'status'      => 1,
				'sort_order'  => 0
			]);
		}

		if (!$this->model_setting_event->getEventByCode('paynl_set_order_tab')) {
			$this->model_setting_event->addEvent([
				'code'        => 'paynl_set_order_tab',
				'description' => 'Pay. refund/capture/void panel on the admin order page',
				'trigger'     => 'admin/view/sale/order_info/after',
				'action'      => 'extension/paynl/paynl.paynlOrderInfoBefore',
				'status'      => 1,
				'sort_order'  => 0
			]);
		}
	}

	/**
	 * Refund
	 *
	 * @return array<string, string>
	 */
	private function refund(): array {
		$json = [];
		$transaction_id = $this->request->get['transaction_id'] ?? null;
		$amount = isset($this->request->get['amount']) ? (float)$this->request->get['amount'] : null;
		$currency = $this->request->get['currency'] ?? null;

		try {
			$pay_config = new Config($this->registry);
			$refund_request = new TransactionRefundRequest($transaction_id, $amount, $currency);
			$refund_request->setConfig($pay_config->getConfig());
			$refund_request->start();

			$json['success'] = 'Pay. refunded ' . $currency . ' ' . $amount . ' successfully!';
		} catch (\Exception $e) {
			$json['error'] = "Pay. couldn't refund, please try again later. " . $e->getMessage();
		}

		return $json;
	}

	/**
	 * Capture
	 *
	 * @return array<string, string>
	 */
	private function capture(): array {
		$json = [];
		$transaction_id = $this->request->get['transaction_id'] ?? null;
		$amount = isset($this->request->get['amount']) ? (float)$this->request->get['amount'] : null;
		$currency = $this->request->get['currency'] ?? null;

		try {
			$pay_config = new Config($this->registry);
			$capture_request = new OrderCaptureRequest($transaction_id);
			$capture_request->setAmount($amount);
			$capture_request->setConfig($pay_config->getConfig());
			$capture_request->start();

			$json['success'] = 'Pay. capture ' . $currency . ' ' . $amount . ' successfully!';
		} catch (\Exception $e) {
			$json['error'] = "Pay. couldn't capture, please try again later. " . $e->getMessage();
		}

		return $json;
	}

	/**
	 * Void
	 *
	 * @return array<string, string>
	 */
	public function void(): array {
		$json = [];
		$transaction_id = $this->request->get['transaction_id'] ?? null;
		$amount = isset($this->request->get['amount']) ? (float)$this->request->get['amount'] : null;
		$currency = $this->request->get['currency'] ?? null;

		try {
			$pay_config = new Config($this->registry);
			$void_request = new OrderVoidRequest($transaction_id);
			$void_request->setConfig($pay_config->getConfig());
			$void_request->start();

			$json['success'] = 'Pay. voided ' . $currency . ' ' . $amount . ' successfully!';
		} catch (\Exception $e) {
			$json['error'] = "Pay. couldn't void, please try again later. " . $e->getMessage();
		}

		return $json;
	}
}

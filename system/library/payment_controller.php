<?php
namespace Opencart\System\Library\Extension\Paynl;

require_once __DIR__ . '/paynl/vendor/autoload.php';

use PayNL\Sdk\Exception\PayException as SdkPayException;
use PayNL\Sdk\Util\Exchange;
use PayNL\Sdk\Util\ExchangeResponse;

/**
 * Class PaymentController
 *
 * Shared base for every paynl_<method> CATALOG controller, ported from
 * OC3's Pay_Controller_Payment. Each concrete method's catalog controller
 * (e.g. PaynlIdeal) extends this directly and sets $paymentOptionId /
 * $paymentMethodName.
 *
 * Covers the core checkout flow (index/startTransaction/finish/exchange)
 * plus fast-checkout (initFastCheckout/finishFastCheckout/exchangeFastCheckout):
 * express buttons on the cart/mini-cart/product page that create a blank
 * order and send the customer straight to Pay.nl, which collects their
 * contact/shipping/billing details itself (the "optimize" flow) rather
 * than going through OC's own checkout steps first.
 *
 * Fast-checkout uses a genuinely different Pay.nl API (see
 * FastCheckoutApi's own docblock) than the rest of this extension, and
 * built as a generic capability here - but only PaynlIdeal actually
 * wires it up as a concrete method with real button markup (see the
 * shared catalog Paynl hook controller's getFastCheckoutButtonLayout()),
 * matching the OC3 original's own real scope: PayPal's fast-checkout
 * needs its own separate PayPal Developer app credentials and a
 * client-side JS SDK integration, genuinely different from every other
 * method here, and was NOT ported - a clearly scoped, documented gap
 * rather than a half-built/broken feature.
 *
 * Known limitation, matching the OC3 original's own real behaviour (not
 * a new gap introduced by this port): fast-checkout orders do not get a
 * real, calculated shipping cost line, since no shipping address is
 * known yet at order-creation time - only a plain method label. The
 * charged amount reflects products/totals only.
 *
 * @package Opencart\System\Library\Extension\Paynl
 */
class PaymentController extends \Opencart\System\Engine\Controller {
	/**
	 * @var int
	 */
	protected int $paymentOptionId = 0;

	/**
	 * @var string
	 */
	protected string $paymentMethodName = '';

	/**
	 * @var array<string, mixed>
	 */
	protected array $data = [];

	/**
	 * Index
	 *
	 * Renders the bank/issuer-selection step shown at checkout for this
	 * payment method.
	 *
	 * @return string
	 */
	public function index(): string {
		$this->load->language('extension/paynl/payment/paynl');

		$this->data['text_choose_bank'] = $this->language->get('text_choose_bank');
		$this->data['button_confirm'] = $this->language->get('button_confirm');
		$this->data['button_loading'] = $this->language->get('text_loading');
		$this->data['paymentMethodName'] = $this->paymentMethodName;

		$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);

		$model_key = 'model_extension_paynl_payment_' . $this->paymentMethodName;
		/** @var \Opencart\System\Engine\Proxy $method_model */
		$method_model = $this->registry->get($model_key);

		$payment_option = $method_model->getPaymentOption($this->paymentOptionId);

		if (!$payment_option) {
			die('Payment method not available');
		}

		$this->data['instructions'] = $this->config->get('payment_' . $this->paymentMethodName . '_instructions');
		$this->data['optionSubList'] = $payment_option['optionSubs'] ?? [];

		if (!empty($this->config->get('payment_' . $this->paymentMethodName . '_coc'))) {
			$this->data['coc'] = $this->config->get('payment_' . $this->paymentMethodName . '_coc');
		}

		$company = isset($this->session->data['payment_address']['company']) ? trim($this->session->data['payment_address']['company']) : '';

		if (!empty($this->config->get('payment_' . $this->paymentMethodName . '_vat')) && strlen($company) > 0) {
			$this->data['vat'] = $this->config->get('payment_' . $this->paymentMethodName . '_vat');
		}

		if (!empty($this->config->get('payment_' . $this->paymentMethodName . '_dob'))) {
			$this->data['dob'] = $this->config->get('payment_' . $this->paymentMethodName . '_dob');
		}

		$this->data['terms'] = '';

		return $this->load->view('extension/paynl/payment/paynl_form_checkout', $this->data);
	}

	/**
	 * Confirm
	 *
	 * The entry point native OC4 checkout (and this store's own
	 * one-page checkout - see extension/nomercy_shop's own
	 * common/checkout.php::place(), which calls this exact method by
	 * route after creating the order) calls once the customer places
	 * the order. Ported in spirit from
	 * extension/opencart/catalog/controller/payment/cod.php's own
	 * confirm() - same order_id/payment_method validation and JSON
	 * response contract - but where COD finishes the order immediately
	 * (no external redirect needed), this starts the real Pay.nl
	 * transaction and sends the customer's browser to Pay.nl's hosted
	 * payment page instead, reusing the same Transaction::startTransaction()
	 * call the standalone bank-picker step (index()/startTransaction())
	 * already uses. Not part of the original OC3 plugin's own methods
	 * (that plugin only ever supported OC3's older multi-step checkout
	 * template flow) - added specifically to close the gap this
	 * store's own one-page checkout needs, confirmed live against a
	 * real "no payment methods show" / "Betaalmethode vereist!" bug
	 * report.
	 *
	 * @return void
	 */
	public function confirm(): void {
		$this->load->language('extension/paynl/payment/paynl');

		$json = [];
		$order_info = [];

		if (isset($this->session->data['order_id'])) {
			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

			if (!$order_info) {
				$json['redirect'] = $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true);

				unset($this->session->data['order_id']);
			}
		} else {
			$json['error'] = $this->language->get('error_order');
		}

		$expected_code = $this->paymentMethodName . '.' . $this->paymentMethodName;

		if (!$json && (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] != $expected_code)) {
			$json['error'] = $this->language->get('error_payment_method');
		}

		if (!$json) {
			$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);
			$model_key = 'model_extension_paynl_payment_' . $this->paymentMethodName;
			/** @var \Opencart\System\Engine\Proxy $method_model */
			$method_model = $this->registry->get($model_key);

			// Neither OC4's own error_display config value nor PHP's
			// error_reporting() actually stopped this in live testing -
			// the bundled Guzzle vendor library's own curl_close()
			// deprecation notice appears to fire during PHP's shutdown
			// phase (a cURL handle's destructor), after this method has
			// already returned, which is why suppressing it from
			// *within* this method's own execution window didn't work.
			// A replacement error handler stays registered through
			// shutdown (not restored - nothing else needs the original
			// handler back before this request ends), silently
			// swallowing deprecation notices specifically while passing
			// everything else through to OC4's own handler.
			$previous_handler = set_error_handler(function (int $code, string $message, string $file, int $line) use (&$previous_handler): bool {
				if ($code === E_DEPRECATED || $code === E_USER_DEPRECATED) {
					return true;
				}

				return $previous_handler ? (bool)$previous_handler($code, $message, $file, $line) : false;
			});

			try {
				$method_model->log('confirm(): starting payment for order ' . $order_info['order_id'] . ' via ' . $this->paymentMethodName);

				$transaction = new Transaction($this->registry);
				$json['redirect'] = $transaction->startTransaction($order_info, $this->paymentOptionId, $this->paymentMethodName);
			} catch (SdkPayException $e) {
				$json['error'] = $this->language->get($this->getErrorMessage($e->getMessage()));
			} catch (\Throwable $e) {
				$method_model->log('confirm(): unexpected error: ' . $e->getMessage());
				$json['error'] = $this->language->get('text_pay_api_error_general');
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Start Transaction
	 *
	 * AJAX endpoint the checkout page's own JS calls once the customer
	 * confirms their bank/issuer choice - kicks off the real Pay.nl order.
	 *
	 * @return void
	 */
	public function startTransaction(): void {
		$this->load->language('extension/paynl/payment/paynl');
		$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);
		$this->load->model('checkout/order');

		$model_key = 'model_extension_paynl_payment_' . $this->paymentMethodName;
		/** @var \Opencart\System\Engine\Proxy $method_model */
		$method_model = $this->registry->get($model_key);

		$order_id = $this->session->data['order_id'] ?? null;
		$order_info = $order_id ? $this->model_checkout_order->getOrder((int)$order_id) : [];

		$response = [];

		// See confirm()'s own comment for why this - not config,
		// not error_reporting() - is the fix that actually works.
		$previous_handler = set_error_handler(function (int $code, string $message, string $file, int $line) use (&$previous_handler): bool {
			if ($code === E_DEPRECATED || $code === E_USER_DEPRECATED) {
				return true;
			}

			return $previous_handler ? (bool)$previous_handler($code, $message, $file, $line) : false;
		});

		try {
			$method_model->log('start payment: ' . $this->paymentMethodName);

			$transaction = new Transaction($this->registry);
			$response['success'] = $transaction->startTransaction($order_info, $this->paymentOptionId, $this->paymentMethodName);
		} catch (SdkPayException $e) {
			$this->load->language('extension/paynl/payment/paynl');
			$response['error'] = $this->language->get($this->getErrorMessage($e->getMessage()));
		} catch (\Throwable $e) {
			$method_model->log('startTransaction(): unexpected error: ' . $e->getMessage());
			$this->load->language('extension/paynl/payment/paynl');
			$response['error'] = $this->language->get('text_pay_api_error_general');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($response));
	}

	/**
	 * Finish
	 *
	 * Customer's browser lands here after returning from Pay.nl's own
	 * payment page (the "returnurl"). Redirects on to the real success/
	 * failure page based on the reported status code.
	 *
	 * @return void
	 */
	public function finish(): void {
		$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);

		$status_code = $this->request->get['statusCode'] ?? null;
		$status = $status_code !== null ? Helper::getStatus((int)$status_code) : null;

		if ($status !== null && ($status == Model::STATUS_COMPLETE || $status == Model::STATUS_PENDING)) {
			$this->response->redirect($this->url->link('checkout/success'));
		}

		$this->load->language('extension/paynl/payment/paynl');

		if ($status_code == -90) {
			$this->session->data['error'] = $this->language->get('text_cancel');
		} elseif ($status_code == -64 || $status_code == -63) {
			$this->session->data['error'] = $this->language->get('text_denied');
		}

		$this->response->redirect($this->url->link('checkout/checkout'));
	}

	/**
	 * Exchange
	 *
	 * Pay.nl's own server-to-server webhook callback (the "exchangeUrl").
	 * Confirms/updates the order based on the real, authoritative status
	 * Pay.nl reports - the customer's browser is never involved in this
	 * request.
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	public function exchange(): void {
		$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);

		$model_key = 'model_extension_paynl_payment_' . $this->paymentMethodName;
		/** @var \Opencart\System\Engine\Proxy $method_model */
		$method_model = $this->registry->get($model_key);

		$exchange_response = new ExchangeResponse(true, '');

		$pay_config = new Config($this->registry);
		$config = $pay_config->getConfig();
		$exchange = new Exchange();

		try {
			$pay_order = $exchange->process($config);

			$transaction_id = $pay_order->getOrderId();
			$action = $exchange->getAction();
			$status_name = Helper::getStatus($pay_order->getStatusCode());

			if ($action == 'pending') {
				$exchange_response->set(true, 'Processed pending');
			} elseif (empty($transaction_id)) {
				$exchange_response->set(true, 'ignoring, invalid arguments');
			} elseif (str_starts_with($action, 'refund')) {
				$exchange_response->set(true, 'ignoring REFUND');

				if ($this->config->get('payment_paynl_general_refund_processing')) {
					if ($status_name != Model::STATUS_REFUNDED) {
						$exchange_response->set(false, 'unexpected status for refund: ' . $status_name);
					} else {
						$exchange_response = $method_model->processTransaction($transaction_id, $pay_order);
					}
				}
			} elseif ($action == 'cancel') {
				$exchange_response->set(true, 'ignoring CANCELED');
			} else {
				try {
					$method_model->log('Exchange: ' . $action . ' transactionId: ' . $transaction_id);
					$exchange_response = $method_model->processTransaction($transaction_id, $pay_order);
				} catch (PayException $e) {
					$exchange_response->set(false, 'Plugin Error ' . $e->getMessage());
				} catch (SdkPayException $e) {
					$exchange_response->set(false, 'API Error ' . $e->getMessage());
				} catch (\Exception $e) {
					$exchange_response->set(false, 'Error ' . $e->getMessage());
				}
			}
		} catch (\Throwable $exception) {
			$exchange_response->set(false, 'Error ' . $exception->getMessage());
		}

		$exchange->setExchangeResponse($exchange_response);
	}

	/**
	 * Is Ajax
	 *
	 * @return bool
	 */
	public function isAjax(): bool {
		return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
	}

	/**
	 * Get Error Message
	 *
	 * Maps a raw Pay.nl API error message to a language key for a
	 * friendlier customer-facing message.
	 *
	 * @param string $message
	 *
	 * @return string
	 */
	public function getErrorMessage(string $message): string {
		$message = strtolower(trim($message));

		if (stripos($message, 'minimum amount') !== false || stripos($message, 'maximum amount') !== false || stripos($message, 'amount is not allowed') !== false) {
			return 'text_pay_api_error_amount';
		} elseif (stripos($message, 'is not activated for this sales location') !== false) {
			return 'text_pay_api_error_activated';
		} elseif (stripos($message, 'not allowed in country') !== false) {
			return 'text_pay_api_error_country';
		}

		return 'text_pay_api_error_general';
	}

	/**
	 * Create Blank Fast Checkout Order
	 *
	 * Builds and inserts a new OpenCart order from the current cart
	 * contents alone - no customer/address information exists yet at
	 * this point, since fast-checkout skips OC's own checkout steps
	 * entirely. Pay.nl collects those details itself (the "optimize"
	 * flow) and reports them back via the exchange webhook, which fills
	 * them in afterward through Model::updateOrderAfterWebhook().
	 *
	 * @param string $default_shipping_config_key Config key holding the
	 *                                             default shipping method
	 *                                             code for this method
	 *                                             (e.g.
	 *                                             payment_paynl_ideal_default_shipping)
	 *
	 * @return array<string, mixed> The order data used to create the
	 *                              order, with 'order_id' added.
	 */
	protected function createBlankFastCheckoutOrder(string $default_shipping_config_key): array {
		$this->load->model('setting/extension');
		$this->load->model('checkout/order');

		$is_logged = $this->customer->isLogged();

		$shipping_code = (string)$this->config->get($default_shipping_config_key);
		$shipping_method = null;

		if (!empty($shipping_code)) {
			$shipping_extension_code = explode('.', $shipping_code)[0];
			$shipping_title = $this->config->get('shipping_' . $shipping_extension_code . '_title') ?: ucfirst($shipping_extension_code);
			$shipping_method = ['name' => $shipping_title, 'code' => $shipping_code];
		}

		$order_data = [
			'subscription_id'         => 0,
			'invoice_prefix'          => $this->config->get('config_invoice_prefix'),
			'store_id'                => $this->config->get('config_store_id'),
			'store_name'              => $this->config->get('config_name'),
			'store_url'               => $this->config->get('config_url'),
			'customer_id'             => $is_logged ? $this->customer->getId() : 0,
			'customer_group_id'       => $is_logged ? $this->customer->getGroupId() : (int)$this->config->get('config_customer_group_id'),
			'firstname'               => $is_logged ? $this->customer->getFirstName() : 'Guest',
			'lastname'                => $is_logged ? $this->customer->getLastName() : 'Customer',
			'email'                   => $is_logged ? $this->customer->getEmail() : 'guest@example.com',
			'telephone'               => $is_logged ? $this->customer->getTelephone() : '',
			'custom_field'            => [],
			'payment_address_id'      => 0,
			'payment_firstname'       => '',
			'payment_lastname'        => '',
			'payment_company'         => '',
			'payment_address_1'       => '',
			'payment_address_2'       => '',
			'payment_city'            => '',
			'payment_postcode'        => '',
			'payment_country'         => '',
			'payment_country_id'      => 0,
			'payment_zone'            => '',
			'payment_zone_id'         => 0,
			'payment_address_format'  => '',
			'payment_custom_field'    => [],
			'payment_method'          => null,
			'shipping_address_id'     => 0,
			'shipping_firstname'      => '',
			'shipping_lastname'       => '',
			'shipping_company'        => '',
			'shipping_address_1'      => '',
			'shipping_address_2'      => '',
			'shipping_city'           => '',
			'shipping_postcode'       => '',
			'shipping_country'        => '',
			'shipping_country_id'     => 0,
			'shipping_zone'           => '',
			'shipping_zone_id'        => 0,
			'shipping_address_format' => '',
			'shipping_custom_field'   => [],
			'shipping_method'         => $shipping_method
		];

		$order_data['products'] = [];

		foreach ($this->cart->getProducts() as $product) {
			$order_data['products'][] = [
				'product_id' => $product['product_id'],
				'master_id'  => $product['master_id'] ?? 0,
				'name'       => $product['name'],
				'model'      => $product['model'],
				'quantity'   => $product['quantity'],
				'price'      => $product['price'],
				'total'      => $product['total'],
				'tax'        => $this->tax->getTax($product['price'], $product['tax_class_id']),
				'reward'     => $product['reward'] ?? 0,
				'option'     => $product['option'] ?? []
			];
		}

		// Totals - only product-derived totals apply here (sub_total, tax,
		// coupon, handling, low_order_fee, total) since there's no known
		// shipping address yet for a shipping cost to be calculated
		// against (see this class's own docblock).
		$totals = [];
		$taxes = $this->cart->getTaxes();
		$total = 0;

		$results = $this->model_setting_extension->getExtensionsByType('total');
		$sort_order = [];

		foreach ($results as $key => $value) {
			$sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
		}

		array_multisort($sort_order, SORT_ASC, $results);

		foreach ($results as $result) {
			if ($result['code'] === 'shipping') {
				continue;
			}

			if ($this->config->get('total_' . $result['code'] . '_status')) {
				$this->load->model('extension/' . $result['extension'] . '/total/' . $result['code']);

				$model_key = 'model_extension_' . $result['extension'] . '_total_' . $result['code'];
				/** @var \Opencart\System\Engine\Proxy $total_model */
				$total_model = $this->registry->get($model_key);

				// Same by-reference closure-property call as Transaction::startTransaction() -
				// see that method's own comment for why.
				($total_model->getTotal)($totals, $taxes, $total);
			}
		}

		$order_data['totals'] = [];

		foreach ($totals as $total_row) {
			$order_data['totals'][] = [
				'extension'  => $total_row['extension'] ?? '',
				'code'       => $total_row['code'],
				'title'      => $total_row['title'],
				'value'      => $total_row['value'],
				'sort_order' => $total_row['sort_order'] ?? 0
			];
		}

		$order_data['total'] = $total;

		$order_data['affiliate_id'] = 0;
		$order_data['commission'] = 0;
		$order_data['marketing_id'] = 0;
		$order_data['tracking'] = '';

		$order_data['comment'] = '';
		$order_data['language_id'] = $this->config->get('config_language_id');
		$order_data['language_code'] = $this->config->get('config_language');
		$order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
		$order_data['currency_code'] = $this->session->data['currency'];
		$order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
		$order_data['ip'] = $this->request->server['REMOTE_ADDR'] ?? '';
		$order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'] ?? '';
		$order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'] ?? '';
		$order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'] ?? '';

		$order_id = $this->model_checkout_order->addOrder($order_data);

		$this->model_checkout_order->addHistory($order_id, (int)$this->config->get('config_order_status_id'), '', false);

		$order_data['order_id'] = $order_id;

		return $order_data;
	}

	/**
	 * Send Request
	 *
	 * Sends the fast-checkout order to Pay.nl's dedicated fast-checkout
	 * API (see FastCheckoutApi) and records the resulting transaction.
	 *
	 * @param array<string, mixed> $order_data From createBlankFastCheckoutOrder()
	 *
	 * @return array<string, mixed> ['data' => [...Pay.nl response...]] on
	 *                              success, or ['error' => '...'] on failure
	 */
	protected function sendRequest(array $order_data): array {
		$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);
		$model_key = 'model_extension_paynl_payment_' . $this->paymentMethodName;
		/** @var \Opencart\System\Engine\Proxy $method_model */
		$method_model = $this->registry->get($model_key);

		$response = [];

		// Same fix as confirm()/startTransaction() - see confirm()'s
		// own comment for the full story of why this specific approach
		// is needed.
		$previous_handler = set_error_handler(function (int $code, string $message, string $file, int $line) use (&$previous_handler): bool {
			if ($code === E_DEPRECATED || $code === E_USER_DEPRECATED) {
				return true;
			}

			return $previous_handler ? (bool)$previous_handler($code, $message, $file, $line) : false;
		});

		try {
			$method_model->log('start fast checkout payment: ' . $this->paymentMethodName);

			$pay_config = new Config($this->registry);

			$api = new FastCheckoutApi();
			$api->setApiToken($pay_config->getApiToken());
			$api->setServiceId($pay_config->getServiceId());
			$api->setTestmode($pay_config->isTestMode());
			$api->setOrderNumber((string)$order_data['order_id']);

			$amount = (int)round($order_data['total'] * 100 * $order_data['currency_value']);
			$api->setAmount($amount);
			$api->setCurrency($order_data['currency_code']);

			foreach ($this->cart->getProducts() as $product) {
				$price_with_tax = $this->tax->calculate($product['price'] * $order_data['currency_value'], $product['tax_class_id'], (bool)$this->config->get('config_tax'));
				$tax = $price_with_tax - ($product['price'] * $order_data['currency_value']);
				$price = (int)round($price_with_tax * 100);

				$api->addProduct((string)$product['product_id'], $product['name'], $price, (int)$product['quantity'], Helper::calculateTaxClass($price_with_tax, $tax));
			}

			$api->setDescription('');
			$api->setReference((string)$order_data['order_id']);
			$api->setOptimize();

			$payment_method = $order_data['payment_method'] ?: $this->paymentOptionId;
			$api->setPaymentMethod($payment_method);

			$return_url = $this->url->link('extension/paynl/payment/' . $this->paymentMethodName . '.finishFastCheckout');
			$exchange_url = $this->url->link('extension/paynl/payment/' . $this->paymentMethodName . '.exchangeFastCheckout');

			$custom_exchange_url = trim((string)$pay_config->getCustomExchangeUrl());

			if (!empty($custom_exchange_url)) {
				$exchange_url = htmlspecialchars_decode($custom_exchange_url);
			}

			$api->setReturnUrl($return_url);
			$api->setExchangeUrl($exchange_url);

			$response['data'] = $api->doRequest();

			$method_model->addTransaction(
				$response['data']['orderId'],
				(int)$order_data['order_id'],
				$this->paymentOptionId,
				$amount,
				$api->getPostData()
			);
		} catch (PayException $e) {
			$message = $this->getErrorMessage($e->getMessage());
			$this->load->language('extension/paynl/payment/paynl');
			$response['error'] = $this->language->get($message);
		} catch (\Exception $e) {
			$response['error'] = 'Onbekende fout: ' . $e->getMessage();
		}

		return $response;
	}

	/**
	 * Init Fast Checkout
	 *
	 * Entry point for the express "Fast Checkout" button - creates a
	 * blank order and sends the customer straight to Pay.nl.
	 *
	 * @return void
	 */
	public function initFastCheckout(): void {
		if (empty($this->cart->getProducts())) {
			$this->response->redirect($this->url->link('checkout/cart'));

			return;
		}

		$order_data = $this->createBlankFastCheckoutOrder('payment_' . $this->paymentMethodName . '_default_shipping');
		$response = $this->sendRequest($order_data);

		if ($this->isAjax()) {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($response));

			return;
		}

		if (!empty($response['data']['links']['redirect'])) {
			$this->response->redirect($response['data']['links']['redirect']);

			return;
		}

		$this->response->redirect($this->url->link('checkout/cart'));
	}

	/**
	 * Finish Fast Checkout
	 *
	 * Customer's browser lands here after returning from Pay.nl's own
	 * payment page for a fast-checkout order.
	 *
	 * @return void
	 */
	public function finishFastCheckout(): void {
		$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);

		$status_code = $this->request->get['statusCode'] ?? null;
		$status = $status_code !== null ? Helper::getStatus((int)$status_code) : null;

		if ($status !== null && ($status == Model::STATUS_COMPLETE || $status == Model::STATUS_PENDING)) {
			$this->cart->clear();
			$this->response->redirect($this->url->link('checkout/success'));

			return;
		}

		$this->load->language('extension/paynl/payment/paynl');

		if ($status_code == -90) {
			$this->session->data['error'] = $this->language->get('text_cancel');
		} elseif ($status_code == -63) {
			$this->session->data['error'] = $this->language->get('text_denied');
		}

		$this->response->redirect($this->url->link('checkout/cart'));
	}

	/**
	 * Get Customer Group Id
	 *
	 * @param int $order_id
	 *
	 * @return int
	 */
	private function getCustomerGroupId(int $order_id): int {
		$query = $this->db->query("SELECT `customer_group_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $order_id . "'");

		return (int)($query->row['customer_group_id'] ?? 0);
	}

	/**
	 * Exchange Fast Checkout
	 *
	 * The fast-checkout-specific webhook callback. Unlike the core
	 * exchange() flow, this one also receives the customer/address
	 * details Pay.nl collected during its own "optimize" flow (the TGU
	 * payload's checkoutData), which get written into the order via
	 * Model::updateOrderAfterWebhook().
	 *
	 * @return void
	 */
	public function exchangeFastCheckout(): void {
		$this->load->model('setting/setting');
		$this->load->model('checkout/order');

		$pay_config = new Config($this->registry);
		$config = $pay_config->getConfig();
		$exchange = new Exchange();

		try {
			$pay_order = $exchange->process($config);
			$action = $exchange->getAction();
			$status_code = $pay_order->getStatusCode();
			$order_id = (int)$pay_order->getReference();
			$pay_order_id = $pay_order->getOrderId();
			$status = Helper::getStatus($status_code);
			$payload = $exchange->getPayLoad();
			$checkout_data = $payload->getCheckoutData();
		} catch (\Exception $e) {
			$exchange->setResponse(false, 'Error fetching transaction. ' . $e->getMessage());

			return;
		}

		$this->load->model('extension/paynl/payment/' . $this->paymentMethodName);
		$model_key = 'model_extension_paynl_payment_' . $this->paymentMethodName;
		/** @var \Opencart\System\Engine\Proxy $method_model */
		$method_model = $this->registry->get($model_key);

		try {
			if ($status === Model::STATUS_COMPLETE) {
				$billing_address = $checkout_data['billingAddress'] ?? [];
				$shipping_address = $checkout_data['shippingAddress'] ?? [];
				$customer = $checkout_data['customer'] ?? [];

				$payment_data = [
					'firstname' => $customer['firstName'] ?? '',
					'lastname'  => $customer['lastName'] ?? '',
					'address_1' => trim(($billing_address['streetName'] ?? '') . ' ' . ($billing_address['streetNumber'] ?? '')),
					'city'      => $billing_address['city'] ?? '',
					'postcode'  => $billing_address['zipCode'] ?? '',
					'country'   => $billing_address['countryCode'] ?? '',
					'method'    => (string)$this->paymentOptionId
				];

				$shipping_data = [
					'firstname' => $customer['firstName'] ?? '',
					'lastname'  => $customer['lastName'] ?? '',
					'address_1' => trim(($shipping_address['streetName'] ?? '') . ' ' . ($shipping_address['streetNumber'] ?? '')),
					'city'      => $shipping_address['city'] ?? '',
					'postcode'  => $shipping_address['zipCode'] ?? '',
					'country'   => $shipping_address['countryCode'] ?? ''
				];

				$customer_data = [
					'email'     => $customer['email'] ?? '',
					'phone'     => $customer['phone'] ?? '',
					'lastname'  => $customer['lastName'] ?? '',
					'firstname' => $customer['firstName'] ?? ''
				];

				$method_model->updateTransactionStatus($pay_order_id, $status);

				$result = $method_model->updateOrderAfterWebhook($order_id, $payment_data, $shipping_data, $customer_data, $this->paymentMethodName);

				if ($result === false) {
					$exchange->setResponse(false, 'Order not found');

					return;
				}

				$this->model_checkout_order->addHistory($order_id, (int)$this->config->get('config_complete_status'), 'Order paid via fast checkout (' . $this->paymentMethodName . ').');

				$exchange->setResponse(true, 'processed successfully');

				return;
			}

			if ($status === Model::STATUS_CANCELED) {
				$this->model_checkout_order->addHistory($order_id, (int)$this->config->get('config_void_status_id'), 'Order cancelled');
				$method_model->updateTransactionStatus($pay_order_id, $status);

				$exchange->setResponse(true, 'Order cancelled');

				return;
			}
		} catch (PayException $e) {
			$exchange->setResponse(false, 'Plugin Error: ' . $e->getMessage());

			return;
		} catch (SdkPayException $e) {
			$exchange->setResponse(false, 'API Error: ' . $e->getMessage());

			return;
		} catch (\Exception $e) {
			$exchange->setResponse(false, 'Unknown Error: ' . $e->getMessage());

			return;
		}

		if ($action == 'pending') {
			$exchange->setResponse(true, 'ignoring pending');
		} else {
			$exchange->setResponse(false, 'Unexpected status: ' . $status . ' for action: ' . $action);
		}
	}
}

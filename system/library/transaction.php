<?php
namespace Opencart\System\Library\Extension\Paynl;

require_once __DIR__ . '/paynl/vendor/autoload.php';

use PayNL\Sdk\Exception\PayException as SdkPayException;
use PayNL\Sdk\Model\Address;
use PayNL\Sdk\Model\Company;
use PayNL\Sdk\Model\Customer;
use PayNL\Sdk\Model\Order;
use PayNL\Sdk\Model\Product;
use PayNL\Sdk\Model\Products;
use PayNL\Sdk\Model\Request\OrderCreateRequest;
use PayNL\Sdk\Model\Stats;

/**
 * Class Transaction
 *
 * Starts a Pay.nl order/transaction for the current cart, ported from
 * OC3's Pay_Controller_Transaction. Builds the full Pay.nl order payload
 * (customer, addresses, line items, totals) from the current registry's
 * cart/currency/tax state, then returns the redirect URL the customer
 * should be sent to.
 *
 * @package Opencart\System\Extension\Paynl\Library
 */
class Transaction {
	/**
	 * @var \Opencart\System\Engine\Registry
	 */
	protected \Opencart\System\Engine\Registry $registry;

	/**
	 * @var Config
	 */
	protected Config $payConfig;

	/**
	 * Constructor
	 *
	 * @param \Opencart\System\Engine\Registry $registry
	 */
	public function __construct(\Opencart\System\Engine\Registry $registry) {
		$this->registry = $registry;
		$this->payConfig = new Config($registry);
	}

	/**
	 * Start Transaction
	 *
	 * @param array<string, mixed> $order_info
	 * @param int                  $payment_option
	 * @param string               $payment_method_name
	 *
	 * @return string The Pay.nl-hosted payment URL to redirect the customer to.
	 *
	 * @throws SdkPayException
	 */
	public function startTransaction(array $order_info, int $payment_option, string $payment_method_name): string {
		if (empty($order_info)) {
			throw new SdkPayException('Order information is missing', 0, 500);
		}

		/** @var \Opencart\System\Library\Request $request */
		$request_lib = $this->registry->get('request');
		/** @var \Opencart\System\Engine\Config $config */
		$config = $this->registry->get('config');
		/** @var \Opencart\System\Library\Url $url */
		$url = $this->registry->get('url');
		/** @var \Opencart\System\Library\Cart\Cart $cart */
		$cart = $this->registry->get('cart');
		/** @var \Opencart\System\Library\Cart\Currency $currency */
		$currency = $this->registry->get('currency');
		/** @var \Opencart\System\Library\Cart\Tax $tax */
		$tax = $this->registry->get('tax');
		/** @var \Opencart\System\Library\Session $session */
		$session = $this->registry->get('session');
		/** @var \Opencart\System\Engine\Loader $load */
		$load = $this->registry->get('load');

		$pay_request = new OrderCreateRequest();
		$pay_request->setConfig($this->payConfig->getConfig(true));
		$pay_request->setServiceId($this->payConfig->getServiceId());
		$pay_request->setDescription((string)$order_info['order_id']);
		$pay_request->setReference((string)$order_info['order_id']);
		$pay_request->setCurrency($order_info['currency_code']);
		$pay_request->setPaymentMethodId($payment_option);

		if (!empty($request_lib->post['optionSubId'])) {
			$pay_request->setIssuerId($request_lib->post['optionSubId']);
		}

		$return_url = $url->link('extension/paynl/payment/' . $payment_method_name . '.finish');
		$exchange_url = $url->link('extension/paynl/payment/' . $payment_method_name . '.exchange');

		$custom_exchange_url = trim($this->payConfig->getCustomExchangeUrl());

		if (!empty($custom_exchange_url)) {
			$exchange_url = htmlspecialchars_decode($custom_exchange_url);
		}

		$pay_request->setReturnurl($return_url);
		$pay_request->setExchangeUrl($exchange_url);
		$pay_request->setTestmode($this->payConfig->isTestMode());

		$language = strtolower(substr($order_info['language_code'] ?? 'nl', 0, 2));
		$country = strtoupper($order_info['payment_iso_code_2'] ?? 'NL');

		$customer = new Customer();
		$customer->setFirstName($order_info['firstname'] ?? '');
		$customer->setLastName($order_info['lastname'] ?? '');

		if (!empty($request_lib->post['dob'])) {
			$customer->setBirthDate(preg_replace('([^0-9/])', '', htmlentities($request_lib->post['dob'])));
		}

		$customer->setPhone($order_info['telephone'] ?? '');
		$customer->setEmail($order_info['email'] ?? '');
		$customer->setLanguage($language);
		$customer->setLocale($language . '_' . $country);

		$company = new Company();
		$company->setName($order_info['payment_company'] ?? '');
		$company->setCoc((string)($request_lib->post['coc'] ?? ''));
		$company->setVat((string)($request_lib->post['vat'] ?? ''));
		$company->setCountryCode($order_info['payment_iso_code_2']);

		$customer->setCompany($company);
		$pay_request->setCustomer($customer);

		$pay_order = new Order();
		$pay_order->setCountryCode($order_info['payment_iso_code_2']);

		$delivery_address_string = $order_info['shipping_address_1'] . ' ' . $order_info['shipping_address_2'];
		[$street, $house_number] = Helper::splitAddress($delivery_address_string);

		$delivery_address = new Address();
		$delivery_address->setCode('dev');
		$delivery_address->setStreetName($street);
		$delivery_address->setStreetNumber($house_number);
		$delivery_address->setZipCode($order_info['shipping_postcode']);
		$delivery_address->setCity($order_info['shipping_city']);
		$delivery_address->setRegionCode($order_info['shipping_zone_code'] ?? '');
		$delivery_address->setCountryCode($order_info['shipping_iso_code_2']);
		$pay_order->setDeliveryAddress($delivery_address);

		$invoice_address_string = $order_info['payment_address_1'] . ' ' . $order_info['payment_address_2'];
		[$street, $house_number] = Helper::splitAddress($invoice_address_string);

		$invoice_address = new Address();
		$invoice_address->setCode('inv');
		$invoice_address->setStreetName($street);
		$invoice_address->setStreetNumber($house_number);
		$invoice_address->setZipCode($order_info['payment_postcode']);
		$invoice_address->setCity($order_info['payment_city']);
		$invoice_address->setRegionCode($order_info['payment_zone_code'] ?? '');
		$invoice_address->setCountryCode($order_info['payment_iso_code_2']);
		$pay_order->setInvoiceAddress($invoice_address);

		$products = new Products();

		foreach ($cart->getProducts() as $product_item) {
			$price_with_tax = $currency->convert(
				$tax->calculate($product_item['price'], $product_item['tax_class_id'], (bool)$config->get('config_tax')),
				$config->get('config_currency'),
				$session->data['currency']
			);

			$price_without_tax = $currency->convert($product_item['price'], $config->get('config_currency'), $session->data['currency']);
			$product_tax = $price_with_tax - $price_without_tax;

			$pay_product = new Product();
			$pay_product->setId((string)$product_item['product_id']);
			$pay_product->setDescription($product_item['name']);
			$pay_product->setType(Product::TYPE_ARTICLE);
			$pay_product->setAmount(round($price_with_tax, 2));
			$pay_product->setCurrency($order_info['currency_code']);
			$pay_product->setQuantity($product_item['quantity']);
			$pay_product->setVatPercentage($price_without_tax > 0 ? ($product_tax / $price_without_tax * 100) : 0);
			$products->addProduct($pay_product);
		}

		// Totals (shipping, coupon, handling, etc.) - sub_total/tax/total are
		// already represented by the products above, so skip those three.
		$totals = [];
		$taxes = $cart->getTaxes();
		$total = 0;

		$load->model('setting/extension');
		/** @var \Opencart\Catalog\Model\Setting\Extension $model_setting_extension */
		$model_setting_extension = $this->registry->get('model_setting_extension');
		$results = $model_setting_extension->getExtensionsByType('total');

		$sort_order = [];

		foreach ($results as $key => $value) {
			$sort_order[$key] = $config->get('total_' . $value['code'] . '_sort_order');
		}

		array_multisort($sort_order, SORT_ASC, $results);

		$taxes_before_by_code = [];

		foreach ($results as $result) {
			if ($config->get('total_' . $result['code'] . '_status')) {
				$load->model('extension/' . $result['extension'] . '/total/' . $result['code']);

				$model_key = 'model_extension_' . $result['extension'] . '_total_' . $result['code'];
				/** @var \Opencart\System\Engine\Proxy $total_model Proxies to the real total model instance */
				$total_model = $this->registry->get($model_key);

				$taxes_before = array_sum($taxes);

				// __call magic can't pass by reference, so OC4 total models expose
				// getTotal as a public Closure property rather than a regular method.
				($total_model->getTotal)($totals, $taxes, $total);

				$taxes_before_by_code[$result['code']] = array_sum($taxes) - $taxes_before;
			}
		}

		foreach ($totals as $total_row) {
			if (in_array($total_row['code'], ['sub_total', 'tax', 'total'], true)) {
				continue;
			}

			$total_row_tax = $taxes_before_by_code[$total_row['code']] ?? 0;

			$total_excl = $currency->convert($total_row['value'], $config->get('config_currency'), $session->data['currency']);
			$total_row_tax = $currency->convert($total_row_tax, $config->get('config_currency'), $session->data['currency']);
			$total_incl = $total_excl + $total_row_tax;

			switch ($total_row['code']) {
				case 'shipping':
					$type = Product::TYPE_SHIPPING;
					break;
				case 'coupon':
				case 'voucher':
					$type = Product::TYPE_DISCOUNT;
					break;
				default:
					$type = Product::TYPE_ARTICLE;
					break;
			}

			$pay_product = new Product();
			$pay_product->setId($total_row['code']);
			$pay_product->setDescription($total_row['title']);
			$pay_product->setType($type);
			$pay_product->setAmount(round($total_incl, 2));
			$pay_product->setCurrency($order_info['currency_code']);
			$pay_product->setQuantity(1);
			$pay_product->setVatPercentage($total_row_tax > 0 ? ($total_row_tax / $total_excl * 100) : 0);
			$products->addProduct($pay_product);
		}

		$pay_order->setProducts($products);
		$pay_request->setOrder($pay_order);
		$pay_request->setStats((new Stats())->setObject($this->payConfig->getObject()));

		$amount = round($currency->convert($order_info['total'], $config->get('config_currency'), $session->data['currency']), 2);
		$pay_request->setAmount($amount);

		$pay_transaction = $pay_request->start();

		$load->model('extension/paynl/payment/' . $payment_method_name);

		$model_key = 'model_extension_paynl_payment_' . $payment_method_name;
		/** @var \Opencart\System\Engine\Proxy $method_model Proxies to the real Model instance */
		$method_model = $this->registry->get($model_key);

		$method_model->addTransaction(
			$pay_transaction->getOrderId(),
			(int)$order_info['order_id'],
			$payment_option,
			(int)$amount,
			'',
			isset($request_lib->post['optionSubId']) ? (int)$request_lib->post['optionSubId'] : null
		);

		return $pay_transaction->getPaymentUrl();
	}
}

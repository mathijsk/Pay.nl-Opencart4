<?php
namespace Opencart\System\Library\Extension\Paynl;

require_once __DIR__ . '/paynl/vendor/autoload.php';

use PayNL\Sdk\Model\Request\ServiceGetConfigRequest;
use PayNL\Sdk\Util\ExchangeResponse;

/**
 * Class Model
 *
 * Shared Pay. (pay.nl) transaction/payment-option storage and webhook
 * processing, ported from OC3's Pay_Model. Each concrete payment method
 * (e.g. PaynlIdeal) has its own catalog-side model class extending this
 * one and setting $paymentOptionId / $paymentMethodName.
 *
 * Table names and raw-SQL style kept identical to the OC3 original
 * (paynl_transactions / paynl_paymentoptions / paynl_paymentoption_subs)
 * since there's no reason to redesign a working schema during a port.
 *
 * @package Opencart\System\Extension\Paynl\Library
 */
class Model extends \Opencart\System\Engine\Model {
	public const STATUS_PENDING = 'PENDING';
	public const STATUS_CANCELED = 'CANCELED';
	public const STATUS_COMPLETE = 'COMPLETE';
	public const STATUS_REFUNDED = 'REFUNDED';

	/**
	 * Payment methods where Dutch/EU rules (ACM guidance, per
	 * https://www.acm.nl/nl/verkoop-aan-consumenten/de-koop-sluiten/betaalmogelijkheden-aanbieden)
	 * do not allow a payment surcharge to be charged to the consumer -
	 * iDEAL/Wero, standard consumer credit/debit cards, SEPA methods,
	 * mobile wallets tied to a standard card, and specific local
	 * European bank methods (EPS/Giropay/Sofort/Blik). Checked both
	 * when deciding whether to show the surcharge fields on a method's
	 * settings page (AdminController) and, as a defense-in-depth
	 * safety net, before ever actually applying a stored surcharge
	 * value (the surcharge total extension) - a merchant should never
	 * be able to end up illegally surcharging one of these even via a
	 * direct database edit or a future bug elsewhere.
	 *
	 * Pay. doesn't expose a separate "business" vs "consumer" card
	 * payment option, so paynl_visamastercard/paynl_groupedcreditcards
	 * are included here (can't distinguish the one case - a genuinely
	 * business card - where a surcharge would be allowed, so the
	 * safer default is to disallow it for the whole method).
	 *
	 * @var array<int, string>
	 */
	public const SURCHARGE_FORBIDDEN = [
		'paynl_ideal',
		'paynl_wero',
		'paynl_visamastercard',
		'paynl_groupedcreditcards',
		'paynl_mistercash',
		'paynl_maestro',
		'paynl_incasso',
		'paynl_overboeking',
		'paynl_applepay',
		'paynl_googlepay',
		'paynl_eps',
		'paynl_giropay',
		'paynl_sofort',
		'paynl_sofortbanking',
		'paynl_sofortbankingds',
		'paynl_sofortbankinghr',
		'paynl_blik'
	];

	/**
	 * @var int
	 */
	protected int $paymentOptionId = 0;

	/**
	 * @var string
	 */
	protected string $paymentMethodName = '';

	/**
	 * Create Tables
	 *
	 * Called automatically from each payment method's admin controller
	 * install() the first time any paynl_* method is installed. Guarded
	 * with IF NOT EXISTS so installing a second method is a no-op here.
	 *
	 * @return void
	 */
	public function createTables(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paynl_transactions` (
				`id` varchar(255) NOT NULL,
				`orderId` int(11) NOT NULL,
				`optionId` int(11) NOT NULL,
				`optionSubId` int(11) DEFAULT NULL,
				`amount` int(11) NOT NULL,
				`status` varchar(255) NOT NULL,
				`created` int(11) NOT NULL,
				`last_update` int(11) DEFAULT NULL,
				`start_data` text NOT NULL,
				PRIMARY KEY (`id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
		");

		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paynl_paymentoptions` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`optionId` int(11) NOT NULL,
				`serviceId` varchar(20) NOT NULL,
				`name` varchar(255) NOT NULL,
				`img` varchar(255) NOT NULL,
				`update_date` datetime NOT NULL,
				PRIMARY KEY (`id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
		");

		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paynl_paymentoption_subs` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`optionSubId` int(11) NOT NULL,
				`paymentOptionId` int(11) NOT NULL,
				`name` varchar(255) NOT NULL,
				`img` varchar(255) NOT NULL,
				`update_date` datetime NOT NULL,
				PRIMARY KEY (`id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
		");
	}

	/**
	 * Add Transaction
	 *
	 * @param string      $transaction_id
	 * @param int         $order_id
	 * @param int         $option_id
	 * @param int         $amount
	 * @param mixed       $start_data
	 * @param int|null    $option_sub_id
	 *
	 * @return void
	 */
	public function addTransaction(string $transaction_id, int $order_id, int $option_id, int $amount, $start_data, ?int $option_sub_id = null): void {
		$sql = "INSERT INTO `" . DB_PREFIX . "paynl_transactions` (id, orderId, optionId, optionSubId, amount, status, created, start_data) VALUES ("
			. "'" . $this->db->escape($transaction_id) . "'"
			. ",'" . $this->db->escape((string)$order_id) . "'"
			. ",'" . $this->db->escape((string)$option_id) . "'"
			. "," . (is_null($option_sub_id) ? 'NULL' : "'" . $this->db->escape((string)$option_sub_id) . "'")
			. ",'" . $this->db->escape((string)$amount) . "'"
			. ", '" . self::STATUS_PENDING . "'"
			. ", UNIX_TIMESTAMP() "
			. ",'" . $this->db->escape(json_encode($start_data)) . "'"
			. ")";

		$this->db->query($sql);
	}

	/**
	 * Refresh Payment Options
	 *
	 * Pulls the full list of payment methods/sub-methods this Pay.nl
	 * service account has enabled, and caches them locally so each
	 * method's own admin/catalog controllers don't need a live API call
	 * per page load.
	 *
	 * @param string $service_id
	 * @param string $api_token
	 * @param string $token_code
	 *
	 * @return void
	 */
	public function refreshPaymentOptions(string $service_id, string $api_token, string $token_code): void {
		$pay_config = new Config($this->registry);
		$config = (new ServiceGetConfigRequest($service_id))->setConfig($pay_config->getConfig(false, $token_code, $api_token))->start();

		$service_id_escaped = $this->db->escape($service_id);

		// Remove the old cached options first.
		$sql = "DELETE options, optionsubs FROM `" . DB_PREFIX . "paynl_paymentoptions` as options "
			. "LEFT JOIN `" . DB_PREFIX . "paynl_paymentoption_subs` as optionsubs ON optionsubs.paymentOptionId = options.id";

		$this->db->query($sql);

		if (!$config) {
			return;
		}

		foreach ($config->getPaymentMethods() as $method) {
			$img = $this->db->escape((string)$method->getImage());

			$option_id = $this->db->escape((string)$method->getId());
			$name = $this->db->escape((string)$method->getName());

			$img_explode = explode('/', $img);
			$brand_img = end($img_explode);
			$brand_img_explode = explode('.', $brand_img);
			$brand_id = !empty(reset($brand_img_explode)) ? reset($brand_img_explode) : 0;
			$brand_id = $this->db->escape((string)$brand_id);

			$image_json = json_encode(['img' => $img, 'brand_id' => $brand_id]);

			$sql = "INSERT INTO `" . DB_PREFIX . "paynl_paymentoptions` "
				. "(optionId, serviceId, name, img, update_date) VALUES "
				. "('$option_id', '$service_id_escaped', '$name', '" . $this->db->escape($image_json) . "', NOW())";

			$this->db->query($sql);

			$internal_option_id = $this->db->getLastId();

			if ($method->hasOptions()) {
				foreach ($method->getOptions() as $option_sub) {
					$option_sub_id = $this->db->escape((string)($option_sub['id'] ?? ''));
					$sub_name = $this->db->escape((string)($option_sub['name'] ?? ''));

					$sql = "INSERT INTO `" . DB_PREFIX . "paynl_paymentoption_subs` "
						. "(optionSubId, paymentOptionId, name, update_date) VALUES "
						. "('$option_sub_id', $internal_option_id, '$sub_name', NOW())";

					$this->db->query($sql);
				}
			}
		}
	}

	/**
	 * Log
	 *
	 * @param mixed $text
	 *
	 * @return void
	 */
	public function log($text): void {
		if ($this->config->get('payment_paynl_general_logging') !== '0') {
			$log = new \Opencart\System\Library\Log('pay.log');
			$log->write($text);
		}
	}

	/**
	 * Get Payment Option
	 *
	 * @param int $payment_option_id
	 *
	 * @return array<string, mixed>|false
	 */
	public function getPaymentOption(int $payment_option_id) {
		$payment_option_id = $this->db->escape((string)$payment_option_id);

		$sql = "SELECT * FROM `" . DB_PREFIX . "paynl_paymentoptions` WHERE optionId = '$payment_option_id' LIMIT 1;";
		$result = $this->db->query($sql);

		$payment_option = $result->row;

		if (empty($payment_option)) {
			return false;
		}

		$sql = "SELECT * FROM `" . DB_PREFIX . "paynl_paymentoption_subs` WHERE paymentOptionId = '" . $payment_option['id'] . "' ORDER BY name ASC;";
		$result = $this->db->query($sql);

		$option_subs = [];

		foreach ($result->rows as $option_sub) {
			$option_subs[] = [
				'id'          => $option_sub['optionSubId'],
				'name'        => $option_sub['name'],
				'img'         => $option_sub['img'],
				'update_date' => $option_sub['update_date']
			];
		}

		$img_data = json_decode($payment_option['img']);

		if (is_object($img_data)) {
			$img = $img_data->img;
			$brand_id = $img_data->brand_id;
		} else {
			$img = $payment_option['img'];
			$brand_id = 0;
		}

		return [
			'id'          => $payment_option['optionId'],
			'name'        => $payment_option['name'],
			'optionSubs'  => $option_subs,
			'img'         => $img,
			'update_date' => $payment_option['update_date'],
			'brand_id'    => $brand_id
		];
	}

	/**
	 * Get Transaction
	 *
	 * @param string $transaction_id
	 *
	 * @return array<string, mixed>
	 */
	public function getTransaction(string $transaction_id): array {
		$sql = "SELECT * FROM `" . DB_PREFIX . "paynl_transactions` WHERE id = '" . $this->db->escape($transaction_id) . "' LIMIT 1;";
		$result = $this->db->query($sql);

		return $result->row;
	}

	/**
	 * Get Transaction From Order Id
	 *
	 * @param int $order_id
	 *
	 * @return array<string, mixed>
	 */
	public function getTransactionFromOrderId(int $order_id): array {
		$sql = "SELECT * FROM `" . DB_PREFIX . "paynl_transactions` WHERE orderId = '" . $this->db->escape((string)$order_id) . "' LIMIT 1;";
		$result = $this->db->query($sql);

		return $result->row;
	}

	/**
	 * Get Statusses Of Order
	 *
	 * Because an order can have multiple transactions (e.g. a retry after
	 * a cancelled attempt), this checks whether the order has already
	 * been completed by any of them before accepting a new status.
	 *
	 * @param int $order_id
	 *
	 * @return array<int, string>
	 */
	public function getStatussesOfOrder(int $order_id): array {
		$sql = "SELECT `status` FROM `" . DB_PREFIX . "paynl_transactions` WHERE orderId = '" . $this->db->escape((string)$order_id) . "';";
		$result = $this->db->query($sql);

		$statusses = [];

		foreach ($result->rows as $row) {
			$statusses[] = $row['status'];
		}

		return $statusses;
	}

	/**
	 * Update Transaction Status
	 *
	 * @param string $transaction_id
	 * @param string $status
	 *
	 * @return bool
	 *
	 * @throws PayException
	 */
	public function updateTransactionStatus(string $transaction_id, string $status): bool {
		if (!in_array($status, [self::STATUS_CANCELED, self::STATUS_COMPLETE, self::STATUS_PENDING, self::STATUS_REFUNDED], true)) {
			throw new PayException('Invalid transaction status');
		}

		// Safety net so a processed order cannot silently flip back to canceled.
		$transaction = $this->getTransaction($transaction_id);

		if (empty($transaction)) {
			throw new PayException('Transaction not found');
		}

		// Because an order can have multiple transactions, check every transaction's status for this order.
		$order_statusses = $this->getStatussesOfOrder((int)$transaction['orderId']);

		if (in_array(self::STATUS_COMPLETE, $order_statusses, true) && $status != self::STATUS_COMPLETE && $status != self::STATUS_REFUNDED) {
			die('TRUE|already paid');
		}

		if ($transaction['status'] == $status) {
			// Status unchanged.
			return true;
		}

		$sql = "UPDATE `" . DB_PREFIX . "paynl_transactions` SET status = '$status', last_update = UNIX_TIMESTAMP() WHERE id = '" . $this->db->escape($transaction_id) . "'";

		return (bool)$this->db->query($sql);
	}

	/**
	 * Get Customer Group Id
	 *
	 * @param int $order_id
	 *
	 * @return int
	 */
	private function getCustomerGroupId(int $order_id): int {
		$sql = "SELECT `customer_group_id` FROM `" . DB_PREFIX . "order` WHERE order_id = '" . $this->db->escape((string)$order_id) . "';";
		$result = $this->db->query($sql);

		$customer_group_id = 0;

		foreach ($result->rows as $row) {
			$customer_group_id = (int)$row['customer_group_id'];
		}

		return $customer_group_id;
	}

	/**
	 * Get Config
	 *
	 * @param string $key
	 * @param string $payment_method
	 *
	 * @return mixed
	 */
	private function getConfig(string $key, string $payment_method) {
		return $this->config->get('payment_' . $payment_method . '_' . $key);
	}

	/**
	 * Get Methods
	 *
	 * The actual entry point OC4's checkout/payment_method model calls
	 * (catalog/model/checkout/payment_method.php's getMethods()) - a
	 * different name/signature than OC3's getMethod($address, $amount):
	 * plural, and takes only the payment address, no separate order
	 * amount parameter. Computes the order amount itself from the
	 * current cart total and delegates to the existing getMethod() logic
	 * below, which still does the real min/max-amount/geo-zone/customer-
	 * type checks.
	 *
	 * @param array<string, mixed> $payment_address
	 *
	 * @return array<string, mixed>|false
	 */
	public function getMethods(array $payment_address = []) {
		$order_amount = false;

		if ($this->registry->has('cart')) {
			/** @var \Opencart\System\Library\Cart\Cart $cart */
			$cart = $this->registry->get('cart');
			$order_amount = $cart->getTotal();
		}

		return $this->getMethod($payment_address, $order_amount);
	}

	/**
	 * Get Method
	 *
	 * Determines whether this payment method is currently offerable at
	 * checkout for the given address/order amount, and if so, returns
	 * the array shape OC4's checkout actually expects - confirmed by
	 * reading extension/opencart/catalog/model/payment/cod.php's own
	 * getMethods() directly, not assumed from the OC3 original (which
	 * used a different, flatter code/title/terms/sort_order shape).
	 * OC4's own checkout.js (and this store's own, ported from the
	 * same expectation) requires an 'option' dict per method - even a
	 * single-option method like this needs one entry under it, or the
	 * frontend throws trying to iterate a missing/undefined 'option'
	 * for this method specifically, which - since that iteration
	 * happens in one shared loop over ALL payment methods - silently
	 * broke the ENTIRE payment method list on checkout, not just this
	 * one method's own entry. Found and fixed live after a real
	 * customer-facing report ("no payment methods show").
	 *
	 * @param array<string, mixed>|false $address
	 * @param float|false                 $order_amount
	 *
	 * @return array<string, mixed>|false
	 */
	public function getMethod($address = false, $order_amount = false) {
		$pm = $this->paymentMethodName;

		if (empty($this->getConfig('status', $pm))) {
			return false;
		}

		$payment_options = $this->getPaymentOption($this->paymentOptionId);
		$min_order_amount = $this->getConfig('total', $pm);
		$max_order_amount = $this->getConfig('totalmax', $pm);
		$geo_zone = (int)$this->getConfig('geo_zone_id', $pm);
		$customer_type = $this->getConfig('customer_type', $pm);

		if ($order_amount !== false && $order_amount >= 0) {
			if (!empty($min_order_amount) && $order_amount < $min_order_amount) {
				return false;
			}

			if (!empty($max_order_amount) && $order_amount > $max_order_amount) {
				return false;
			}
		}

		$sql = "SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . $geo_zone . "' AND country_id = '" . (int)($address['country_id'] ?? 0) . "'"
			. " AND (zone_id = '" . (int)($address['zone_id'] ?? 0) . "' OR zone_id = '0')";

		$query = $this->db->query($sql);

		if (!empty($geo_zone) && $query->num_rows == 0) {
			return false;
		}

		$company = isset($address['company']) ? trim($address['company']) : '';

		if (($customer_type == 'private' && !empty($company)) || ($customer_type == 'business' && empty($company))) {
			return false;
		}

		$icon = '';
		$icon_size = $this->config->get('payment_paynl_general_display_icon');

		if (!empty($icon_size) && !empty($payment_options['brand_id'])) {
			$style = ' style="width:50px; height:50px; display:inline-block; vertical-align:middle; margin-right:6px;"';

			switch ($icon_size) {
				case '20x20':
					$style = ' style="width:20px; height:20px; display:inline-block; vertical-align:middle; margin-right:6px;"';
					break;
				case '25x25':
					$style = ' style="width:25px; height:25px; display:inline-block; vertical-align:middle; margin-right:6px;"';
					break;
				case '50x50':
					$style = ' style="width:50px; height:50px; display:inline-block; vertical-align:middle; margin-right:6px;"';
					break;
				case '75x75':
					$style = ' style="width:75px; height:75px; display:inline-block; vertical-align:middle; margin-right:6px;"';
					break;
				case '100x100':
					$style = ' style="width:100px; height:100px; display:inline-block; vertical-align:middle; margin-right:6px;"';
					break;
			}

			// Explicit inline-block + vertical-align here rather than
			// relying on the surrounding page's own CSS: many themes'
			// CSS resets (Tailwind's preflight, for one - confirmed live
			// this is exactly what was happening on a real store) set
			// img { display: block; } by default, which pushes this
			// label's text onto a new line below the icon instead of
			// beside it. Self-contained inline styling works regardless
			// of the theme, which matters for a portable package that
			// can't assume any particular theme's CSS.
			$icon = '<img' . $style . ' class="paynl_icon" src="/extension/paynl/catalog/view/image/payment/paynl/' . $payment_options['brand_id'] . '.png">';
		}

		$name = $icon . $this->getLabel();

		return [
			'code'       => $pm,
			'name'       => $name,
			'option'     => [
				$pm => [
					// Native OC4's own checkout/payment_method.php::save()
					// does explode('.', $submitted_value) and requires
					// exactly two parts (group code, sub-option key) -
					// confirmed by reading that controller directly, not
					// assumed. Matches COD's own native 'cod.cod' pattern:
					// same value repeated on both sides of the dot for a
					// method with only one real option, like every method
					// here.
					'code' => $pm . '.' . $pm,
					'name' => $name
				]
			],
			'sort_order' => $this->getConfig('sort_order', $pm)
		];
	}

	/**
	 * Get Label
	 *
	 * @return string
	 */
	public function getLabel(): string {
		return (string)$this->config->get('payment_' . $this->paymentMethodName . '_label');
	}

	/**
	 * Is Already Paid
	 *
	 * @param int $order_id
	 *
	 * @return bool
	 */
	public function isAlreadyPaid(int $order_id): bool {
		return in_array(self::STATUS_COMPLETE, $this->getStatussesOfOrder($order_id), true);
	}

	/**
	 * Process Transaction
	 *
	 * Called from the exchange (webhook) handler once Pay.nl's status
	 * for a transaction is known. Updates the local transaction record
	 * and, where appropriate, the OpenCart order's own status/history.
	 *
	 * @param string $transaction_id
	 * @param object $pay_order Instance of \PayNL\Sdk\Model\Pay\PayOrder
	 *
	 * @return \PayNL\Sdk\Util\ExchangeResponse
	 *
	 * @throws PayException
	 * @throws \Exception
	 */
	public function processTransaction(string $transaction_id, $pay_order): ExchangeResponse {
		$this->load->model('setting/setting');
		$this->load->model('checkout/order');

		$settings = $this->model_setting_setting->getSetting('payment_' . $this->paymentMethodName);
		$this->log('processTransaction ' . $transaction_id . ' name: ' . $this->paymentMethodName . print_r($settings, true));

		$transaction = $this->getTransaction($transaction_id);
		$status = Helper::getStatus($pay_order->getStatusCode());

		// Get the order status id from the admin's own settings for this method.
		$order_status_id = Helper::getOrderStatusId($pay_order->getStatusCode(), $settings, $this->paymentMethodName);

		$this->log('pre ' . print_r([$pay_order->getStatusCode(), $this->paymentMethodName, $status, $order_status_id], true));

		// Update the status in the database.
		$this->updateTransactionStatus($transaction_id, $status);
		$message = 'Pay. Updated order to ' . $status . '.';

		$order_info = $this->model_checkout_order->getOrder((int)$transaction['orderId']);

		// Make sure Pay. status isn't pending when the order already has a status - Pay. will retry the call in that case.
		if ($order_info['order_status_id'] != 0 && $status == Model::STATUS_PENDING) {
			throw new \Exception('unexpected status ' . $status);
		}

		$new_payment_method_arr = $this->getPaymentOption((int)$pay_order->getPaymentMethod());

		if (!empty($new_payment_method_arr) && $this->paymentOptionId != $pay_order->getPaymentMethod() && $this->config->get('payment_paynl_general_follow_payment_method') !== '0') {
			$new_payment_method = $new_payment_method_arr['name'];
			$old_payment_method = $order_info['payment_method']['name'] ?? '';
			$order_id = (int)$transaction['orderId'];
			$follow_payment_message = 'Pay. Updated payment method from ' . $old_payment_method . ' to ' . $new_payment_method . '.';
			$customer_group_id = $this->getCustomerGroupId($order_id);

			if (!empty($customer_group_id)) {
				// Narrow, direct field update rather than editOrder(): OC4's editOrder() has
				// significant side effects (auto-voids the order via addHistory(), deletes
				// and re-adds every product/total row) that OC3's plain-UPDATE editOrder()
				// never had - not appropriate just to record a payment method name change.
				$sql = "UPDATE `" . DB_PREFIX . "order` SET "
					. "customer_group_id = '" . (int)$customer_group_id . "', "
					. "payment_method = '" . $this->db->escape(json_encode(['name' => $new_payment_method, 'code' => $this->paymentMethodName])) . "'"
					. " WHERE order_id = '" . $order_id . "'";

				$this->db->query($sql);

				$order_info['payment_method']['name'] = $new_payment_method;

				$this->log('addHistory: ' . print_r([$order_id, $order_status_id, $follow_payment_message], true));
				$this->model_checkout_order->addHistory($order_id, $order_status_id, $follow_payment_message, false);
			}
		}

		if (($order_info['payment_method']['code'] ?? '') != $this->paymentMethodName && $status == Model::STATUS_CANCELED) {
			return new ExchangeResponse(false, 'Not cancelling because the last used method is not this method');
		}

		if ($order_info['order_status_id'] != $order_status_id) {
			// Only update when the status actually changed.
			$settings_send_updates = $this->model_setting_setting->getValue('payment_' . $this->paymentMethodName . '_send_status_updates');
			$send_status_update = $settings_send_updates == 1;

			if ($order_info['order_status_id'] == 0 && $status != Model::STATUS_COMPLETE && !$send_status_update) {
				// Not confirmed yet, only save when completed.
				$this->log('No update, returning. Vars:' . print_r([$order_info['order_status_id'], $status], true));
			}

			if ($order_info['order_status_id'] == 5 && $status == Model::STATUS_COMPLETE) {
				$this->log('Not updating ' . $order_info['order_status_id'] . ' vs ' . $order_status_id);

				return new ExchangeResponse(true, 'Ignored: ' . $status);
			}

			$this->log('addHistory: ' . print_r([$order_info['order_id'], $order_status_id, $message, $send_status_update], true));
			$this->model_checkout_order->addHistory($order_info['order_id'], $order_status_id, $message, $send_status_update);
		} else {
			$this->log('Not updating ' . $order_info['order_status_id'] . ' vs ' . $order_status_id);
		}

		return new ExchangeResponse(true, 'Updated to: ' . $status);
	}

	/**
	 * Update Order After Webhook
	 *
	 * Used by fast-checkout flows: fills in the payment/shipping/customer
	 * details Pay.nl collected during checkout, since a fast-checkout
	 * order is created with those fields still blank.
	 *
	 * @param int                  $order_id
	 * @param array<string, mixed> $payment_data
	 * @param array<string, mixed> $shipping_data
	 * @param array<string, mixed> $customer_data
	 * @param string               $payment_code
	 *
	 * @return bool
	 */
	public function updateOrderAfterWebhook(int $order_id, array $payment_data, array $shipping_data, array $customer_data, string $payment_code): bool {
		$order_query = $this->db->query("SELECT customer_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . $order_id . "'");

		if (!$order_query->num_rows) {
			return false;
		}

		$fields = [];
		$fields[] = "payment_firstname = '" . $this->db->escape($payment_data['firstname']) . "'";
		$fields[] = "payment_lastname = '" . $this->db->escape($payment_data['lastname']) . "'";
		$fields[] = "payment_address_1 = '" . $this->db->escape($payment_data['address_1']) . "'";
		$fields[] = "payment_city = '" . $this->db->escape($payment_data['city']) . "'";
		$fields[] = "payment_postcode = '" . $this->db->escape($payment_data['postcode']) . "'";
		$fields[] = "payment_country = '" . $this->db->escape($payment_data['country']) . "'";
		$fields[] = "payment_method = '" . $this->db->escape(json_encode(['name' => $payment_data['method'], 'code' => $payment_code])) . "'";

		$fields[] = "shipping_firstname = '" . $this->db->escape($shipping_data['firstname']) . "'";
		$fields[] = "shipping_lastname = '" . $this->db->escape($shipping_data['lastname']) . "'";
		$fields[] = "shipping_address_1 = '" . $this->db->escape($shipping_data['address_1']) . "'";
		$fields[] = "shipping_city = '" . $this->db->escape($shipping_data['city']) . "'";
		$fields[] = "shipping_postcode = '" . $this->db->escape($shipping_data['postcode']) . "'";
		$fields[] = "shipping_country = '" . $this->db->escape($shipping_data['country']) . "'";

		if ($order_query->row['customer_id'] == 0) {
			$fields[] = "firstname = '" . $this->db->escape($customer_data['firstname']) . "'";
			$fields[] = "lastname = '" . $this->db->escape($customer_data['lastname']) . "'";
			$fields[] = "email = '" . $this->db->escape($customer_data['email']) . "'";
			$fields[] = "telephone = '" . $this->db->escape($customer_data['phone']) . "'";
		}

		$sql = "UPDATE `" . DB_PREFIX . "order` SET " . implode(', ', $fields) . " WHERE order_id = '" . $order_id . "'";

		$this->db->query($sql);

		return true;
	}
}

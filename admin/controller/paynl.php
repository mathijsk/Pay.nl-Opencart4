<?php
namespace Opencart\Admin\Controller\Extension\Paynl;

require_once __DIR__ . '/../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\Config;
use PayNL\Sdk\Model\Request\OrderStatusRequest;
use PayNL\Sdk\Model\Request\TransactionStatusRequest;

/**
 * Class Paynl
 *
 * Shared admin hook controller - not itself a payment method (kept
 * outside admin/controller/payment/ deliberately, so OC4's own
 * extension-discovery glob never lists it as one). Injects a refund/
 * capture/void panel into the order detail page for any order paid
 * through a paynl_* method, ported from OC3's
 * ControllerExtensionPaymentPaynl::paynlOrderInfoBefore().
 *
 * OC3's version worked by intercepting the raw Twig template string
 * before render (a VQMod-era mechanism with no OC4 equivalent). OC4's
 * real per-request hook point is view/sale/order_info/after, which
 * only gives access to the already-rendered HTML output - so this
 * injects the panel's HTML directly into $output via string
 * replacement, rather than trying to fork/override the native
 * sale/order_info.twig template.
 *
 * @package Opencart\Admin\Controller\Extension\Paynl
 */
class Paynl extends \Opencart\System\Engine\Controller {
	/**
	 * Paynl Order Info Before
	 *
	 * Registered on view/sale/order_info/after (installed by
	 * AdminController::install()).
	 *
	 * @param string               $route
	 * @param array<string, mixed> $data
	 * @param string               $output
	 *
	 * @return void
	 */
	public function paynlOrderInfoBefore(string &$route, array &$data, string &$output): void {
		if (empty($data['order_id'])) {
			return;
		}

		$order_id = (int)$data['order_id'];

		$this->load->model('sale/order');
		$order_info = $this->model_sale_order->getOrder($order_id);

		if (empty($order_info) || empty($order_info['payment_method']['code']) || strpos($order_info['payment_method']['code'], 'paynl') === false) {
			return;
		}

		$payment_code = $order_info['payment_method']['code'];

		$this->load->model('extension/paynl/payment/' . $payment_code);
		$model_key = 'model_extension_paynl_payment_' . $payment_code;
		/** @var \Opencart\System\Engine\Proxy $method_model */
		$method_model = $this->registry->get($model_key);

		$transaction = $method_model->getTransactionFromOrderId($order_id);

		if (empty($transaction) || empty($transaction['id'])) {
			return;
		}

		$transaction_id = $transaction['id'];

		$pay_config = new Config($this->registry);

		try {
			$order_status_request = new OrderStatusRequest($transaction_id);
			$order_status_request->setConfig($pay_config->getConfig(true));
			$pay_transaction = $order_status_request->start();
		} catch (\Exception $e) {
			$pay_transaction = null;
		}

		$already_refunded = 0.0;

		if (empty($pay_transaction) || ($pay_transaction instanceof \PayNL\Sdk\Model\Pay\PayOrder && ($pay_transaction->isPaid() || $pay_transaction->isAuthorized()))) {
			$transaction_status_request = new TransactionStatusRequest($transaction_id);
			$transaction_status_request->setConfig($pay_config->getConfig(true));
			$pay_gms_order = $transaction_status_request->start();

			if ($pay_gms_order->isRefunded() || empty($pay_transaction)) {
				$pay_transaction = $pay_gms_order;
			}

			$already_refunded = (float)$pay_gms_order->getAmountRefunded();
		}

		if (empty($pay_transaction)) {
			return;
		}

		$panel = $this->buildPanel($pay_transaction, $order_info, $transaction_id, $already_refunded);

		if ($panel !== '') {
			$output = str_replace('</body>', $panel . '</body>', $output);
		}
	}

	/**
	 * Build Panel
	 *
	 * @param object                $pay_transaction Either a PayOrder or a TransactionStatusResponse
	 * @param array<string, mixed>  $order_info
	 * @param string                $transaction_id
	 * @param float                 $already_refunded
	 *
	 * @return string
	 */
	private function buildPanel($pay_transaction, array $order_info, string $transaction_id, float $already_refunded): string {
		$user_token = $this->session->data['user_token'];
		$base_url = $this->url->link('extension/paynl/payment/' . $order_info['payment_code'], 'user_token=' . $user_token . '&transaction_id=' . $transaction_id);

		$cart_amount = number_format((float)$order_info['total'], 2, '.', '');
		$currency = method_exists($pay_transaction, 'getCurrency') ? $pay_transaction->getCurrency() : $order_info['currency_code'];

		// Partial-refund detection differs by which SDK response type this
		// is (PayOrder has isRefundedPartial(), TransactionStatusResponse
		// has isPartiallyRefunded() instead) - check defensively rather
		// than assume one name works for both, since $pay_transaction can
		// legitimately be either type depending on the branch above.
		$is_refunded_partial = false;

		if (method_exists($pay_transaction, 'isRefundedPartial')) {
			$is_refunded_partial = $pay_transaction->isRefundedPartial();
		} elseif (method_exists($pay_transaction, 'isPartiallyRefunded')) {
			$is_refunded_partial = $pay_transaction->isPartiallyRefunded();
		}

		$show_refund = $pay_transaction->isPaid() || $is_refunded_partial;
		$status_code = $pay_transaction->getStatusCode();
		$show_capture = $pay_transaction->isAuthorized() || $status_code == 97;

		if ($show_refund) {
			$amount = (float)$pay_transaction->getAmount();

			if ($currency == 'EUR') {
				$amount -= $already_refunded;
			}

			$amount = number_format(max($amount, 0), 2, '.', '');

			$body = '';

			if ($already_refunded > 0) {
				$body .= '<p class="mb-2 text-body-secondary">' . sprintf('Already refunded: %s %s', $currency, number_format($already_refunded, 2, '.', '')) . '</p>';
			}

			$body .= $this->actionForm($base_url . '&action=refund', 'Refund', 'Amount to refund', $amount, 'btn-warning');

			return $this->wrapPanel('Pay. — Refund', $body, $order_info['currency_code'], $cart_amount);
		}

		if ($show_capture) {
			$captured = 0.0;

			if (method_exists($pay_transaction, 'getCapturedAmount')) {
				$captured = (float)$pay_transaction->getCapturedAmount()->getValue() / 100;
			}

			$remaining = number_format(max((float)$pay_transaction->getAmount() - $captured, 0), 2, '.', '');

			$body = '';

			if ($captured > 0) {
				$body .= '<p class="mb-2 text-body-secondary">' . sprintf('Already captured: %s', number_format($captured, 2, '.', '')) . '</p>';
			}

			$body .= $this->actionForm($base_url . '&action=capture', 'Capture', 'Amount to capture', $remaining, 'btn-primary');

			if ($captured == 0) {
				$body .= $this->actionForm($base_url . '&action=void', 'Void', 'Amount to void', $remaining, 'btn-danger');
			}

			return $this->wrapPanel('Pay. — Capture / Void', $body, $order_info['currency_code'], $cart_amount);
		}

		return '';
	}

	/**
	 * Action Form
	 *
	 * @param string $url
	 * @param string $label
	 * @param string $description
	 * @param string $amount
	 * @param string $btnClass
	 *
	 * @return string
	 */
	private function actionForm(string $url, string $label, string $description, string $amount, string $btnClass): string {
		$formId = 'paynl-action-' . strtolower($label) . '-' . substr(md5($url), 0, 6);

		return '
			<form class="row g-2 align-items-end mb-3" id="' . $formId . '" onsubmit="return false;">
				<div class="col-auto">
					<label class="form-label small mb-0">' . htmlspecialchars($description) . '</label>
					<input type="number" step="0.01" min="0" class="form-control form-control-sm" value="' . htmlspecialchars($amount) . '" style="width: 120px;">
				</div>
				<div class="col-auto">
					<button type="button" class="btn btn-sm ' . htmlspecialchars($btnClass) . '" onclick="paynlAction(this, ' . htmlspecialchars(json_encode($url), ENT_QUOTES) . ')">' . htmlspecialchars($label) . '</button>
				</div>
				<div class="col-auto small text-body-secondary paynl-result"></div>
			</form>';
	}

	/**
	 * Wrap Panel
	 *
	 * @param string $title
	 * @param string $body
	 * @param string $currency
	 * @param string $orderTotal
	 *
	 * @return string
	 */
	private function wrapPanel(string $title, string $body, string $currency, string $orderTotal): string {
		return '
			<div class="container-fluid mt-3">
				<div class="card">
					<div class="card-header"><i class="fa-solid fa-credit-card"></i> ' . htmlspecialchars($title) . '<span class="text-body-secondary"> — order total ' . htmlspecialchars($currency) . ' ' . htmlspecialchars($orderTotal) . '</span></div>
					<div class="card-body">' . $body . '</div>
				</div>
			</div>
			<script type="text/javascript">
				function paynlAction(button, url) {
					var form = button.closest("form");
					var amountInput = form.querySelector("input[type=number]");
					var result = form.querySelector(".paynl-result");
					var fullUrl = url + "&amount=" + encodeURIComponent(amountInput.value);

					button.disabled = true;
					result.textContent = "...";

					fetch(fullUrl, {headers: {"X-Requested-With": "XMLHttpRequest"}})
						.then(function (response) { return response.json(); })
						.then(function (json) {
							result.textContent = json.success || json.error || "";
							button.disabled = false;
						})
						.catch(function () {
							result.textContent = "Request failed.";
							button.disabled = false;
						});
				}
			</script>';
	}
}

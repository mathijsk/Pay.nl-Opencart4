<?php
namespace Opencart\System\Library\Extension\Paynl;
/**
 * Class Helper
 *
 * Shared Pay. (pay.nl) static helpers, ported from OC3's Pay_Helper.
 * Pure logic, no OpenCart dependency beyond the Model class's own
 * STATUS_* constants.
 *
 * @package Opencart\System\Extension\Paynl\Library
 */
class Helper {
	/**
	 * Get State Text
	 *
	 * Maps a Pay.nl numeric state ID to a human-readable state name.
	 * Generally only -90 (CANCEL), 20 (PENDING) and 100 (PAID) are seen
	 * in practice, but the full mapping is kept for completeness/logging.
	 *
	 * @param int $state_id
	 *
	 * @return string
	 */
	public static function getStateText(int $state_id): string {
		switch ($state_id) {
			case -70:
			case -71:
				return 'CHARGEBACK';
			case -51:
				return 'PAID CHECKAMOUNT';
			case -81:
				return 'REFUND';
			case -82:
				return 'PARTIAL REFUND';
			case 20:
			case 25:
			case 50:
				return 'PENDING';
			case 60:
				return 'OPEN';
			case 75:
			case 76:
				return 'CONFIRMED';
			case 80:
				return 'PARTIAL PAYMENT';
			case 100:
				return 'PAID';
			default:
				return $state_id < 0 ? 'CANCEL' : 'UNKNOWN';
		}
	}

	/**
	 * Filter Array Recursive
	 *
	 * Removes all empty nodes from an array, recursively.
	 *
	 * @param array<int|string, mixed> $array
	 *
	 * @return array<int|string, mixed>
	 */
	public static function filterArrayRecursive(array $array): array {
		$new_array = [];

		foreach ($array as $key => $value) {
			if (is_array($value)) {
				$value = self::filterArrayRecursive($value);
			}

			if (!empty($value)) {
				$new_array[$key] = $value;
			}
		}

		return $new_array;
	}

	/**
	 * Split Address
	 *
	 * Splits a combined "Street Name 123" address string into
	 * [street_name, street_number]. Falls back to the American
	 * "123 Street Name" notation if the Dutch-style split fails.
	 *
	 * @param string $address
	 *
	 * @return array<int, string>
	 */
	public static function splitAddress(string $address): array {
		$address = trim($address);

		$parts = preg_split('/(\s+)([0-9]+)/', $address, 2, PREG_SPLIT_DELIM_CAPTURE);
		$street_name = trim(array_shift($parts));
		$street_number = trim(implode('', $parts));

		if (empty($street_name) || empty($street_number)) { // American address notation
			$parts = preg_split('/([a-zA-Z]{2,})/', $address, 2, PREG_SPLIT_DELIM_CAPTURE);
			$street_number = trim(array_shift($parts));
			$street_name = implode('', $parts);
		}

		// If street number > 10 chars the API will throw an error, so omit the address entirely.
		if (strlen($street_number) > 10) {
			return ['', ''];
		}

		return [$street_name, $street_number];
	}

	/**
	 * Get Status
	 *
	 * Maps a raw Pay.nl status code to one of this extension's own
	 * internal STATUS_* constants (see Model class).
	 *
	 * @param int $pay_state
	 *
	 * @return string
	 */
	public static function getStatus(int $pay_state): string {
		$status = Model::STATUS_PENDING;

		if ($pay_state == 100) {
			$status = Model::STATUS_COMPLETE;
		} elseif ($pay_state == -81) {
			$status = Model::STATUS_REFUNDED;
		} elseif ($pay_state < 0) {
			$status = Model::STATUS_CANCELED;
		}

		return $status;
	}

	/**
	 * Get Order Status Id
	 *
	 * Maps a raw Pay.nl status code to the OpenCart order_status_id
	 * configured by the admin for a given payment method.
	 *
	 * @param int                       $pay_state
	 * @param array<string, mixed>      $settings
	 * @param string                    $name
	 *
	 * @return int
	 */
	public static function getOrderStatusId(int $pay_state, array $settings, string $name): int {
		$status_pending = (int)($settings['payment_' . $name . '_pending_status'] ?? 0);
		$status_complete = (int)($settings['payment_' . $name . '_completed_status'] ?? 0);
		$status_canceled = (int)($settings['payment_' . $name . '_canceled_status'] ?? 0);
		$status_refunded = (int)($settings['payment_' . $name . '_refunded_status'] ?? 0);

		$order_status_id = $status_pending;

		if ($pay_state == 100) {
			$order_status_id = $status_complete;
		} elseif ($pay_state == -81) {
			$order_status_id = empty($status_refunded) ? 11 : $status_refunded;
		} elseif ($pay_state < 0) {
			$order_status_id = $status_canceled;
		}

		return $order_status_id;
	}

	/**
	 * Calculate Tax Class
	 *
	 * Determine the tax class to send to Pay.nl for a product/order line.
	 *
	 * @param float $amount_incl_tax
	 * @param float $tax_amount
	 *
	 * @return string The tax class: N (none), L (low/6%) or H (high/21%)
	 */
	public static function calculateTaxClass(float $amount_incl_tax, float $tax_amount): string {
		$tax_classes = [
			0  => 'N',
			6  => 'L',
			21 => 'H'
		];

		if ($tax_amount == 0 || $amount_incl_tax == 0) {
			return $tax_classes[0];
		}

		$amount_excl_tax = $amount_incl_tax - $tax_amount;
		$tax_rate = ($tax_amount / $amount_excl_tax) * 100;
		$nearest_tax_rate = self::nearest($tax_rate, array_keys($tax_classes));

		return $tax_classes[$nearest_tax_rate];
	}

	/**
	 * Nearest
	 *
	 * @param float       $number
	 * @param array<int>  $numbers
	 *
	 * @return int
	 */
	private static function nearest(float $number, array $numbers): int {
		$number = (int)$number;
		$distances = [];

		foreach ($numbers as $candidate) {
			$distances[abs($number - $candidate)] = $candidate;
		}

		ksort($distances);
		$distances = array_values($distances);

		return $distances[0];
	}
}

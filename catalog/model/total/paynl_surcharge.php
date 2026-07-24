<?php
namespace Opencart\Catalog\Model\Extension\Paynl\Total;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\Model;

/**
 * Class PaynlSurcharge
 *
 * Adds a payment method surcharge line to the order totals, based on
 * whichever payment method is currently selected in the session. Not
 * part of the original OC3 plugin - added at the project owner's
 * request, specifically scoped to Dutch/EU ACM surcharge rules: some
 * payment methods (iDEAL, standard consumer cards, SEPA, etc.) may
 * never carry a surcharge at all, and where a surcharge IS legally
 * allowed, it may not exceed the actual transaction cost the merchant
 * is charged.
 *
 * This class deliberately does NOT decide "is a surcharge allowed
 * here" purely by checking whether a value was configured - it
 * independently re-checks Model::SURCHARGE_FORBIDDEN itself, as a
 * second, defense-in-depth safety net on top of the admin settings
 * screen already refusing to show/save fields for a forbidden method.
 * A merchant should never be able to end up illegally surcharging one
 * of these, even via a direct database edit or a future bug elsewhere.
 *
 * VAT handling (added after the project owner flagged a real
 * Belastingdienst/ACM interaction that the first version of this
 * class got wrong): a payment surcharge counts as "bijkomende kosten"
 * under Dutch tax rules and must carry the SAME VAT rate(s) as the
 * order's own products (split proportionally across rates for a cart
 * with mixed VAT rates) - it does NOT inherit Pay.'s own VAT treatment
 * (their transaction fee to the merchant is VAT-exempt, being a
 * financial service). This creates a specific trap for the ACM's
 * "charge no more than your actual cost" rule: since what Pay. charges
 * the merchant is €0 VAT, but the merchant must charge the consumer
 * VAT on top of the surcharge, the FIXED/PERCENTAGE values configured
 * on each payment method's own settings screen are treated as the
 * VAT-INCLUSIVE ceiling (i.e. exactly matching Pay.'s real charge to
 * the merchant, all-in) - this class backs the VAT portion back out of
 * that ceiling to get the correct pre-tax line value, then folds that
 * VAT into the cart's own existing tax breakdown (by rate, weighted by
 * how much of the cart's value sits at each rate) rather than adding
 * a separate, undocumented VAT amount nobody asked this class to
 * charge. This only works correctly if this extension's own
 * sort_order runs BEFORE the native "tax" total extension - tax.php
 * only sums whatever is already in $taxes by the time it runs, it
 * does not recompute anything - so the admin settings screen defaults
 * this to sort_order 4 (between shipping at 3 and tax at 5), not 8
 * (after tax) as an earlier version of this class incorrectly assumed
 * was fine.
 *
 * Can be called from $this->load->model('extension/paynl/total/paynl_surcharge')
 *
 * @package Opencart\Catalog\Model\Extension\Paynl\Total
 */
class PaynlSurcharge extends \Opencart\System\Engine\Model {
	/**
	 * Get Total
	 *
	 * @param array<int, array<string, mixed>> $totals
	 * @param array<int, float>                $taxes
	 * @param float                             $total
	 *
	 * @return void
	 */
	public function getTotal(array &$totals, array &$taxes, float &$total): void {
		if (!$this->config->get('total_paynl_surcharge_status')) {
			return;
		}

		$payment_method = $this->session->data['payment_method']['code'] ?? '';

		if ($payment_method === '') {
			return;
		}

		// Payment method codes are stored in the dotted
		// group.suboption format (e.g. paynl_billink.paynl_billink,
		// matching native OC4's own cod.cod convention - see
		// Model::getMethod()'s own docblock for the full story) - the
		// group code (before the dot) is what our settings are keyed
		// on.
		[$payment_code] = explode('.', $payment_method);

		if (in_array($payment_code, Model::SURCHARGE_FORBIDDEN, true)) {
			return;
		}

		$fixed = (float)$this->config->get('payment_' . $payment_code . '_surcharge_fixed');
		$percentage = (float)$this->config->get('payment_' . $payment_code . '_surcharge_percentage');

		if ($fixed <= 0 && $percentage <= 0) {
			// Nothing configured for this method - no line shown at
			// all, rather than a pointless "Payment surcharge: €0.00"
			// row on every order using one of the ~90 methods that
			// simply never had a surcharge value set.
			return;
		}

		// This extension's own sort_order runs before tax's (4 vs 5
		// by default), so $total does not yet include the cart's own
		// product VAT at this point - array_sum($taxes) is added back
		// in explicitly here so the percentage is still calculated
		// against the full amount the customer actually pays (Pay.'s
		// own transaction fee is a percentage of the full amount
		// moved, VAT included, not just the goods value before tax).
		$base = $total + array_sum($taxes);

		// This is the VAT-INCLUSIVE ceiling - see this class's own
		// docblock for why it must be treated as inclusive rather
		// than adding VAT on top of it.
		$gross = $fixed + ($base * $percentage / 100);

		if ($gross <= 0) {
			return;
		}

		// Work out how the cart's own VAT is currently split across
		// tax rates, by product value at each rate (not by existing
		// tax amount - using value directly is simpler and identical
		// in effect for percentage-type rates, which VAT always is in
		// practice). Fixed-amount tax rate types are deliberately
		// excluded from this proportional split - scaling a flat fee
		// proportionally the same way as a percentage rate doesn't
		// make sense, and VAT in the Netherlands is never a fixed-
		// amount rate type in oc_tax_rate anyway.
		$value_by_rate = [];
		$rate_by_id = [];

		foreach ($this->cart->getProducts() as $product) {
			if (empty($product['tax_class_id'])) {
				continue;
			}

			foreach ($this->tax->getRates($product['total'], (int)$product['tax_class_id']) as $tax_rate_id => $tax_rate) {
				if ($tax_rate['type'] !== 'P') {
					continue;
				}

				$value_by_rate[$tax_rate_id] = ($value_by_rate[$tax_rate_id] ?? 0) + $product['total'];
				$rate_by_id[$tax_rate_id] = (float)$tax_rate['rate'];
			}
		}

		$taxable_value = array_sum($value_by_rate);

		if ($taxable_value <= 0) {
			// No taxable products in the cart at all (or none with a
			// percentage-type VAT rate) - the main product carries no
			// VAT, so neither does the surcharge (VAT on "bijkomende
			// kosten" follows the main product's own rate - 0% follows
			// 0%). Show the full configured value as-is, no VAT split.
			$net = $gross;
		} else {
			// Weighted average VAT rate across the cart, used to back
			// the VAT portion out of the VAT-inclusive ceiling.
			$blended_rate = 0.0;

			foreach ($value_by_rate as $tax_rate_id => $value) {
				$blended_rate += ($value / $taxable_value) * $rate_by_id[$tax_rate_id];
			}

			$net = $gross / (1 + ($blended_rate / 100));
			$vat_total = $gross - $net;

			// Split that VAT total across the same rates, in the same
			// proportions as the cart's own products, and fold it into
			// the existing $taxes array - tax.php (sort_order 5, after
			// this extension's default of 4) will pick this up
			// automatically as part of its own normal summing, ending
			// up as a single correctly-sized "BTW (21%)"-style line
			// that already includes VAT on both the products and this
			// surcharge, rather than a separate, confusing VAT line
			// nobody asked for.
			foreach ($value_by_rate as $tax_rate_id => $value) {
				$taxes[$tax_rate_id] = ($taxes[$tax_rate_id] ?? 0) + ($vat_total * ($value / $taxable_value));
			}
		}

		if ($net <= 0) {
			return;
		}

		$this->load->language('extension/paynl/total/paynl_surcharge');

		$totals[] = [
			'extension'  => 'paynl',
			'code'       => 'paynl_surcharge',
			'title'      => $this->language->get('text_title'),
			'value'      => $net,
			'sort_order' => (int)$this->config->get('total_paynl_surcharge_sort_order')
		];

		$total += $net;
	}
}

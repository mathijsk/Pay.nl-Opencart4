<?php
namespace Opencart\Admin\Model\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\Model;

/**
 * Class PaynlSaunaandwellness
 *
 * Sauna & Wellness cadeaukaart - admin-side model. No method-specific logic needed; all
 * real behaviour lives in the shared Model class.
 *
 * @package Opencart\Admin\Model\Extension\Paynl\Payment
 */
class PaynlSaunaandwellness extends Model {
	protected int $paymentOptionId = 4269;
	protected string $paymentMethodName = 'paynl_saunaandwellness';
}

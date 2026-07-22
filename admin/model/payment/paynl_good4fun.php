<?php
namespace Opencart\Admin\Model\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\Model;

/**
 * Class PaynlGood4fun
 *
 * Good4fun - admin-side model. No method-specific logic needed; all
 * real behaviour lives in the shared Model class.
 *
 * @package Opencart\Admin\Model\Extension\Paynl\Payment
 */
class PaynlGood4fun extends Model {
	protected int $paymentOptionId = 2628;
	protected string $paymentMethodName = 'paynl_good4fun';
}

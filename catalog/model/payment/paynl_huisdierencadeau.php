<?php
namespace Opencart\Catalog\Model\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\Model;

/**
 * Class PaynlHuisdierencadeau
 *
 * Huisdieren cadeaukaart - catalog-side model. No method-specific logic needed;
 * all real behaviour lives in the shared Model class. This is also the
 * model whose getMethods() OpenCart's checkout calls to decide whether
 * to offer Huisdieren cadeaukaart at all for a given order/address.
 *
 * @package Opencart\Catalog\Model\Extension\Paynl\Payment
 */
class PaynlHuisdierencadeau extends Model {
	protected int $paymentOptionId = 4158;
	protected string $paymentMethodName = 'paynl_huisdierencadeau';
}

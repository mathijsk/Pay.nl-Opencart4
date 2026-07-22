<?php
namespace Opencart\Catalog\Model\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\Model;

/**
 * Class PaynlKlarnakp
 *
 * Klarna (Achteraf betalen) - catalog-side model. No method-specific logic needed;
 * all real behaviour lives in the shared Model class. This is also the
 * model whose getMethods() OpenCart's checkout calls to decide whether
 * to offer Klarna (Achteraf betalen) at all for a given order/address.
 *
 * @package Opencart\Catalog\Model\Extension\Paynl\Payment
 */
class PaynlKlarnakp extends Model {
	protected int $paymentOptionId = 1717;
	protected string $paymentMethodName = 'paynl_klarnakp';
}

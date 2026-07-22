<?php
namespace Opencart\Catalog\Controller\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\PaymentController;

/**
 * Class PaynlBloemencadeau
 *
 * Bloemen Cadeaukaart - catalog (checkout) controller. Thin subclass of the
 * shared PaymentController: all real logic lives there, this just
 * identifies which Pay.nl payment option/method this page is for.
 *
 * @package Opencart\Catalog\Controller\Extension\Paynl\Payment
 */
class PaynlBloemencadeau extends PaymentController {
	protected int $paymentOptionId = 2607;
	protected string $paymentMethodName = 'paynl_bloemencadeau';
}

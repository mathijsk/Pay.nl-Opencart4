<?php
namespace Opencart\Catalog\Controller\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\PaymentController;

/**
 * Class PaynlSofortbankinghr
 *
 * Sofortbanking High Risk - catalog (checkout) controller. Thin subclass of the
 * shared PaymentController: all real logic lives there, this just
 * identifies which Pay.nl payment option/method this page is for.
 *
 * @package Opencart\Catalog\Controller\Extension\Paynl\Payment
 */
class PaynlSofortbankinghr extends PaymentController {
	protected int $paymentOptionId = 595;
	protected string $paymentMethodName = 'paynl_sofortbankinghr';
}

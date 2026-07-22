<?php
namespace Opencart\Admin\Controller\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\AdminController;

/**
 * Class PaynlSofort
 *
 * Sofort (by Klarna) - admin settings controller. Thin subclass of the shared
 * AdminController: all real logic lives there, this just identifies
 * which Pay.nl payment option/method this page is for. Generated from
 * the OC3 plugin's own $_paymentOptionId/$_paymentMethodName/$_defaultLabel/
 * $_fastCheckout values, not guessed.
 *
 * @package Opencart\Admin\Controller\Extension\Paynl\Payment
 */
class PaynlSofort extends AdminController {
	protected int $paymentOptionId = 4761;
	protected string $paymentMethodName = 'paynl_sofort';
	protected string $defaultLabel = 'Sofort (by Klarna)';
}

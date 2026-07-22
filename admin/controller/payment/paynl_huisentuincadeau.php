<?php
namespace Opencart\Admin\Controller\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\AdminController;

/**
 * Class PaynlHuisentuincadeau
 *
 * Huis & Tuin Cadeaukaart - admin settings controller. Thin subclass of the shared
 * AdminController: all real logic lives there, this just identifies
 * which Pay.nl payment option/method this page is for. Generated from
 * the OC3 plugin's own $_paymentOptionId/$_paymentMethodName/$_defaultLabel/
 * $_fastCheckout values, not guessed.
 *
 * @package Opencart\Admin\Controller\Extension\Paynl\Payment
 */
class PaynlHuisentuincadeau extends AdminController {
	protected int $paymentOptionId = 2283;
	protected string $paymentMethodName = 'paynl_huisentuincadeau';
	protected string $defaultLabel = 'Huis & Tuin Cadeaukaart';
}

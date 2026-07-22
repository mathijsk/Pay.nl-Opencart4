<?php
namespace Opencart\Admin\Controller\Extension\Paynl\Payment;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

use Opencart\System\Library\Extension\Paynl\AdminController;

/**
 * Class PaynlIdeal
 *
 * iDEAL - admin settings controller. Thin subclass of the shared
 * AdminController: all real logic lives there, this just identifies
 * which Pay.nl payment option/method this page is for.
 *
 * @package Opencart\Admin\Controller\Extension\Paynl\Payment
 */
class PaynlIdeal extends AdminController {
	protected int $paymentOptionId = 10;
	protected string $paymentMethodName = 'paynl_ideal';
	protected string $defaultLabel = 'iDEAL';
	protected bool $fastCheckout = true;
}

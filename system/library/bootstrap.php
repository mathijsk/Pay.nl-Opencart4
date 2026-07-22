<?php
/**
 * Pay. shared library bootstrap.
 *
 * Required directly (not autoloaded) by every concrete paynl_<method>
 * admin/catalog controller and model file. This sidesteps a real
 * inconsistency in OC4 itself: the one-time extension/payment.php
 * install() action registers our namespace under one path
 * (Opencart\System\Extension\Paynl -> system/), while the PERSISTENT
 * per-request registration (beheer/controller/startup/extension.php,
 * catalog/controller/startup/extension.php) uses a different one
 * (Opencart\System\Library\Extension\Paynl -> system/library/) - and
 * the install() action itself runs BEFORE that extension is in the
 * persistent list, so neither registration alone covers every request
 * that needs these classes. Requiring them directly - the same
 * approach the original OC3 plugin's own Pay_Autoload.php used - works
 * identically in every context regardless of OC4's own registration
 * timing, and needs no dependency on OC4's autoloader for our own code
 * at all.
 */

require_once __DIR__ . '/pay_exception.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/model.php';
require_once __DIR__ . '/transaction.php';
require_once __DIR__ . '/fast_checkout_api.php';
require_once __DIR__ . '/admin_controller.php';
require_once __DIR__ . '/payment_controller.php';

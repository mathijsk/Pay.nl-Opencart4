<?php
namespace Opencart\System\Library\Extension\Paynl;
/**
 * Class PayException
 *
 * Plugin-level exception, distinct from the Pay.nl SDK's own
 * \PayNL\Sdk\Exception\PayException (API-level errors). Ported from
 * OC3's Pay_Exception.
 *
 * @package Opencart\System\Extension\Paynl\Library
 */
class PayException extends \Exception {
}

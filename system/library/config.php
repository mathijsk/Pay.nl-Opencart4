<?php
namespace Opencart\System\Library\Extension\Paynl;
/**
 * Class Config
 *
 * Shared Pay. (pay.nl) configuration reader, used by every paynl_* payment
 * method extension plus the general settings screen. Ported from the
 * OpenCart 3 plugin's Pay_Controller_Config — same config key names
 * (payment_paynl_general_*), since OC4's flat oc_setting store works the
 * same way OC3's did.
 *
 * @package Opencart\System\Extension\Paynl\Library
 */
class Config {
	/**
	 * @var \Opencart\System\Engine\Registry
	 */
	protected \Opencart\System\Engine\Registry $registry;

	/**
	 * Constructor
	 *
	 * @param \Opencart\System\Engine\Registry $registry
	 */
	public function __construct(\Opencart\System\Engine\Registry $registry) {
		$this->registry = $registry;
	}

	/**
	 * Is Test Mode
	 *
	 * True if the general test-mode setting is on, or the current visitor's
	 * IP is in the configured test-IP allowlist.
	 *
	 * @return bool
	 */
	public function isTestMode(): bool {
		/** @var \Opencart\System\Library\Request $request */
		$request = $this->registry->get('request');
		/** @var \Opencart\System\Engine\Config $config */
		$config = $this->registry->get('config');

		$ip = $request->server['REMOTE_ADDR'] ?? '';
		$ip_config = $config->get('payment_paynl_general_test_ip');

		if (!empty($ip_config)) {
			$allowed_ips = explode(',', $ip_config);

			if (in_array($ip, $allowed_ips, true) && filter_var($ip, FILTER_VALIDATE_IP) && strlen($ip) > 0 && count($allowed_ips) > 0) {
				return true;
			}
		}

		return (bool)$config->get('payment_paynl_general_testmode');
	}

	/**
	 * Get Config
	 *
	 * Builds a Pay.nl SDK Config object from the stored admin settings.
	 *
	 * @param bool        $use_core
	 * @param string|null $token_code
	 * @param string|null $api_token
	 *
	 * @return \PayNL\Sdk\Config\Config
	 */
	public function getConfig(bool $use_core = false, ?string $token_code = null, ?string $api_token = null): \PayNL\Sdk\Config\Config {
		/** @var \Opencart\System\Engine\Config $config */
		$config = $this->registry->get('config');

		$sdk_config = new \PayNL\Sdk\Config\Config();
		$sdk_config->setUsername($token_code ?? $this->getTokenCode());
		$sdk_config->setPassword($api_token ?? $this->getApiToken());

		$gateway = $config->get('payment_paynl_general_gateway');

		if (!empty($gateway) && $use_core === true) {
			$sdk_config->setCore($gateway);
		}

		return $sdk_config;
	}

	/**
	 * Get Api Token
	 *
	 * @return string
	 */
	public function getApiToken(): string {
		return (string)$this->registry->get('config')->get('payment_paynl_general_apitoken');
	}

	/**
	 * Get Token Code
	 *
	 * @return string
	 */
	public function getTokenCode(): string {
		return (string)$this->registry->get('config')->get('payment_paynl_general_tokencode');
	}

	/**
	 * Get Service Id
	 *
	 * @return string
	 */
	public function getServiceId(): string {
		return (string)$this->registry->get('config')->get('payment_paynl_general_serviceid');
	}

	/**
	 * Get Custom Exchange Url
	 *
	 * @return string
	 */
	public function getCustomExchangeUrl(): string {
		return trim((string)$this->registry->get('config')->get('payment_paynl_general_custom_exchange_url'));
	}

	/**
	 * Get Object
	 *
	 * Identifies this integration to the Pay.nl API (shop platform + plugin
	 * + PHP version), used for support/diagnostics on Pay.nl's side.
	 *
	 * @return string
	 */
	public function getObject(): string {
		return 'opencart 4 | ' . (defined('VERSION') ? VERSION : '-') . ' | ' . substr(phpversion(), 0, 3);
	}
}

<?php
namespace Opencart\Admin\Controller\Extension\Paynl\Total;

require_once __DIR__ . '/../../../system/library/bootstrap.php';

/**
 * Class PaynlSurcharge
 *
 * Settings screen for the payment method surcharge total extension -
 * just a status toggle and sort order (matching native OC4's own
 * simple total extensions, e.g. extension/opencart/admin/controller/total/handling.php).
 * The actual per-method fixed amount/percentage values live on each
 * payment method's own settings page instead (AdminController), not
 * here - this extension only decides whether the surcharge mechanism
 * as a whole is switched on, and where in the totals list it appears.
 *
 * @package Opencart\Admin\Controller\Extension\Paynl\Total
 */
class PaynlSurcharge extends \Opencart\System\Engine\Controller {
	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/paynl/total/paynl_surcharge');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [
			[
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
			],
			[
				'text' => $this->language->get('text_extension'),
				'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=total')
			],
			[
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link('extension/paynl/total/paynl_surcharge', 'user_token=' . $this->session->data['user_token'])
			]
		];

		$data['save'] = $this->url->link('extension/paynl/total/paynl_surcharge.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=total');

		$data['total_paynl_surcharge_status'] = $this->config->get('total_paynl_surcharge_status');
		$data['total_paynl_surcharge_sort_order'] = $this->config->get('total_paynl_surcharge_sort_order');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/paynl/total/paynl_surcharge', $data));
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$this->load->language('extension/paynl/total/paynl_surcharge');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/paynl/total/paynl_surcharge')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('total_paynl_surcharge', $this->request->post);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Install
	 *
	 * Called automatically when this extension is installed via
	 * Marketplace > Extensions > Order Totals. Defaults it to enabled
	 * with sort_order 4 - critically, this must run BEFORE the native
	 * "tax" total extension (sort_order 5 by default), not after: this
	 * extension folds the surcharge's own VAT into the cart's existing
	 * $taxes array so tax.php's own summing picks it up automatically,
	 * and tax.php does not recompute anything from $total, it only
	 * sums whatever is already in $taxes by the time it runs. See
	 * catalog/model/total/paynl_surcharge.php's own docblock for the
	 * full reasoning.
	 *
	 * @return void
	 */
	public function install(): void {
		if ($this->config->get('total_paynl_surcharge_status') === null) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('total_paynl_surcharge', [
				'total_paynl_surcharge_status'     => 1,
				'total_paynl_surcharge_sort_order' => 4
			]);
		}
	}
}

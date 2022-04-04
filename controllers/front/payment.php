<?php
if (!defined('_PS_VERSION_')) exit;

/**
 * @since 1.5.0
 */
class PayginePaymentModuleFrontController extends ModuleFrontController {
	public $ssl = true;
	public $display_column_left = false;

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent() {
		parent::initContent();

		$cart = $this->context->cart;

		if (!$this->module->checkCurrency($cart))
			Tools::redirect('index.php?controller=order');

		$this->context->smarty->assign(array(
			'nbProducts' => $cart->nbProducts(),
			'this_path' => $this->module->getPathUri(),
			'this_path_bw' => $this->module->getPathUri(),
			'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->module->name . '/'
		));

		$this->setTemplate('payment_execution.tpl');
	}

}

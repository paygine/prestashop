<?php
if (!defined('_PS_VERSION_')) exit;

class Paygine extends PaymentModule {
	protected $_html = '';
	protected $_postErrors = array();

	public $sector_id;
	public $password;
	public $test_mode = 0;
	public $paygine_tax = 6;

	public function __construct() {
		$this->name = 'paygine';
		$this->tab = 'payments_gateways';
		$this->version = '1.0.0';
		$this->author = $this->l('Dennis Prochko');
		$this->controllers = array('payment', 'validation');
		$this->is_eu_compatible = 1;

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		$config = Configuration::getMultiple(array('PAYGINE_SECTOR_ID', 'PAYGINE_PASSWORD', 'PAYGINE_TEST_MODE', 'PAYGINE_TAX'));
		if (!empty($config['PAYGINE_SECTOR_ID']))
			$this->sector_id = $config['PAYGINE_SECTOR_ID'];
		if (!empty($config['PAYGINE_PASSWORD']))
			$this->password = $config['PAYGINE_PASSWORD'];
		if (!empty($config['PAYGINE_TEST_MODE']))
			$this->test_mode = $config['PAYGINE_TEST_MODE'];
		if (!empty($config['PAYGINE_TAX']))
			$this->paygine_tax = $config['PAYGINE_TAX'];

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('Paygine');
		$this->description = $this->l('Accept payments for your products via credit and debit cards.');
		$this->confirmUninstall = $this->l('Are you sure about uninstall this module?');
		if (!isset($this->sector_id) || !isset($this->password) || !isset($this->test_mode))
			$this->warning = $this->l('Paygine account details must be configured before using this module.');
		if (!count(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('No currency has been set for this module.');
	}

	public function install() {
		if (!parent::install() || !$this->registerHook('payment') || ! $this->registerHook('displayPaymentEU') || !$this->registerHook('paymentReturn'))
			return false;

		// create new order status
		Db::getInstance()->insert('order_state', array(
			'invoice' => 0,
			'send_email' => 0,
			'module_name' => $this->name,
			'color' => 'RoyalBlue',
			'unremovable' => 1,
			'hidden' => 0,
			'logable' => 0,
			'delivery' => 0,
			'shipped' => 0,
			'paid' => 0,
			'deleted' => 0
		));
		$id_order_state = (int)Db::getInstance()->Insert_ID();
		$languages = Language::getLanguages(false);
		foreach ($languages as $language) {
			Db::getInstance()->insert('order_state_lang', array(
				'id_order_state' => $id_order_state,
				'id_lang' => $language['id_lang'],
				'name' => $this->l('Awaiting payment by card'),
				'template' => ''
			));
		}
		@copy(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logo.gif',
			_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'os' . DIRECTORY_SEPARATOR . $id_order_state . '.gif');
		Configuration::updateValue('PS_OS_PAYGINE', $id_order_state);

		return true;
	}

	public function uninstall() {
		if (!Configuration::deleteByName('PAYGINE_SECTOR_ID')
				|| !Configuration::deleteByName('PAYGINE_PASSWORD')
				|| !Configuration::deleteByName('PAYGINE_TEST_MODE')
				|| !parent::uninstall())
			return false;

		// remove our order status
		$id_order_state = (int)Db::getInstance()->getValue('SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE module_name = \'' . $this->name . '\'');
		@unlink(_PS_ROOT_DIR_.DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'os' . DIRECTORY_SEPARATOR . $id_order_state . '.gif');
		Db::getInstance()->delete('order_state_lang', 'id_order_state = ' . $id_order_state);
		Db::getInstance()->delete('order_state', 'module_name = \'' . $this->name . '\'');

		return true;
	}

	protected function _postValidation() {
		if (Tools::isSubmit('btnSubmit')) {
			if (!Tools::getValue('PAYGINE_SECTOR_ID'))
				$this->_postErrors[] = $this->l('Sector ID field is required.');
			elseif (!Tools::getValue('PAYGINE_PASSWORD'))
				$this->_postErrors[] = $this->l('Password field is required.');
		}
	}

	protected function _postProcess() {
		if (Tools::isSubmit('btnSubmit')) {
			Configuration::updateValue('PAYGINE_SECTOR_ID', Tools::getValue('PAYGINE_SECTOR_ID'));
			Configuration::updateValue('PAYGINE_PASSWORD', Tools::getValue('PAYGINE_PASSWORD'));
			Configuration::updateValue('PAYGINE_TEST_MODE', Tools::getValue('PAYGINE_TEST_MODE'));
			Configuration::updateValue('PAYGINE_TAX', Tools::getValue('PAYGINE_TAX'));
		}
		$this->_html .= $this->displayConfirmation($this->l('Settings updated'));
	}

	protected function _displayPaygine() {
		return $this->display(__FILE__, 'infos.tpl');
	}

	public function getContent() {
		if (Tools::isSubmit('btnSubmit')) {
			$this->_postValidation();
			if (!count($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->_html .= $this->displayError($err);
		}
		else
			$this->_html .= '<br />';

		$this->_html .= $this->_displayPaygine();
		$this->_html .= $this->renderForm();

		return $this->_html;
	}

	public function hookPayment($params) {
		if (!$this->active)
			return;
		if (!$this->checkCurrency($params['cart']))
			return;

		$this->smarty->assign(array(
			'this_path' => $this->_path,
			'this_path_bw' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/'
		));
		return $this->display(__FILE__, 'payment.tpl');
	}

	public function hookDisplayPaymentEU($params) {
		if (!$this->active)
			return;

		if (!$this->checkCurrency($params['cart']))
			return;

		$payment_options = array(
			'cta_text' => $this->l('Pay by credit or debit card'),
			'logo' => Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/paygine.png'),
			'action' => $this->context->link->getModuleLink($this->name, 'validation', array(), true)
		);

		return $payment_options;
	}

	public function hookPaymentReturn($params) {
		if (!$this->active)
			return;

		$state = $params['objOrder']->getCurrentState();
		if (in_array($state, array(Configuration::get('PS_OS_PAYGINE'), Configuration::get('PS_OS_OUTOFSTOCK'), Configuration::get('PS_OS_OUTOFSTOCK_UNPAID')))) {
			$this->smarty->assign(array(
				'total_to_pay' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
				'status' => 'ok',
				'id_order' => $params['objOrder']->id
			));
			if (isset($params['objOrder']->reference) && !empty($params['objOrder']->reference))
				$this->smarty->assign('reference', $params['objOrder']->reference);
		}
		else
			$this->smarty->assign('status', 'failed');
		return $this->display(__FILE__, 'payment_return.tpl');
	}

	public function checkCurrency($cart) {
		$currency_order = new Currency($cart->id_currency);
		$currencies_module = $this->getCurrency($cart->id_currency);

		if (is_array($currencies_module))
			foreach ($currencies_module as $currency_module)
				if ($currency_order->id == $currency_module['id_currency'])
					return true;
		return false;
	}

	public function renderForm() {
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Paygine Account Details'),
					'icon' => 'icon-gear'
				),
				'input' => array(
					array(
						'type' => 'text',
						'label' => $this->l('Sector ID'),
						'name' => 'PAYGINE_SECTOR_ID',
						'desc' => $this->l('Customer number as registered at Paygine'),
						'required' => true
					),
					array(
						'type' => 'text',
						'label' => $this->l('Password'),
						'name' => 'PAYGINE_PASSWORD',
						'desc' => $this->l('The password used for digital signature as obtained in Paygine client\'s cabinet'),
						'required' => true
					),
					array(
						'type' => 'radio',
						'label' => $this->l('Work Mode'),
						'name' => 'PAYGINE_TEST_MODE',
						'values' => array(
							array(
								'id'    => 'active_on',
								'label' => $this->l('Use test mode. In this mode the funds will not withdrawn from the card.'),
								'value' => 1
							),
							array(
								'id'    => 'active_off',
								'label' => $this->l('Use production mode.'),
								'value' => 0
							)
						),
						'required' => true
					),
					array(
						'type' => 'radio',
						'label' => $this->l('Tax'),
						'name' => 'PAYGINE_TAX',
						'values' => array(
							array(
								'id'    => 'ch1',
								'label' => $this->l('ставка НДС 20%'),
								'value' => 1
							),
							array(
								'id'    => 'ch2',
								'label' => $this->l('ставка НДС 10%'),
								'value' => 2
							),
							array(
								'id'    => 'ch3',
								'label' => $this->l('ставка НДС расч. 18/118'),
								'value' => 3
							),
							array(
								'id'    => 'ch4',
								'label' => $this->l('ставка НДС расч. 10/110'),
								'value' => 4
							),
							array(
								'id'    => 'ch5',
								'label' => $this->l('ставка НДС 0%'),
								'value' => 5
							),
							array(
								'id'    => 'ch6',
								'label' => $this->l('НДС не облагается'),
								'value' => 6
							)
						),
						'required' => true
					)
				),
				'submit' => array(
					'title' => $this->l('Save'),
				)
			)
		);

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = array();
		$helper->id = (int)Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);

		return $helper->generateForm(array($fields_form));
	}

	public function getConfigFieldsValues()	{
		return array(
			'PAYGINE_SECTOR_ID' => Tools::getValue('PAYGINE_SECTOR_ID', Configuration::get('PAYGINE_SECTOR_ID')),
			'PAYGINE_PASSWORD' => Tools::getValue('PAYGINE_PASSWORD', Configuration::get('PAYGINE_PASSWORD')),
			'PAYGINE_TEST_MODE' => Tools::getValue('PAYGINE_TEST_MODE', Configuration::get('PAYGINE_TEST_MODE')),
			'PAYGINE_TAX' => Tools::getValue('PAYGINE_TAX', Configuration::get('PAYGINE_TAX'))
		);
	}
	
	public function orderWasPayed($response) {
		$order_id = intval($response->reference);
		if ($order_id == 0) {
			return 'no_order';
		}

		$order = new Order($order_id);
		if (!Validate::isLoadedObject($order)) {
			return 'order_not_matched';
		}

		if (($response->type != 'PURCHASE' && $response->type != 'EPAYMENT') ) {
			return 'unknown_op_type';
		}

		$tmp_response = json_decode(json_encode($response), true);
		unset($tmp_response["signature"]);
		unset($tmp_response["protocol_message"]);

		$signature = base64_encode(md5(implode('', $tmp_response) . $this->password));
		
		if (!($signature === $response->signature)) {
		    return 'wrong_signature';
		} else {
		    if ($response->state != 'APPROVED') {
		        if (!$response->reason_code){
		            return 'valid_unknown_reject';
		        } else {
		            return 'valid_reject_' . $response->reason_code;
		        }
		    }
		        
		    return 'valid_approval';
		}
	}

}

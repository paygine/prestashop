<?php

 /*
  * tested on ver. 1.7.8.7
  */

if (!defined('_PS_VERSION_')) exit;

require_once __DIR__.'/vendor/autoload.php';

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShopBundle\Controller\Admin\Sell\Order\ActionsBarButton;

class Paygine extends PaymentModule {
	const PAYGINE_TABLE = 'paygine';
	const PAYGINE_ORDER_REGISTERED = 'REGISTERED';
	const PAYGINE_ORDER_AUTHORIZED = 'AUTHORIZED';
	const PAYGINE_ORDER_COMPLETED = 'COMPLETED';
	const PAYGINE_ORDER_CANCELED = 'CANCELED';
	const PAYGINE_OPERATION_APPROVED = 'APPROVED';
	const PAYGINE_OPERATION_TYPES = [
		'PURCHASE',
		'PURCHASE_BY_QR',
		'AUTHORIZE',
		'REVERSE',
		'COMPLETE'
	];
	const PAYGINE_PAYMENT_TYPES = [
		'PURCHASE',
		'PURCHASE_BY_QR',
		'AUTHORIZE',
	];
	const PAYGINE_CONFIG_FIELDS = [
		'PAYGINE_SECTOR_ID',
		'PAYGINE_PASSWORD',
		'PAYGINE_MODAL_PAYFORM',
		'PAYGINE_TEST_MODE',
		'PAYGINE_TAX',
		'PAYGINE_PAYMENT_METHOD',
		'PAYGINE_ORDER_COMPLETED',
		'PAYGINE_ORDER_AUTHORIZED',
		'PAYGINE_ORDER_REFUNDED',
	];
	protected $_html = '';
	protected $_postErrors = array();

	public $sector_id;
	public $password;
	public $payment_method = '';
	public $modal_payform = 0;
	public $test_mode = 0;
	public $tax = 6;
	public $paygine_url;
	public $fiscal_positions;
	public $shop_cart = [];
	public $errors = [];
	
	public static $hooks = [
		'payment',
		'paymentReturn',
		'displayPaymentEU',
		'DisplayAdminOrder',
		'ActionGetAdminOrderButtons'
	];
	
	public function __construct() {
		$this->name = 'paygine';
		$this->tab = 'payments_gateways';
		$this->version = '1.1.2';
		$this->author = 'Paygine';
		$this->controllers = array('confirmation', 'notify', 'payment', 'validation');
		$this->is_eu_compatible = 1;

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		$config = Configuration::getMultiple(self::PAYGINE_CONFIG_FIELDS);
		foreach(self::PAYGINE_CONFIG_FIELDS as $field) {
			if(!empty($config[$field]))
				$this->{strtolower(str_replace('PAYGINE_', '', $field))} = $config[$field];
		}
		
		if (!$this->test_mode)
			$this->paygine_url = 'https://pay.paygine.com';
		else
			$this->paygine_url = 'https://test.paygine.com';
		
		$this->bootstrap = true;
		
		parent::__construct();
		
		$this->displayName = 'Paygine';
		$this->description = $this->l('Accept payments for your products via credit and debit cards.');
		$this->confirmUninstall = $this->l('Are you sure about uninstall this module?');
		if (!isset($this->sector_id) || !isset($this->password) || !isset($this->test_mode))
			$this->warning = $this->l('Paygine account details must be configured before using this module.');
		if (!count(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('No currency has been set for this module.');
	}
	
	/**
	 * @return bool
	 */
	public function registerHooks()
	{
		foreach (self::$hooks as $hook) {
			if (!$this->registerHook($hook))
				return false;
		}
		return true;
	}

	public function install() {
		if (!parent::install() || !$this->registerHooks() || !$this->install_db())
			return false;
		
		$id_order_state = Db::getInstance()->getValue("SELECT `id_order_state` FROM " . _DB_PREFIX_ . "order_state WHERE `module_name` = '" . pSQL($this->name) . "'");
		if(!$id_order_state) {
			// create new order state
			$order_state = new OrderState();
			$order_state->name = [];
			foreach (Language::getLanguages(false) as $language) {
				if (Tools::strtolower($language['iso_code']) == 'en')
					$order_state->name[$language['id_lang']] = 'Awaiting payment by card';
				else
					$order_state->name[$language['id_lang']] = $this->l('Awaiting payment by card', false, $language['locale']);
			}
			$order_state->unremovable = true;
			$order_state->invoice = false;
			$order_state->send_email = false;
			$order_state->module_name = $this->name;
			$order_state->color = '#4169E1'; // RoyalBlue
			$order_state->hidden = false;
			$order_state->logable = false;
			$order_state->delivery = false;
			if ($order_state->add()) {
				$source = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logo.gif';
				$destination = _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'os' . DIRECTORY_SEPARATOR . (int) $order_state->id . '.gif';
				copy($source, $destination);
			}
			Configuration::updateValue('PS_OS_PAYGINE', (int) $order_state->id);
		}
		return true;
	}
	
	public function install_db(){
		return Db::getInstance()->Execute( 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::PAYGINE_TABLE . '` (
						`id`							INT(11) NOT NULL,
						`order_id`				INT(11) NOT NULL,
						`payment_method`	VARCHAR(255) NOT NULL,
						`amount`					INT(11) NOT NULL,
						`order_state`			VARCHAR(255) NOT NULL,
						`updated`					DATETIME NOT NULL,
						PRIMARY KEY				(`id`)
						) ENGINE=InnoDB		DEFAULT CHARSET=utf8 ;' );
	}

	public function uninstall() {
		foreach(self::PAYGINE_CONFIG_FIELDS as $field) {
			if(!Configuration::deleteByName($field))
				return false;
		}
		if(!parent::uninstall())
			return false;
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
			foreach(self::PAYGINE_CONFIG_FIELDS as $field)
				Configuration::updateValue($field, Tools::getValue($field));
		}
		$this->_html .= $this->displayConfirmation($this->l('Settings updated'));
	}

	protected function _displayPaygine() {
		$this->smarty->assign('title', $this->l('This module allows you to accept payments by credit and debit cards'));
		return $this->display(__FILE__, 'infos.tpl');
	}
	
	protected function _displayRoundWarning() {
		$round_type = \Configuration::get('PS_ROUND_TYPE') != \Order::ROUND_ITEM;
		$round_mode = \Configuration::get('PS_PRICE_ROUND_MODE') != PS_ROUND_HALF_UP;
		$currencies = $this->checkCurrenciesPrecision();
		if(!$round_type && !$round_mode && !$currencies)
			return '';
		$message = $this->l('Your rounding settings are not fully compatible with Paygine requirements.<br/>In order to avoid some of the transactions to fail, please change');
		if($round_type || $round_mode) {
			$message .= $this->l(' the PrestaShop rounding mode in <a href="@href1@" target="blank"> Preferences > General</a> to');
			$message = str_replace('@href1@' , $this->context->link->getAdminLink('AdminPreferences'), $message);
		}
		$message .= ':';
		$this->smarty->assign( array(
			'message' => $message,
			'round_type' => $round_type,
			'round_mode' => $round_mode,
			'currencies' => $currencies
		));
		return $this->display(__FILE__, 'roundingWarning.tpl');
	}
	
	public function checkCurrenciesPrecision($precision = 2) {
		$currencies = Currency::getPaymentCurrencies($this->id);
		$res = [];
		foreach($currencies as $currency) {
			if($currency['precision'] == $precision) continue;
			$currency['url'] = $this->context->link->getAdminLink('CurrencyController', true, array(
				'route' => 'admin_currencies_edit',
				'action' => 'editAction',
				'currencyId' => $currency['id_currency']
			));
			$res[] = $currency;
		}
		return $res;
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
		
		$module_path = Media::getMediaPath(_PS_MODULE_DIR_.$this->name);
		$action_path = $this->context->link->getModuleLink($this->name, 'validation', array(), true);
		$payment_options = array(
			'cta_text' => $this->l('Pay by credit or debit card'),
			'logo' => $module_path . '/paygine.png',
			'action' => $action_path,
			'additionalInformation' => ''
		);
		if($this->modal_payform){
			$this->smarty->assign(array(
				'paygine_url' => $this->paygine_url,
				'action_path' => $action_path,
				'option_id' => $params['altern'],
				'order_history' => $this->context->link->getPageLink('history')
			));
			$payment_options['additionalInformation'] = $this->display(__FILE__, 'modal_payform.tpl');
		}
		
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
						'desc' => $this->l('Your individual customer number'),
						'required' => true
					),
					array(
						'type' => 'text',
						'label' => $this->l('Password'),
						'name' => 'PAYGINE_PASSWORD',
						'desc' => $this->l('Password used to generate a digital signature'),
						'required' => true
					),
					array(
						'type' => 'select',
						'label' => $this->l('Payment method'),
						'name' => 'PAYGINE_PAYMENT_METHOD',
						'class' => 'fixed-width-xxl',
						'required' => true,
						'options' => array(
							'query' => array(
								array(
									'id' => '',
									'name' => $this->l('Standard acquiring (one-stage payment)')
								),
								array(
									'id' => 'two_steps',
									'name' => $this->l('Standard acquiring (two-stage payment)') . ' *'
								),
								array(
									'id' => 'halva',
									'name' => $this->l('Halva Chastyami (one-stage payment)')
								),
								array(
									'id' => 'halva_two_steps',
									'name' => $this->l('Halva Chastyami (two-stage payment)') . ' *'
								),
								array(
									'id' => 'sbp',
									'name' => $this->l('Fast Payment System')
								)
							),
							'id' => 'id',
							'name' => 'name'
						),
						'desc' => '* ' . $this->l('Payment occurs after confirmation by the manager in the personal account')
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Open the payment form in modal window'),
						'name' => 'PAYGINE_MODAL_PAYFORM',
						'is_bool' => true,
						'values' => array(
							array(
								'id' => 'on',
								'value' => true,
								'label' => $this->l('On')
							),
							array(
								'id' => 'off',
								'value' => '0',
								'label' => $this->l('Off')
							)
						),
						'desc' => $this->l('When this option is enabled, the payment form opens in a modal window.')
					),
					array(
						'type' => 'select',
						'label' => $this->l('Test mode'),
						'name' => 'PAYGINE_TEST_MODE',
						'options' => array(
							'query' => array(
								array(
									'id' => 'on',
									'value' => 1,
									'name' => $this->l('On')
								),
								array(
									'id' => 'off',
									'value' => 0,
									'name' => $this->l('Off')
								)
							),
							'id' => 'id',
							'name' => 'name'
						),
						'desc' => $this->l('Use emulation of real work. Buyer will not be charged')
					),
					array(
						'type' => 'radio',
						'label' => $this->l('Tax'),
						'name' => 'PAYGINE_TAX',
						'values' => array(
							array(
								'id'    => 'ch1',
								'label' => $this->l('VAT rate 20%'),
								'value' => 1
							),
							array(
								'id'    => 'ch2',
								'label' => $this->l('VAT rate 10%'),
								'value' => 2
							),
							array(
								'id'    => 'ch3',
								'label' => $this->l('VAT rate calc 20/120'),
								'value' => 3
							),
							array(
								'id'    => 'ch4',
								'label' => $this->l('VAT rate calc 10/110'),
								'value' => 4
							),
							array(
								'id'    => 'ch5',
								'label' => $this->l('VAT rate 0%'),
								'value' => 5
							),
							array(
								'id'    => 'ch6',
								'label' => $this->l('Not subject to VAT'),
								'value' => 6
							)
						),
						'required' => true
					),
					
					array(
						'type' => '',
						'name' => 'custom_statuses_title',
						'label' => $this->l('Custom statuses for orders'),
					),
					
					array(
						'type' => 'select',
						'label' => $this->l('Payment completed'),
						'name' => 'PAYGINE_ORDER_COMPLETED',
						'class' => 'fixed-width-xxl',
						'options' => array(
							'query' => array_merge(
								array(
									array(
										'id_order_state' => 0,
										'id_lang' => $this->context->language->id,
										'name' => ''
									)
								),
								OrderState::getOrderStates($this->context->language->id)
							),
							'id' => 'id_order_state',
							'name' => 'name'
						)
					),
					array(
						'type' => 'select',
						'label' => $this->l('Payment authorized'),
						'name' => 'PAYGINE_ORDER_AUTHORIZED',
						'class' => 'fixed-width-xxl',
						'options' => array(
							'query' => array_merge(
								array(
									array(
										'id_order_state' => 0,
										'id_lang' => $this->context->language->id,
										'name' => ''
									)
								),
								OrderState::getOrderStates($this->context->language->id)
							),
							'id' => 'id_order_state',
							'name' => 'name'
						)
					),
					array(
						'type' => 'select',
						'label' => $this->l('Payment refunded'),
						'name' => 'PAYGINE_ORDER_REFUNDED',
						'class' => 'fixed-width-xxl',
						'options' => array(
							'query' => array_merge(
								array(
									array(
										'id_order_state' => 0,
										'id_lang' => $this->context->language->id,
										'name' => ''
									)
								),
								OrderState::getOrderStates($this->context->language->id)
							),
							'id' => 'id_order_state',
							'name' => 'name'
						)
					),
					
					array(
						'type' => '',
						'name' => 'notify_url_title',
						'label' => '',
					),
					
					array(
						'type' => 'text',
						'name' => 'notify_url',
						'label' => $this->l('Notify URL'),
						'desc' => $this->l('Report this URL to Paygine technical support to receive payment notifications'),
						'readonly' => true,
					),
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
		$res = array();
		foreach(self::PAYGINE_CONFIG_FIELDS as $field)
			$res[$field] = Tools::getValue($field, Configuration::get($field)) ? : null;
		// default values
		if(!isset($res['PAYGINE_MODAL_PAYFORM']))
			$res['PAYGINE_MODAL_PAYFORM'] = false;
		if(!isset($res['PAYGINE_TEST_MODE']))
			$res['PAYGINE_TEST_MODE'] = 1;
		if(!isset($res['PAYGINE_TAX']))
			$res['PAYGINE_TAX'] = '6';
		$res['notify_url'] = $this->context->link->getModuleLink($this->name, 'notify', array(), true);
		return $res;
	}
	
	/**
	 * @param $url string
	 * @param $data array
	 * @param $method string
	 * @return false|string
	 */
	public function sendRequest($url, $data, $method = 'POST')
	{
		$query = http_build_query($data);
		
		$context = stream_context_create(array(
			'http' => array(
				'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
					. "Content-Length: " . strlen($query) . "\r\n",
				'method'  => $method,
				'content' => $query
			)
		));
		
		if (!$context)
			throw new Exception($this->l('Creates a stream context failed'));
		
		$repeat = 3;
		while ($repeat) {
			$repeat--;
			$response = file_get_contents($url, false, $context);
			if($response !== false)
				break;
			sleep(2);
		}
		return $response;
	}
	
	public function redirectWithNotification($url, $message = '', $type = 'errors') {
		if($message)
			$this->context->controller->{$type}[] = $message;
		
		if($this->modal_payform) {
			echo "<script type='text/JavaScript'>
						window.top.location.href = '{$url}';
					</script>";
			die();
		} else {
			$this->context->controller->redirectWithNotifications($url);
		}
	}
	
	public function hookDisplayAdminOrder($params) {
		$id_order = $params['id_order'];
		$order = new Order((int) $id_order);
		if ($order->module == $this->name) {
			$order_token = Tools::getAdminToken( 'AdminOrders' . (int) Tab::getIdFromClassName( 'AdminOrders' ) . (int) $this->context->employee->id );
			$router = $this->get('router');
			$this->context->smarty->assign( array(
				'ps_version' => _PS_VERSION_,
				'id_order' => $id_order,
				'order_token' => $order_token,
				//'checkbox_text' => '',
				'non_refundable' => $this->l("Paygine does not support partial refund")
			));
			return $this->display( __FILE__, 'views/templates/hook/display_admin_order.tpl' );
		}
	}
	
	public function hookActionGetAdminOrderButtons($params)	{
		$paygine_order_state = $this->getPaygineOrderState(['order_id' => (int) $params['id_order']]);
		if($paygine_order_state) {
			$order = new Order($params['id_order']);
			$bar = $params['actions_bar_buttons_collection'];
			$router = SymfonyContainer::getInstance()->get('router');
			
			$complete_data = [
				'id' => 'order_complete_button'
			];
			$complete_class = 'btn-action disabled';
			if($paygine_order_state == self::PAYGINE_ORDER_AUTHORIZED) {
				$complete_data['url'] = $router->generate('paygine_admin_order_complete', ['order_id'=> (int)$order->id]);
				$complete_class = 'btn-primary';
			}
			$complete_button = new ActionsBarButton($complete_class, $complete_data, $this->l('Complete'));
			$bar->add($complete_button);
			
			$refund_data = [
				'id' => 'order_refund_button'
			];
			$refund_class = 'btn-action disabled';
			if($paygine_order_state == self::PAYGINE_ORDER_AUTHORIZED || $paygine_order_state == self::PAYGINE_ORDER_COMPLETED) {
				$refund_data['url'] = $router->generate('paygine_admin_order_refund', ['order_id' => (int)$order->id]);
				$refund_class = 'btn-primary';
			}
			$refund_button = new ActionsBarButton($refund_class, $refund_data, $this->l('Refund'));
			$bar->add($refund_button);
		}
	}
	
	function calcFiscalPositionsShopCart($order, $tax) {
		$fiscal_positions = '';
		$shop_cart = [];
		$fiscal_amount = 0;
		$sc_key = 0;
		foreach($this->context->cart->getProducts() as $product) {
			$fiscal_positions .= $product['cart_quantity'] . ';';
			$element_price = intval(round($product['price_wt'] * 100));
			$fiscal_positions .= $element_price . ';';
			$fiscal_positions .= $tax . ';';
			$fiscal_positions .= $product['name'] . '|';
			$fiscal_amount += $product['cart_quantity'] * $element_price;
			
			$shop_cart[$sc_key]['name'] = $product['name'];
			$shop_cart[$sc_key]['quantityGoods'] = (int) $product['cart_quantity'];
			$shop_cart[$sc_key]['goodCost'] = round($product['price_wt'] * $shop_cart[$sc_key]['quantityGoods'], 2);
			$sc_key++;
		}
		if($order->total_shipping > 0){
			$fiscal_positions .= '1;';
			$element_price = intval(round($order->total_shipping * 100));
			$fiscal_positions .= $element_price . ';';
			$fiscal_positions .= $tax . ';';
			$fiscal_positions .= $this->l('Delivery') . '|';
			$fiscal_amount += $element_price;
			
			$shop_cart[$sc_key]['quantityGoods'] = 1;
			$shop_cart[$sc_key]['goodCost'] = round($order->total_shipping, 2);
			$shop_cart[$sc_key]['name'] = $this->l('Delivery');
		}
		$order_amount = intval($order->total_paid * 100);
		$fiscal_diff = abs($fiscal_amount - $order_amount);
		if ($fiscal_diff) {
			$fiscal_positions .= '1;' . $fiscal_diff . ';6;' . $this->l('Discount') . ';14|';
			$shop_cart = [];
		}
		$this->fiscal_positions = substr($fiscal_positions, 0, -1);
		$this->shop_cart = $shop_cart;
	}
	
	public function registerOrder($order, $customer, $address, $currency) {
		$order_amount = intval($order->total_paid * 100);
		$order_data = array(
			'sector' => $this->sector_id,
			'reference' => $order->id,
			'fiscal_positions' => $this->fiscal_positions,
			'amount' => $order_amount,
			'description' => sprintf($this->l('Order #%s'), $order->reference),
			'email' => $customer->email,
			'phone' => $address->phone,
			'currency' => $currency,
			'mode' => 1,
			'url' => $this->context->link->getModuleLink($this->name, 'confirmation', array(), true),
			'signature' => base64_encode(md5($this->sector_id . $order_amount . $currency . $this->password))
		);
		
		$old_lvl = error_reporting(0);
		$paygine_id = $this->sendRequest($this->paygine_url . '/webapi/Register', $order_data);
		error_reporting($old_lvl);
		
		if (intval($paygine_id) == 0) {
			error_log($paygine_id);
			return false;
		}
		
		$this->storePaygineOrder($paygine_id, [
			'order_id' => $order->id,
			'payment_method' => $this->payment_method,
			'amount' => $order_amount,
			'order_state' => self::PAYGINE_ORDER_REGISTERED
		]);
		
		return $paygine_id;
	}
	
	/**
	 * @param $order_id int
	 * @param $operation_id int
	 * @return false|mixed
	 */
	function getPaymentOperationInfo($order_id, $operation_id){
		$data = array(
			'sector' => $this->sector_id,
			'id' => $order_id,
			'operation' => $operation_id,
			'signature' => base64_encode(md5($this->sector_id . $order_id . $operation_id . $this->password))
		);
		return $this->sendRequest($this->paygine_url . '/webapi/Operation', $data);
	}
	
	public function prepareWhere($data) {
		if(is_array($data)) {
			$where = [];
			foreach($data as $field => $value) {
				$where[] = '`' . pSQL($field) . '` = "' . pSQL( $value ) . '"';
			}
			$where = implode(' AND ', $where);
		} else {
			$where = '`id` = ' . (int) $data;
		}
		return $where;
	}
	
	public function getPaygineOrder($id) {
		$where = $this->prepareWhere($id);
		$query = 'SELECT * FROM ' . _DB_PREFIX_ . self::PAYGINE_TABLE . ' WHERE ' . $where;
		return Db::getInstance()->getRow($query);
	}
	
	public function getPaygineOrderState($id) {
		$where = $this->prepareWhere($id);
		$query = 'SELECT `order_state` FROM ' . _DB_PREFIX_ . self::PAYGINE_TABLE . ' WHERE ' . $where;
		return Db::getInstance()->getValue($query);
	}
	
	public function storePaygineOrder($id, $raw_data) {
		if(!$id && !$raw_data)
			return false;
		$data['id'] = (int) $id;
		foreach($raw_data as $field => $value) {
			$data[pSQL($field)] = pSQL( $value );
		}
		$data['updated'] = date('Y-m-d H:i:s');
		return Db::getInstance()->insert(self::PAYGINE_TABLE, $data);
	}
	
	public function updatePaygineOrder($id, $raw_data) {
		if(!$id && !$raw_data)
			return false;
		$data = [];
		foreach($raw_data as $field => $value) {
			$data[pSQL($field)] = pSQL( $value );
		}
		$data['updated'] = date('Y-m-d H:i:s');
		$where = $this->prepareWhere($id);
		return Db::getInstance()->update(self::PAYGINE_TABLE, $data, $where);
	}
	
	public function signData(&$data, $password = true) {
		$sign = $this->sector_id . implode('', $data);
		if($password)
			$sign .= $this->password;
		$data['sector'] = $this->sector_id;
		$data['signature'] = base64_encode(md5($sign));
	}
	
	function prepareOrderDataForSending($paygine_order) {
		$currency = '643';
		return [
			'id' => $paygine_order['id'],
			'amount' => $paygine_order['amount'],
			'currency' => $currency
		];
	}
	
	/**
	 * @param $string string
	 * @return array
	 * @throws Exception
	 */
	public function parseXML($string) {
		if (!$string)
			throw new Exception($this->l("Empty response"));
		$xml = simplexml_load_string($string);
		if (!$xml)
			throw new Exception($this->l("Invalid XML"));
		$valid_xml = json_decode(json_encode($xml), true);
		if (!$valid_xml)
			throw new Exception($this->l("Invalid XML"));
		return $valid_xml;
	}
	
	/**
	 * @param $xml_array
	 * @return false|string
	 */
	public function xmlValuesToString($xml_array) {
		if(!is_array($xml_array)) return '';
		$res = '';
		foreach($xml_array as $key => $value) {
			if($key === '@attributes' or $key === 'signature') continue;
			$res .= is_string($value) ? $value : $this->xmlValuesToString($value);
		}
		return $res;
	}
	
	/**
	 * @param $response array
	 * @return bool|string
	 */
	public function operationIsValid($response) {
		if(empty($response['signature']))
			throw new Exception($this->l("Empty signature"));
		$xml_string = $this->xmlValuesToString($response);
		$signature = base64_encode(md5($xml_string . $this->password));
		if ($signature !== $response['signature'])
			throw new Exception($this->l("Invalid signature"));
		if(!in_array($response['type'], self::PAYGINE_OPERATION_TYPES))
			throw new Exception($this->l("Unknown operation type") . " : " . $response['type']);
		return true;
	}
	
	public function completePaygineOrder($paygine_order) {
		$path = ($paygine_order['payment_method'] == 'halva' || $paygine_order['payment_method'] == 'halva_two_steps') ? '/webapi/custom/svkb/Complete' : '/webapi/Complete';
		return $this->actionPaygineOrder($paygine_order, $path);
	}
	
	public function refundPaygineOrder($paygine_order) {
		$path = ($paygine_order['payment_method'] == 'halva' || $paygine_order['payment_method'] == 'halva_two_steps') ? '/webapi/custom/svkb/Reverse' : '/webapi/Reverse';
		return $this->actionPaygineOrder($paygine_order, $path);
	}
	
	public function actionPaygineOrder($paygine_order, $path) {
		$url = $this->paygine_url . $path;
		$data = $this->prepareOrderDataForSending($paygine_order);
		$this->signData($data);
		try {
			$order = new Order($paygine_order['order_id']);
			if (!Validate::isLoadedObject($order))
				throw new Exception($this->l('Order not found'));
			$response = $this->sendRequest($url, $data);
			$paygine_operation = $this->parseXML($response);
			$operation_is_valid = $this->operationIsValid($paygine_operation);
			if($paygine_operation['state'] !== self::PAYGINE_OPERATION_APPROVED) {
				throw new Exception($this->l('Operation not approved'));
			}
		} catch(Exception $e) {
			$this->get('session')->getFlashBag()->add('error', $e->getMessage());
			return false;
		}
		if($this->getPaygineOrder($paygine_order['id'])) {
			$amount = !empty($paygine_operation['buyIdSumAmount']) ? $paygine_operation['buyIdSumAmount'] : $paygine_operation['amount'];
			$this->updatePaygineOrder($paygine_order['id'], [
				'order_state' => $paygine_operation['order_state'],
				'amount' => $amount
			]);
		}
		$order->setCurrentState($this->getCustomOrderState($paygine_operation['type']));
		$order->save();
		return true;
	}
	
	public function getCustomOrderState($operation_type) {
		switch($operation_type){
			case 'PURCHASE':
			case 'PURCHASE_BY_QR':
			case 'COMPLETE':
				return !empty($this->order_completed) ? $this->order_completed : Configuration::get('PS_OS_PAYMENT');
			case 'AUTHORIZE':
				return !empty($this->order_authorized) ? $this->order_authorized : Configuration::get('PS_OS_PAYMENT');
			case 'REVERSE':
				return !empty($this->order_refunded) ? $this->order_refunded : Configuration::get('PS_OS_REFUND');
		}
		return false;
	}

	
}

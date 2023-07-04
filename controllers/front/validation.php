<?php
if (!defined('_PS_VERSION_')) exit;

/**
 * @since 1.5.0
 */
class PaygineValidationModuleFrontController extends ModuleFrontController {
	/**
	 * @see FrontController::postProcess()
	 */
	
	public function postProcess() {
		$sector_id = $this->module->sector_id;
		$password = $this->module->password;
		$payment_method = $this->module->payment_method;
		$cart = $this->context->cart;
		$order_url = $this->context->link->getPageLink('order');
		$order_history_url = $this->context->link->getPageLink('history');
		
		if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
			$this->module->redirectWithNotification($order_url);
		
		$customer = new Customer($cart->id_customer);
		if (!Validate::isLoadedObject($customer))
			Tools::redirect('index.php?controller=order&step=1');
		
		if (!Validate::isLoadedObject($cart))
			$this->module->redirectWithNotification($order_history_url);
		
		$currency = $this->context->currency;
		$total = (float) $cart->getOrderTotal();
		
		$this->module->validateOrder($cart->id, Configuration::get('PS_OS_PAYGINE'), $total, $this->module->displayName, NULL, array(), (int)$currency->id, false, $customer->secure_key);
		
		$order = new Order($this->module->currentOrder);
		$reorder_url = $this->context->link->getPageLink('order', true, null, 'submitReorder&id_order=' . (int) $order->id);
		
		if (!Validate::isLoadedObject($order))
			$this->module->redirectWithNotification($reorder_url, $this->module->l('Validation error'));
		
		$currency_obj = new Currency($order->id_currency);
		if (!Validate::isLoadedObject($currency_obj))
			$this->module->redirectWithNotification($reorder_url, $this->module->l('Currency error'));
		$currency = $currency_obj->iso_code_num;
		$address = new Address($order->id_address_invoice);
		if (!Validate::isLoadedObject($address))
			$this->module->redirectWithNotification($reorder_url, $this->module->l('Address error'));
		
		$tax = (isset($this->module->tax) && $this->module->tax > 0 && $this->module->tax <= 6) ? $this->module->tax : 6;
		$this->module->calcFiscalPositionsShopCart($order, $tax);
		
		$paygine_id = $this->module->registerOrder($order, $customer, $address, $currency);
		if(!$paygine_id)
			$this->module->redirectWithNotification($reorder_url, $this->module->l('Failed to create order. Please try later.'));
		
		$args = [
			'sector' => $sector_id,
			'id' => $paygine_id,
		];
		
		switch($payment_method){
			case 'two_steps':
				$payment_path = '/webapi/Authorize';
				break;
			case 'halva':
				$payment_path = '/webapi/custom/svkb/PurchaseWithInstallment';
				break;
			case 'halva_two_steps':
				$payment_path = '/webapi/custom/svkb/AuthorizeWithInstallment';
				break;
			case 'sbp':
				$payment_path = '/webapi/PurchaseSBP';
				break;
			default:
				$payment_path = '/webapi/Purchase';
		}
		$shop_cart_encoded = '';
		if(!empty($this->module->shop_cart) && ($payment_method == 'halva' || $payment_method == 'halva_two_steps')) {
			$shop_cart_encoded = base64_encode(json_encode($this->module->shop_cart, JSON_UNESCAPED_UNICODE));
			$args['shop_cart'] = $shop_cart_encoded;
		}
		$args['signature'] = base64_encode(md5($sector_id . $paygine_id . $shop_cart_encoded . $password));
		
		$url = $this->module->paygine_url . $payment_path . "?" . urldecode(http_build_query($args));
		// in the current window
		Tools::redirect($url);
	}

}

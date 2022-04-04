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
		$cart = $this->context->cart;
		if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
			Tools::redirect('index.php?controller=order&step=1');

		//$authorized = false;
		//foreach (Module::getPaymentModules() as $module) {
		//	if ($module['name'] == 'paygine') {
		//		$authorized = true;
		//		break;
		//	}
		//}
		//if (!$authorized)
		//	Tools::redirect('index.php?controller=order&step=1');

		$customer = new Customer($cart->id_customer);
		if (!Validate::isLoadedObject($customer))
			Tools::redirect('index.php?controller=order&step=1');

		$currency = $this->context->currency;
		$total = (float)$cart->getOrderTotal(true, Cart::BOTH);

		$this->module->validateOrder($cart->id, Configuration::get('PS_OS_PAYGINE'), $total, $this->module->displayName, NULL, array(), (int)$currency->id, false, $customer->secure_key);
		$order = new Order($this->module->currentOrder);
		if (!Validate::isLoadedObject($order))
			Tools::redirect('index.php?controller=order&step=1');

		$url = $this->registerOrder($order, $customer);
		if (!$url)
			Tools::redirect('index.php?controller=order&step=3');
		else
			Tools::redirect($url);
	}

	private function registerOrder($order, $customer) {
		$currency_obj = new Currency($order->id_currency);
		if (!Validate::isLoadedObject($currency_obj))
			return false;
		$currency = $currency_obj->iso_code_num;

		if (!$this->module->test_mode) {
			$paygine_url = 'https://pay.paygine.com';
		} else {
			$paygine_url = 'https://test.paygine.com';
		}

		$address = new Address($order->id_address_invoice);
		if (!Validate::isLoadedObject($address))
			return false;

		$TAX = (isset($this->module->paygine_tax) && $this->module->paygine_tax > 0 && $this->module->paygine_tax <= 6) ? $this->module->paygine_tax : 6;
		$fiscalPositions='';
		$fiscalAmount = 0;
		foreach ($this->context->cart->getProducts() as $product) {
			$fiscalPositions .= $product['cart_quantity'] . ';';
			$fiscalPositions .= $product['price']*100 . ';';
			$fiscalPositions .= $TAX . ';';
			$fiscalPositions .= $product['name'] . '|';
			$fiscalAmount = $fiscalAmount + (intval($product['cart_quantity'])*intval($product['price']*100));
		}
		if ($order->total_shipping > 0) {
			$fiscalPositions.='1;';
			$fiscalPositions.=($order->total_shipping*100).';';
			$fiscalPositions.=$TAX.';';
			$fiscalPositions.='Доставка'.'|';
			$fiscalAmount = $fiscalAmount + $order->total_shipping*100;
		}
		$amountDiff = abs($fiscalAmount - intval($order->total_paid * 100));
		if ($amountDiff != 0){
			$fiscalPositions.='1'.';';
			$fiscalPositions.=$amountDiff.';';
			$fiscalPositions.=$TAX.';';
			$fiscalPositions.='coupon'.';';
			$fiscalPositions.='14'.'|';
			$fiscalAmount = intval($order->total_paid * 100);
		}
	    $fiscalPositions = substr($fiscalPositions, 0, -1);

		$signature = base64_encode(md5($this->module->sector_id . intval($order->total_paid * 100) . $currency . $this->module->password));
		$query = http_build_query(array(
			'sector' => $this->module->sector_id,
			'reference' => $order->id,
			'fiscal_positions' => $fiscalPositions,
			'amount' => intval($order->total_paid * 100),
			'description' => sprintf($this->module->l('Order #%s'), $order->reference),
			'email' => $customer->email,
			'phone' => $address->phone,
			'currency' => $currency,
			'mode' => 1,
			'url' => $this->context->link->getModuleLink($this->module->name, 'confirmation', array(), true),
			'signature' => $signature
		));

		$context = stream_context_create(array(
			'http' => array(
				'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
					. "Content-Length: " . strlen($query) . "\r\n",
				'method'  => 'POST',
				'content' => $query
			)
		));
		if (!$context)
			return false;

		$old_lvl = error_reporting(0);
		$paygine_order_id = file_get_contents($paygine_url . '/webapi/Register', false, $context);
		error_reporting($old_lvl);

		if (intval($paygine_order_id) == 0) {
			error_log($paygine_order_id);
			return false;
		} else {
			$signature = base64_encode(md5($this->module->sector_id . $paygine_order_id . $this->module->password));
			return "{$paygine_url}/webapi/Purchase?sector={$this->module->sector_id}&id={$paygine_order_id}&signature={$signature}";
		}
	}

}

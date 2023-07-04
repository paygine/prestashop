<?php
if (!defined('_PS_VERSION_')) exit;

/**
 * @since 1.5.0
 */
class PaygineConfirmationModuleFrontController extends ModuleFrontController {

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent() {
		$link = Context::getContext()->link;
		try{
			$paygine_id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : null;
			if (!$paygine_id)
				throw new Exception($this->module->l('Missing Paygine Order ID in the request'));
			$order_id = isset($_REQUEST['reference']) ? (int) $_REQUEST['reference'] : null;
			if (!$order_id)
				throw new Exception($this->module->l('Missing Order ID in the request'));
			$paygine_operation_id = isset($_REQUEST['operation']) ? (int) $_REQUEST['operation'] : null;
			if (!$paygine_operation_id) {
				$error = $this->module->l('Missing Operation ID in the request');
				$error .= " (error " . (isset($_REQUEST['error']) ? (int) $_REQUEST['error'] : 'unknown') . ")";
				throw new Exception($error);
			}
			$order = new Order($order_id);
			if (!Validate::isLoadedObject($order))
				throw new Exception($this->module->l('Order not found'));
			
			$paygine_response = $this->module->getPaymentOperationInfo($paygine_id, $paygine_operation_id);
			$paygine_operation = $this->module->parseXML($paygine_response);
			$operation_is_valid = $this->module->operationIsValid($paygine_operation);
		} catch(Exception $e) {
			$this->module->redirectWithNotification($link->getPageLink('history'), $e->getMessage());
		}
		
		if ($paygine_operation['state'] == $this->module::PAYGINE_OPERATION_APPROVED && in_array($paygine_operation['type'], $this->module::PAYGINE_PAYMENT_TYPES)) {
			$order_state = $this->module->getCustomOrderState($paygine_operation['type']);
			$cart = $this->context->cart;
			$customer = new Customer($cart->id_customer);
			$args = 'id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key;
			$redirect_url = $link->getPageLink('order-confirmation') . '?' . $args;
			$this->success[] = $this->module->l('Order successfully paid');
		} else {
			$order_state = Configuration::get('PS_OS_ERROR');
			$redirect_url = $link->getPageLink('order-detail') . '?id_order=' . $order_id;
			$this->warning[] = $this->module->l('An error occurred while paying for the order');
		}
		$order->setCurrentState($order_state);
		$order->save();
		if($this->module->getPaygineOrder($paygine_id)) {
			$amount = !empty($paygine_operation['buyIdSumAmount']) ? $paygine_operation['buyIdSumAmount'] : $paygine_operation['amount'];
			$this->module->updatePaygineOrder($paygine_id, [
				'amount' => $amount,
				'order_state' => $paygine_operation['order_state']
			]);
		}
		
		$this->module->redirectWithNotification($redirect_url);
	}

}
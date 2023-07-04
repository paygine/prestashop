<?php
if (!defined('_PS_VERSION_')) exit;

/**
 * @since 1.5.0
 */
class PaygineNotifyModuleFrontController extends ModuleFrontController {
	/**
	 * @see FrontController::initContent()
	 */
	public function initContent() {
		header('Content-Type: text/plain');
		$response = file_get_contents("php://input");
		try{
			$paygine_operation = $this->module->parseXML($response);
			$operation_is_valid = $this->module->operationIsValid($paygine_operation);
			$order = new Order((int)$paygine_operation['reference']);
			if (!Validate::isLoadedObject($order))
				throw new Exception($this->module->l('Order not found'));
		} catch(Exception $e) {
			die($e->getMessage());
		}
		
		if ($operation_is_valid) {
			$state = $this->module->getCustomOrderState($paygine_operation['type']);
			$message = 'ok';
		} else {
			$state = Configuration::get('PS_OS_ERROR');
			$message = 'Operation is invalid';
		}
		$order->setCurrentState($state);
		$order->save();
		if($this->module->getPaygineOrder($paygine_operation['order_id'])) {
			$amount = !empty($paygine_operation['buyIdSumAmount']) ? $paygine_operation['buyIdSumAmount'] : $paygine_operation['amount'];
			$this->module->updatePaygineOrder($paygine_operation['order_id'], [
				'amount' => $amount,
				'order_state' => $paygine_operation['order_state']
			]);
		}
		die($message);
	}

}

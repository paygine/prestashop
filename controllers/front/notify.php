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

		$xml = file_get_contents("php://input");
		if (!$xml)
			die("error");
		$xml = simplexml_load_string($xml);
		if (!$xml)
			die("error");
		$response = json_decode(json_encode($xml));
		if (!$response)
			die("error");

		$order = new Order((int)$response->reference);

		if ($this->module->orderWasPayed($response) == 'valid_approval') {
			$order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
			$order->save();
			die("ok");
		} else {
			$order->setCurrentState(Configuration::get('PS_OS_ERROR'));
			$order->save();
			die("error");
		}
	}

}

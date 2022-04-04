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
		$order_id = intval($_REQUEST["reference"]);
		// Need this pause to avoid receiving PENDING status
		sleep(2);
		
		if (!$order_id)
			Tools::redirect('index.php?controller=order&step=1');

		$order = new Order($order_id);
		$res = $this->checkPaymentStatus();
			
		if ($res == 'valid_approval') {
			$order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
			$order->save();
			$cart = $this->context->cart;
			$customer = new Customer($cart->id_customer);
			Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
		} else {
			$order->setCurrentState(Configuration::get('PS_OS_ERROR'));
			$order->save();
			Tools::redirect('index.php?controller=order-detail&id_order=' . $order_id);
		}
	}

	private function checkPaymentStatus() {
		$paygine_order_id = intval($_REQUEST["id"]);
		if (!$paygine_order_id)
			return 'no_paygine_order_id_in_redirect';

		$order_id = intval($_REQUEST["reference"]);
		if (!$order_id)
			return 'no_reference_in_redirect';

		$paygine_operation_id = intval($_REQUEST["operation"]);
		if (!$paygine_operation_id) {
            $paygine_error_id = intval($_REQUEST["error"]);
		    if (!(!$paygine_error_id)) {
			    return 'error_' . $paygine_error_id;
		    } else {
			    return 'unknown_error';
            }
		}

		$signature = base64_encode(md5($this->module->sector_id . $paygine_order_id . $paygine_operation_id . $this->module->password));

		if (!$this->module->test_mode) {
			$paygine_url = 'https://pay.paygine.com';
		} else {
			$paygine_url = 'https://test.paygine.com';
		}

		$context  = stream_context_create(array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query(array(
					'sector' => $this->module->sector_id,
					'id' => $paygine_order_id,
					'operation' => $paygine_operation_id,
					'signature' => $signature
				)),
			)
		));

		$repeat = 3;

		while ($repeat) {
			$repeat--;

			$xml = file_get_contents($paygine_url . '/webapi/Operation', false, $context);

			if (!$xml) {
			    Tools::redirect('index.php?controller=order-confirmation&id_cart=notxml' );
				sleep(2);
				continue;
			}

			$xml = simplexml_load_string($xml);
			if (!$xml) {
			    Tools::redirect('index.php?controller=order-confirmation&id_cart=notxml1' );
				sleep(2);
				continue;
			}

			$response = json_decode(json_encode($xml));
			if (!$response) {
			    Tools::redirect('index.php?controller=order-confirmation&id_cart=notresponse' );
				sleep(2);
				continue;
			}

//			if (!$this->module->orderWasPayed($response)) {
//				sleep(2);
//				continue;
//			}
			
			return $this->module->orderWasPayed($response);
		}

		return false;
	}

}

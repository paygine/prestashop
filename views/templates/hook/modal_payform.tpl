<div id="paygine_block"></div>
<script defer="defer" src="{$paygine_url}/static/common/scripts/modalPayform.bundle.js"></script>
<script>
	let modal;
	let userWaiting;
	window.addEventListener("load", () => {
		modal = modalPayform("{$action_path}");
		let paygine_option = $("#payment-option-{$option_id}");
		let submit_button = $("#paygine_block").closest("div.content").find('button[type="submit"]');
		submit_button.click(function (e){
			if(paygine_option.is(":checked") && !$(this).hasClass("disabled")){
				modal.openModal();
				startTimer();
				return false;
			}
			return true;
		});
	});
	let observer = new MutationObserver(function (mutations) {
		mutations.forEach(function (mutation) {
			[].filter.call(mutation.addedNodes, function (node) {
				return node.id === 'payform-modal'; //
			}).forEach(function (node) {
				let button = node.querySelector('#payform-close-button');
				button.addEventListener('click', function (e){
					redirectToOrderHistory();
				});
			});
		});
	});
	observer.observe(document.body, { childList: true, subtree: true });
	function startTimer() {
		userWaiting = setTimeout(() => {
			clearTimeout(userWaiting);
			redirectToOrderHistory();
		}, 5 * 60 * 1000);
	}
	function redirectToOrderHistory() {
		window.top.location.href = '{$order_history}';
	}
</script>
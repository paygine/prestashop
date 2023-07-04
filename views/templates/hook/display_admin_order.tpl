<script>
    {literal}
		const refund_text = {/literal}{$checkbox_text|json_encode}{literal};
		const order_state = false;
		const non_refundable = {/literal}{$non_refundable|json_encode}{literal};

		/* Add Checkbox */
		$(document).ready(() => {
			if($('.alert[role="alert"]:visible').length) {
				$([document.documentElement, document.body]).animate({
					scrollTop: $('body').offset().top
				}, 1000);
			}
			/* For prestashop version >= 1.7.7 */
			$(document).on('click', '.partial-refund-display ,.return-product-display, .standard-refund-display', function(){
				/* Create checkbox and insert for refund */
				if ($('#paygine_refund').length === 0) {
					/* Display message if transaction is not captured */
					let refund_checkbox = `<p id="paygine_refund" class="checkbox" style="color:red">` + non_refundable + `</p>`;
					if(order_state === "AUTHORIZE" || order_state === "COMPLETED"){
						refund_checkbox = `
							<div class="cancel-product-element form-group" style="display: block;">
								<div class="checkbox">
									<div class="md-checkbox md-checkbox-inline">
										<label>
											<input type="checkbox" id="paygine_refund" name="paygine_refund" checked value="1">
											<i class="md-checkbox-control"></i>
											${refund_text}
										</label>
									</div>
								</div>
							</div>`;
					}
					$('.refund-checkboxes-container').prepend(refund_checkbox);
					/* Init checkboxes link */
					initLinkedCheckboxes("#cancel_product_credit_slip","#paygine_refund");
				}
			});

			$('#order_complete_button, #order_refund_button').on('click', function(){
				let url = $(this).attr('url');
				if(url.length) {
					orderPaymentRequest(url);
					location.reload();
				}
			});
		});

		function initLinkedCheckboxes(slipCheckboxId,refundCheckboxId){
			/* Skip if "Generate a credit slip" is not present */
			if(!$(slipCheckboxId).length)
				return false;

			/* Make refund checkbox dependent on "Generate a credit slip" checkbox */
			$(refundCheckboxId).change(function() {
				if(this.checked) {
					$(slipCheckboxId).prop("checked", 1);
				}
			});

			/* Make "Generate a credit slip" checkbox dependent on refund checkbox */
			$(slipCheckboxId).change(function() {
				if(!this.checked) {
					$(refundCheckboxId).prop("checked", 0);
				}
			});
		}

		function orderPaymentRequest(url) {
			$.ajax({
				url: url,
				error: function (err) {
					console.log(err);
				}
			});
		}
    {/literal}
</script>
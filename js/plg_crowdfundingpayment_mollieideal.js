jQuery(document).ready(function() {

	jQuery("#js-mollieideal-bank-id").on("change", function(event){
		event.preventDefault();
		
		var bankId    = jQuery(this).val();
		
		if(bankId > 0) {
			
			var projectId = jQuery(this).data("project-id");
			var rewardId  = jQuery(this).data("reward-id");
			var amount    = jQuery(this).data("amount");
			
			var data = {
				bank_id: bankId,
				pid: projectId,
				reward_id: rewardId,
				amount: amount,
				payment_service: "mollieideal"
			};
			
			jQuery.ajax({
				url: "index.php?option=com_crowdfunding&task=payments.preparePaymentAjax&format=raw",
				type: "GET",
				data: data,
				dataType: "text json",
				cache: false,
				beforeSend: function(response) {
					
					// Display ajax loading image
					jQuery("#js-mollie-ajax-loading").show();
					
				},
				success: function(response) {
					
					// Hide ajax loading image
					jQuery("#js-mollie-ajax-loading").hide();
					
					if(!response.success) {
						jQuery("#js-mollieideal-bank-id").hide();
						jQuery("#js-mollie-ideal-alert").html(response.text).show();
					} else {
						// Set the URL to Mollie and show the button
						jQuery("#js-continue-mollie").attr("href", response.data.url).show();
					}
					
				}
					
			});
			
		} else { // Hide the button
			
			jQuery("#js-continue-mollie").attr("href", "#").hide();
		}
		
	});
});
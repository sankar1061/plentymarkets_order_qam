$(document).ready(function() {
	$("form[name=nn_invoice_form]").submit(function(evt) {
		if ((($("#nn_invoice_birthday").val() == 'undefine') || ($("#nn_invoice_birthday").val() == ''))) {
			evt.preventDefault();    
			alert($("#nn_invoice_valid_dob_msg").val());
		}
	});
}); 

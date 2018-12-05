/*
 * Novalnet Direct Debit SEPA Script
 * By Novalnet AG (https://www.novalnet.de)
 * Copyright (c) Novalnet AG
*/
var $ = jQuery.noConflict();
$( function() {
	$('#novalnet_sepa_confirm_id').attr('checked',false);
        $('#nn_sepa_panhash').attr('value','');
        $('#sepa_bic').attr('value','');
        $('#sepa_iban').attr('value','');
    $("form[name=sepa_form]").submit(function(evt) {
        if ($("#novalnet_sepa_confirm_id").length > 0 && $("#novalnet_sepa_confirm_id").is(":checked") == false) {
            evt.preventDefault();
            alert($("#nn_sepa_confirm_iban_bic_msg").val());
       } else if (($("#nn_sepa_birthday")) && (($("#nn_sepa_birthday").val() == 'undefine') || ($("#nn_sepa_birthday").val() == ''))) {
	  evt.preventDefault();    
	  alert($("#nn_sepa_valid_dob_msg").val());
       }
    });
    $("#novalnet_sepa_confirm_id").click(function() {
        if ($("#novalnet_sepa_confirm_id").is(":checked") == true) {
            ibanbic_call();
        } else {
            hide_bank_details();
        }
    });
	$("#sepa_bic, #sepa_iban, #sepa_cardholder").change(function() {
            hide_bank_details();
    });
    assignValue();
    $('#first_name, #last_name,#email-5, #email, #email-Primary, #street_address-1, #street_address, #street_address-Primary,#postal_code-1, #postal_code, #postal_code-Primary, #city-1, #city, #city-primary, #city-1, #city, #city-primary, #country-1,#country,#country-primary').change(function() {
        assignValue();
    });
    $('#novalnet_sepa_confirm_id').attr('checked',false);
});

function ibanbic_call(){

    if (document.getElementById('novalnet_sepa_confirm_id').checked == true){
        $('#novalnet_sepa_confirm_id').attr('disabled','disabled');
        if ($('#sepa_cardholder').val() == '' || $('#sepa_iban').val() == '' || $('#sepa_country').val() == ''){
            alert($("#nn_sepa_valid_message").val());
            hide_bank_details();
            $("#novalnet_sepa_confirm_id").removeAttr('disabled');
            document.getElementById('novalnet_sepa_confirm_id').checked = false;
            return false;
        }
        var vendor_id       = $('#nn_vendor').val();
        var vendor_authcode = $('#nn_auth_code').val();
        var bank_country   = $('#sepa_country').val();
        var account_holder = $('#sepa_cardholder').val();
        var bank_account   = $('#sepa_iban').val();
        var bank_code      = $('#sepa_bic').val();
        var unique_id      = $('#nn_sepa_uniqueid').val();
        account_holder = $.trim(account_holder);
        if (vendor_id == '' || vendor_authcode == '') {
            alert( $( '#nn_sepa_merchant_valid_message' ).val() );
            hide_bank_details();
            return false;
        }
        if(bank_country == ''){
             alert( $( '#nn_sepa_countryerror_msg' ).val() );
             return false;
        }
        if(bank_country == 'DE' && bank_code == '' && isNaN(bank_account)){
            bank_code = '123456';
        }
        else if(bank_country == 'DE' && !isNaN(bank_code) && isNaN(bank_account)){
            alert( $( '#nn_sepa_valid_message' ).val() );
            hide_bank_details();
            return false;
        }
        if(bank_country != 'DE' && (bank_code == '' || ((!isNaN(bank_account) && isNaN(bank_code))
            || (isNaN(bank_account) && !isNaN(bank_code)))) ){
            alert($( '#nn_sepa_valid_message' ).val());
            hide_bank_details();
            return false;
        }
        else if((bank_country == 'DE' && (!isNaN(bank_account) && (bank_code == '' || isNaN(bank_code))))){
            alert($( '#nn_sepa_valid_message' ).val() );
            hide_bank_details();
            return false;
        }

        if (is_numeric(bank_account) && is_numeric(bank_code)){

            var iban_bic = {'account_holder':account_holder,'bank_account':bank_account,'bank_code':bank_code,'vendor_id':vendor_id,'vendor_authcode':vendor_authcode,'bank_country':bank_country,'unique_id':unique_id,'get_iban_bic':1}
            iban_bic = $.param(iban_bic);

            sent_xdomainreq_sepa( iban_bic, 'nnsepa_iban' , null);
        }
        else {
            var hash_gen = {'account_holder':account_holder,'bank_account':'','bank_code':'','vendor_id':vendor_id,'vendor_authcode':vendor_authcode,'bank_country':bank_country,'unique_id':unique_id,'sepa_data_approved':1,'mandate_data_req':1,'iban':bank_account,'bic':bank_code}
            hash_gen = $.param(hash_gen);
            sent_xdomainreq_sepa( hash_gen, 'nnsepa_hash' , null);
        }

        document.getElementById('sepa_bic_gen').value = bank_code;
        document.getElementById('sepa_iban_gen').value = bank_account;
    } else {
        cancel_ibanbic_values();
    }
}

function cancel_ibanbic_values(){
    document.onkeydown = function(evt) {
        return true;
    };
    document.getElementById('novalnet_sepa_confirm_id').checked = false;
    document.getElementById('nn_sepa_ibanbic_confirm_id').value = 0;
    $('#novalnet_sepa_confirm_id').removeAttr('disabled');
    hide_bank_details();
}

function is_numeric(ele){
    return (/^[0-9]+$/.test(ele));
}

function hide_bank_details() {
    $('#novalnet_sepa_iban_span').hide();
    $('#novalnet_sepa_bic_span').hide();
    $('#novalnet_sepa_confirm_id').attr('checked',false);
    $("#novalnet_sepa_confirm_id").removeAttr('disabled');
    $("#nn_sepa_ibanbic_confirm_id").val(0);
}

function sent_xdomainreq_sepa(qryString, ptype, from_iban){
    $('#sepaloader').css('display', 'block');
    var nnurl = "https://payport.novalnet.de/sepa_iban";
        if ('XDomainRequest' in window && window.XDomainRequest !== null) {
            var xdr = new XDomainRequest();
            xdr.open('POST', nnurl);
            xdr.onload = function () {
                return check_result_sepa(this.responseText, ptype, qryString , from_iban);
            }
            xdr.onerror = function() {
                _result = false;
            };
            xdr.send(qryString);
        }
        else{
            var xmlhttp = (window.XMLHttpRequest) ? new XMLHttpRequest() : new ActiveXObject("Microsoft.XMLHTTP");
            xmlhttp.onreadystatechange=function(){
                if (xmlhttp.readyState==4 && xmlhttp.status==200){
                    return check_result_sepa( xmlhttp.responseText, ptype, qryString , from_iban);
                }
            }
            xmlhttp.open("POST",nnurl,true);
            xmlhttp.setRequestHeader("Content-type","application/x-www-form-urlencoded");
            xmlhttp.send(qryString);
        }
    }


    function check_result_sepa(response, ptype, qryString, from_iban){
        var data = $.parseJSON(response);
        $('#novalnet_sepa_confirm_id').removeAttr('disabled');
        $('#sepaloader').css('display', 'none');
        if(data.hash_result != 'success'){
            alert(data.hash_result);
            return false;
        }
      switch (ptype){
        case 'nnsepa_iban':
            if(data.IBAN =='' || data.BIC == ''){
                alert( $( '#nn_sepa_valid_message').val() );
                hide_bank_details();
                return false;
            }
            document.getElementById('sepa_bic_gen').value = data.BIC;
            document.getElementById('sepa_iban_gen').value = data.IBAN;
            $("#novalnet_sepa_iban_span").text('IBAN : '+ data.IBAN);
            $("#novalnet_sepa_bic_span").text('BIC : '+ data.BIC);
            $('#novalnet_sepa_iban_span').show();
            $('#novalnet_sepa_bic_span').show();

            hash_data = qryString.replace('&get_iban_bic=1','') + '&sepa_data_approved=1&mandate_data_req=1&iban=' + data.IBAN + '&bic=' + data.BIC ;
            sent_xdomainreq_sepa(hash_data, 'nnsepa_hash', null);
          break;
        case 'nnsepa_hash':

            document.getElementById('nn_sepa_hash').value = data.sepa_hash;
          break;
      }
    }

function validateSpecialChars(input_val){
    var re = /[\/\\#,+@!^()$~%.":*?<>{}]/g;
    return re.test(input_val);
}

function disable_background_events(){
    document.onkeydown = function(evt) {
        var charCode = (evt.which) ? evt.which : evt.keyCode;
        if ((evt.ctrlKey == true && charCode == 114)|| charCode == 116) {
          return true;
        }
        return false;
    };
}

function on_change(){
    $('#novalnet_sepa_confirm_id').attr('checked',false);
    $('#nn_sepa_panhash').val ='';
    hide_bank_details();
}

function normalizeDate(input) {
  if(input != 'undefined' && input != '') {
    var parts = input.split('-');

    return (parts[2] < 10 ? '0' : '') + Number(parts[2]) + '.'
      + (parts[1] < 10 ? '0' : '') + Number(parts[1]) + '.'
      + parseInt(parts[0]);
  }
}

function keyLock(evt) {
    var charCode = (evt.which) ? evt.which : evt.keyCode
    if ((evt.ctrlKey == true && charCode == 114)|| charCode == 116) {
      return true;
    }
    return false;
}
function ibanbic_validate(event, key){
    var keycode = ('which' in event) ? event.which : event.keyCode;
    var reg = /^(?:[A-Za-z0-9]+$)/;
    if(key == 'sepa_cardholder') var reg = /^(?:[a-zA-Z\s\&\-\.]+$)/;

    return (reg.test(String.fromCharCode(keycode)) || keycode == 0 || keycode == 8 || (event.ctrlKey == true && keycode == 114))? true : false;
 }

function assignValue() {
    var address=''; var email=''; var zip='';
    var city='';var country='';
     var name='';
    if ($('#first_name').length > 0)  { name= name + $('#first_name').val(); }
    if ($('#last_name').length > 0)  { name = name +' ' + $('#last_name').val(); }

    if ($('#street_address-1').length > 0)  { address= $('#street_address-1').val(); }
    else if ($('#street_address-Primary').length > 0)  { address= $('#street_address-Primary').val();}
    else if ($('#street_address').length > 0)  { address= $('#street_address').val(); }

    if ($('#city-1').length > 0)  { city= $("#city-1").val();}
    else if ($('#city').length > 0)  {city= $("#city").val();}
    else if ($('#city-primary').length > 0)  { city= $("#city-primary").val(); }

    if ($('#country-1').length > 0)  { country= $("#country-1").val(); }
    else if ($('#country').length > 0)  {country= $("#country").val();}
    else if ($('#country-primary').length > 0)  { country= $("#country-primary").val(); }

    if ($('#postal_code-1').length > 0)  { zip= $("#postal_code-1").val();}
    else if ($('#postal_code-Primary').length > 0) { zip= $("#postal_code-Primary").val();}
    else if ($('#postal_code').length > 0) { zip= $("#postal_code").value; }

    if ($('#email-5').length > 0)  { email= $("#email-5").val();}
    else if($('#email-Primary').length > 0) { email= $("#email-Primary").val();}
    else if($('#email').length > 0) {email= $("#email").val();}
    $('#sepa_cardholder').val(name);

    $.ajax({
        type: 'POST',
        url: $("#url_country").val(),
        data: {'country_id':country},
        dataType: 'json',
        success: function(data, status) {
            $("#sepa_country").val(data.iso_code);
            $( "#sepa_country_content span:first-child" ).text(data.name);
        }
    });
}

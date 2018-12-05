var $ = jQuery.noConflict();
var nnButton, nnIfrmButton, iframeWindow, targetOrigin;
nnButton = nnIfrmButton = iframeWindow = targetOrigin = false;

function initIframe()
{
    var request = {
        callBack: 'createElements',
        customStyle: {
            labelStyle: $('#nn_cc_standard_style_label').val(),
            inputStyle: $('#nn_cc_standard_style_input').val(),
            styleText: $('#nn_cc_standard_style_css').val(),
            card_holder: {
                labelStyle: $('#nn_cc_holder_label_css').val(),
                inputStyle: $('#nn_cc_holder_field_css').val(),
            },
            card_number: {
                labelStyle: $('#nn_cc_number_label_css').val(),
                inputStyle: $('#nn_cc_number_field_css').val(),
            },
            expiry_date: {
                labelStyle: $('#nn_cc_date_label_css').val(),
                inputStyle: $('#nn_cc_date_field_css').val(),
            },
            cvc: {
                labelStyle: $('#nn_cc_cvc_label_css').val(),
                inputStyle: $('#nn_cc_cvc_field_css').val(),
            }
        }
    };

    var iframe = $('#nniframe')[0];
    iframeWindow = iframe.contentWindow ? iframe.contentWindow : iframe.contentDocument.defaultView;
    targetOrigin = 'https://secure.novalnet.de';
    iframeWindow.postMessage(JSON.stringify(request), targetOrigin);
}





function getHash(e)
{
	if($('#nn_pan_hash').val().trim() == '') {
	//e.preventDefault();
	iframeWindow.postMessage(
		JSON.stringify(
			{
			'callBack': 'getHash',
			}
		), targetOrigin
	);
	}
}

function reSize()
{
    if ($('#nniframe').length > 0) {
        var iframe = $('#nniframe')[0];
        iframeWindow = iframe.contentWindow ? iframe.contentWindow : iframe.contentDocument.defaultView;
        targetOrigin = 'https://secure.novalnet.de/';
        iframeWindow.postMessage(JSON.stringify({'callBack' : 'getHeight'}), targetOrigin);
    }
}

function novalnetCcIframe()
{
    $('#cc_loading').hide();
}

window.addEventListener(
    'message', function (e) {
    var data = (typeof e.data === 'string') ? eval('(' + e.data + ')') : e.data;
    if (e.origin === 'https://secure.novalnet.de') {
        if (data['callBack'] == 'getHash') {
            if (data['error_message'] != undefined) {
                alert($('<textarea />').html(data['error_message']).text());
            } else {
		$('#nn_pan_hash').val(data['hash']);
                $('#unique_id').val(data['unique_id']);
                $('#cc_form').submit();
            }
        }

        if (data['callBack'] == 'getHeight') {
            $('#nniframe').attr('height', data['contentHeight']);
        }
    }
    }, false
);

$(document).ready(
    function () {
    $(window).resize(
        function() {
        reSize();
        }
    );
    }
);


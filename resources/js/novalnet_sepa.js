jQuery(document).ready( function() {
jQuery('#nn_sepa_iban').on('keypress',function ( event ) {
            var keycode = ( 'which' in event ) ? event.which : event.keyCode;                                        
                if(event.target.value.length < 2 ) {
                    reg     = /^(?:[a-zA-Z]+$)/;
                    return reg.test( String.fromCharCode( keycode ) );                    
                }
                else {
                    reg     = /^(?:[0-9]+$)/;                    
                    return reg.test( String.fromCharCode( keycode ) );                                        
                }                
            
        });
});

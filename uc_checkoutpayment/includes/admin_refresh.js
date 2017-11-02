var interval = 1000;
var action = document.getElementById('cko-action').value;
var contextid = document.getElementById('order_id').value;

function loadDoc() 
{
    var xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        'use strict';
        if (this.readyState === 4 && this.status === 200) {
            setTimeout(loadDoc, interval);
            if (this.responseText === 'true') {
                location.reload();
            }
        }
    };
    xhttp.open('GET', '/?q=/uc_checkoutpayment/admin/' + action + '/' + contextid, true);
    xhttp.send();
}

if (action === 'capture' || action === 'refund') {
    setTimeout(loadDoc, interval);
}

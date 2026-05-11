/* global DDBGIFT, wpApiFetch */
(function () {
    if (typeof DDBGIFT === 'undefined' || typeof wp === 'undefined' || typeof wp.apiFetch === 'undefined' || typeof wp.i18n === 'undefined') {
        return;
    }

    var __ = wp.i18n.__;

    function renderResult(container, data) {
        if (!data.success) {
            container.innerHTML = '<div class="ddb-giftcard-redeem__message ddb-giftcard-redeem__message--error">' + (data.message || DDBGIFT.i18n.error) + '</div>';
            return;
        }

        var html = '<div class="ddb-giftcard-redeem__message ddb-giftcard-redeem__message--success">' + DDBGIFT.i18n.success + '</div>';
        html += '<dl class="ddb-giftcard-redeem__details">';

        if (data.data.amount) {
            html += '<div><dt>' + __('Waarde', 'ddb') + '</dt><dd>' + data.data.amount + '</dd></div>';
        }
        if (data.data.product_name) {
            html += '<div><dt>' + __('Beleving', 'ddb') + '</dt><dd>' + data.data.product_name + '</dd></div>';
        }
        if (data.data.expires) {
            html += '<div><dt>' + __('Geldig t/m', 'ddb') + '</dt><dd>' + data.data.expires + '</dd></div>';
        }
        if (data.data.status) {
            html += '<div><dt>' + __('Status', 'ddb') + '</dt><dd>' + data.data.status + '</dd></div>';
        }
        if (data.data.redeem_url) {
            html += '<div><dt>' + __('Verzilveren', 'ddb') + '</dt><dd><a href="' + data.data.redeem_url + '">' + data.data.redeem_url + '</a></dd></div>';
        }

        html += '</dl>';
        container.innerHTML = html;
    }

    function submitForm(event) {
        event.preventDefault();

        var form = event.target;
        var container = form.closest('.ddb-giftcard-redeem').querySelector('.ddb-giftcard-redeem__result');
        var code = form.querySelector('input[name="code"]').value.trim();
        var email = form.querySelector('input[name="email"]').value.trim();

        if (!code) {
            container.innerHTML = '<div class="ddb-giftcard-redeem__message ddb-giftcard-redeem__message--error">' + __('Voer een geldige code in.', 'ddb') + '</div>';
            return;
        }

        container.innerHTML = '<div class="ddb-giftcard-redeem__message">' + DDBGIFT.i18n.loading + '</div>';

        wp.apiFetch({
            path: DDBGIFT.restUrl.replace(window.location.origin, ''),
            method: 'POST',
            data: {
                code: code,
                email: email
            },
            headers: {
                'X-WP-Nonce': DDBGIFT.nonce
            }
        }).then(function (response) {
            renderResult(container, {
                success: true,
                data: response
            });
        }).catch(function (error) {
            var message = error && error.message ? error.message : DDBGIFT.i18n.error;
            renderResult(container, {
                success: false,
                message: message
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var wrappers = document.querySelectorAll('.ddb-giftcard-redeem__form');
        wrappers.forEach(function (form) {
            form.addEventListener('submit', submitForm);
        });
    });
}());

(function () {
    function handleSubmit(event) {
        event.preventDefault();
        var form = event.currentTarget;
        var endpoint = form.getAttribute('data-endpoint') || '/wp-json/bsp/v1/booking/request';
        var formData = new FormData(form);
        var payload = {};
        formData.forEach(function (value, key) {
            payload[key] = value;
        });

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                var calendar = document.querySelector('#bsp-calendar');
                if (calendar) {
                    var wrapper = document.createElement('div');
                    wrapper.className = 'bsp-calendar-response';

                    var payload = document.createElement('pre');
                    payload.textContent = JSON.stringify(data, null, 2);
                    wrapper.appendChild(payload);

                    if (data && data.payment_request && data.payment_request.url) {
                        var paymentBox = document.createElement('div');
                        paymentBox.className = 'bsp-payment-request';

                        var heading = document.createElement('div');
                        heading.className = 'bsp-payment-request-heading';
                        heading.textContent = 'Betaalverzoek klaar:';
                        paymentBox.appendChild(heading);

                        var link = document.createElement('a');
                        link.href = data.payment_request.url;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.textContent = 'Open betaalverzoek';
                        paymentBox.appendChild(link);

                        if (data.payment_request.status) {
                            var status = document.createElement('span');
                            status.className = 'bsp-payment-request-status';
                            status.textContent = 'Status: ' + data.payment_request.status;
                            paymentBox.appendChild(status);
                        }

                        wrapper.appendChild(paymentBox);
                    }

                    calendar.innerHTML = '';
                    calendar.appendChild(wrapper);
                }

                var message = 'Aanvraag verzonden.';
                if (data && data.payment_request && data.payment_request.url) {
                    message += '\nBetaal nu via het verzonden verzoek.';
                }

                alert(message);
            })
            .catch(function (error) {
                console.error('Booking request failed', error);
                alert('Er is een fout opgetreden bij het versturen.');
            });
    }

    function init() {
        var form = document.querySelector('#bsp-booking-form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', handleSubmit);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

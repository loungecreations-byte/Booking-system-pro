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
                alert('Aanvraag verzonden: ' + JSON.stringify(data));
                var calendar = document.querySelector('#bsp-calendar');
                if (calendar) {
                    calendar.textContent = JSON.stringify(data, null, 2);
                }
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

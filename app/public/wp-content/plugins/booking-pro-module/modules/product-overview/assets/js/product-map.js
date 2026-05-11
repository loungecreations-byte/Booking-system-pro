(function (global) {
    'use strict';

    function create(container, options) {
        options = options || {};

        if (!container || typeof global.L === 'undefined') {
            return stub();
        }

        const L = global.L;
        const defaultCenter = options.defaultCenter || [52.370216, 4.895168];
        const map = L.map(container).setView(defaultCenter, 12);
        const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 18,
        });
        tiles.addTo(map);

        const markers = L.layerGroup().addTo(map);

        function setProducts(products) {
            markers.clearLayers();

            if (!Array.isArray(products) || !products.length) {
                container.classList.add('is-empty');
                return;
            }

            container.classList.remove('is-empty');

            const bounds = [];

            products.forEach(function (product) {
                const coords = resolveCoordinates(product);
                if (!coords) {
                    return;
                }

                const marker = L.marker(coords).addTo(markers);
                marker.bindPopup(buildPopup(product, options.strings));
                marker.on('click', function () {
                    highlightCard(product.id, options.componentId);
                });
                bounds.push(coords);
            });

            if (bounds.length > 1) {
                map.fitBounds(bounds, { padding: [28, 28] });
            } else if (bounds.length === 1) {
                map.setView(bounds[0], 14);
            }
        }

        function refresh() {
            setTimeout(function () {
                map.invalidateSize();
            }, 35);
        }

        function destroy() {
            map.remove();
        }

        return {
            setProducts: setProducts,
            refresh: refresh,
            destroy: destroy,
        };
    }

    function stub() {
        return {
            setProducts: function () {},
            refresh: function () {},
            destroy: function () {},
        };
    }

    function resolveCoordinates(product) {
        if (!product || !product.coordinates) {
            return null;
        }

        const lat = Number(product.coordinates.lat);
        const lng = Number(product.coordinates.lng);

        if (!isFinite(lat) || !isFinite(lng)) {
            return null;
        }

        return [lat, lng];
    }

    function buildPopup(product, strings) {
        strings = strings || {};

        const title = product.title || '';
        const type = product.type && product.type.label ? product.type.label : '';
        const price = product.price && product.price.formatted ? product.price.formatted : '';
        const duration = product.duration && product.duration.formatted ? product.duration.formatted : '';
        const link = product.permalink || '#';

        var html = '<div class="sbdp-po-popup">';
        html += '<h4>' + escapeHtml(title) + '</h4>';
        if (type) {
            html += '<p>' + escapeHtml(type) + '</p>';
        }
        if (duration) {
            html += '<p>' + escapeHtml(strings.durationLabel || 'Duur') + ': ' + escapeHtml(duration) + '</p>';
        }
        if (price) {
            html += '<p>' + escapeHtml(strings.priceLabel || 'Prijs') + ': ' + price + '</p>';
        }
        html += '<a href="' + escapeAttribute(link) + '" target="_self" rel="bookmark">' + escapeHtml(strings.viewDetails || 'Bekijk') + '</a>';
        html += '</div>';

        return html;
    }

    function highlightCard(id, componentId) {
        if (!id) {
            return;
        }

        var selector = '[data-product-id="' + id + '"]';
        if (componentId) {
            selector = '[data-component-id="' + componentId + '"] ' + selector;
        }

        var card = document.querySelector(selector);
        if (!card) {
            return;
        }

        card.classList.add('is-highlighted');
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(function () {
            card.classList.remove('is-highlighted');
        }, 1200);
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value || '';
        return div.innerHTML;
    }

    function escapeAttribute(value) {
        const div = document.createElement('div');
        div.textContent = value || '';
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    global.SBDPProductMap = {
        create: create,
    };
})(window);

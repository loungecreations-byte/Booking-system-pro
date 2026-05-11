(function () {
    'use strict';

    const COMPONENT_SELECTOR = '[data-component="sbdp-product-overview"]';

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll(COMPONENT_SELECTOR).forEach(function (root) {
            initialiseComponent(root);
        });
    });

    function initialiseComponent(root) {
        const config = getConfig(root);
        const state = {
            view: config.defaultView || 'grid',
            filters: Object.assign({}, config.filters || {}),
            pagination: Object.assign(
                { page: (config.pagination && config.pagination.page) || 1, perPage: (config.pagination && config.pagination.perPage) || (config.filters && config.filters.per_page) || 12 },
                config.pagination || {}
            ),
            products: Array.isArray(config.products) ? config.products : [],
            meta: config.meta || {},
            mapRequested: Boolean(config.map && config.map.enabled),
            mapEligible: Boolean(config.mapEnabled),
        };

        const nodes = {
            form: root.querySelector('[data-role="filters"]'),
            resetButton: root.querySelector('[data-action="reset-filters"]'),
            grid: root.querySelector('[data-role="grid"]'),
            map: root.querySelector('[data-role="map"]'),
            status: root.querySelector('[data-role="status"]'),
            viewButtons: root.querySelectorAll('[data-action="set-view"]'),
            viewToggle: root.querySelector('[data-role="view-toggle"]'),
            mapNote: root.querySelector('[data-role="map-note"]'),
        };

        renderProducts(nodes.grid, state.products, config);
        toggleView(root, nodes, state.view);

        const mapState = { api: null };
        const initialSync = syncMapAvailability(
            root,
            nodes,
            state,
            config,
            Boolean(state.meta && state.meta.hasCoordinates),
            mapState.api
        );
        mapState.api = initialSync.mapApi;

        if (nodes.form) {
            nodes.form.addEventListener('submit', function (event) {
                event.preventDefault();
                state.filters = readFilters(nodes.form, config);
                state.pagination.page = 1;
                requestProducts(root, config, state, nodes, mapState);
            });
        }

        if (nodes.resetButton && nodes.form) {
            nodes.resetButton.addEventListener('click', function () {
                resetFilters(nodes.form);
                state.filters = readFilters(nodes.form, config);
                state.pagination.page = 1;
                requestProducts(root, config, state, nodes, mapState);
            });
        }

        if (nodes.viewButtons && nodes.viewButtons.length) {
            nodes.viewButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const view = button.getAttribute('data-view') || 'grid';
                    if (view === 'map' && !state.mapEligible) {
                        return;
                    }
                    state.view = view;
                    toggleView(root, nodes, view);
                    if (view === 'map' && mapState.api) {
                        mapState.api.refresh();
                    }
                });
            });
        }
    }

    function getConfig(root) {
        try {
            const json = root.getAttribute('data-config');
            if (!json) {
                return {};
            }

            return JSON.parse(json);
        } catch (error) {
            console.warn('[SBDP] Product overview config parse failed.', error); // eslint-disable-line no-console
            return {};
        }
    }

    function readFilters(form, config) {
        const data = new window.FormData(form);
        const filters = {
            type: data.get('type') || '',
            date: data.get('date') || '',
            min_price: data.get('min_price') || '',
            max_price: data.get('max_price') || '',
            per_page: (config.filters && config.filters.per_page) || 12,
        };

        Object.keys(filters).forEach(function (key) {
            if (filters[key] === null) {
                filters[key] = '';
            }
            if (typeof filters[key] === 'string') {
                filters[key] = filters[key].trim();
            }
        });

        return filters;
    }

    function resetFilters(form) {
        form.reset();
    }

    function toggleView(root, nodes, view) {
        if (nodes.grid) {
            nodes.grid.classList.toggle('is-hidden', view === 'map');
        }

        if (nodes.map) {
            nodes.map.classList.toggle('is-active', view === 'map');
            nodes.map.setAttribute('aria-hidden', view === 'map' ? 'false' : 'true');
        }

        if (nodes.viewButtons && nodes.viewButtons.length) {
            nodes.viewButtons.forEach(function (button) {
                const target = button.getAttribute('data-view') || 'grid';
                button.classList.toggle('is-active', target === view);
            });
        }

        root.setAttribute('data-default-view', view);
    }

    function setStatus(node, message, isError) {
        if (!node) {
            return;
        }

        node.textContent = message || '';
        node.classList.toggle('is-error', Boolean(isError));
    }

    function clearStatus(node) {
        if (node) {
            node.textContent = '';
            node.classList.remove('is-error');
        }
    }

    function requestProducts(root, config, state, nodes, mapState) {
        if (!config.ajax || !config.ajax.url) {
            setStatus(nodes.status, 'AJAX endpoint ontbreekt.', true);
            return;
        }

        setStatus(nodes.status, config.strings.loading || 'Loading…', false);

        const params = new window.URLSearchParams();
        params.append('action', config.ajax.action || 'bmp_fetch_products');

        if (config.ajax.nonce) {
            params.append('nonce', config.ajax.nonce);
        }

        params.append('page', state.pagination.page || 1);
        params.append('per_page', state.pagination.perPage || state.filters.per_page || 12);

        Object.keys(state.filters).forEach(function (key) {
            const value = state.filters[key];
            if (value !== undefined && value !== null && value !== '') {
                params.append(key, value);
            }
        });

        window
            .fetch(config.ajax.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: params.toString(),
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Server reageerde niet correct.');
                }

                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.success !== true) {
                    throw new Error('Geen resultaten gevonden.');
                }

                const data = payload.data || {};
                state.products = Array.isArray(data.products) ? data.products : [];
                state.pagination = Object.assign(state.pagination, data.pagination || {});
                state.meta = data.meta || {};

                renderProducts(nodes.grid, state.products, config);

                const syncResult = syncMapAvailability(
                    root,
                    nodes,
                    state,
                    config,
                    Boolean(state.meta.hasCoordinates),
                    mapState.api
                );
                mapState.api = syncResult.mapApi;

                clearStatus(nodes.status);
            })
            .catch(function (error) {
                setStatus(nodes.status, error.message || 'Er ging iets mis.', true);
            });
    }

    function syncMapAvailability(root, nodes, state, config, hasCoordinates, mapApi) {
        state.mapEligible = Boolean(state.mapRequested && hasCoordinates);

        if (!state.mapEligible) {
            state.view = 'grid';
            toggleView(root, nodes, 'grid');
        }

        if (nodes.viewToggle) {
            nodes.viewToggle.classList.toggle('is-disabled', !state.mapEligible);
        }

        if (nodes.viewButtons && nodes.viewButtons.length) {
            nodes.viewButtons.forEach(function (button) {
                const view = button.getAttribute('data-view');
                if (view === 'map') {
                    button.classList.toggle('is-disabled', !state.mapEligible);
                    button.disabled = !state.mapEligible;
                }
            });
        }

        toggleMapNote(nodes.mapNote, state.mapRequested && !state.mapEligible);

        if (!state.mapEligible && mapApi) {
            mapApi.destroy();
            mapApi = null;
        } else if (state.mapEligible && !mapApi && nodes.map && window.SBDPProductMap) {
            nodes.map.dataset.emptyText = config.strings.empty || '';
            mapApi = window.SBDPProductMap.create(nodes.map, {
                componentId: config.componentId,
                strings: config.strings,
            });
            mapApi.setProducts(state.products);
        } else if (state.mapEligible && mapApi) {
            mapApi.setProducts(state.products);
            mapApi.refresh();
        }

        if (nodes.map) {
            nodes.map.classList.toggle('is-active', state.mapEligible && state.view === 'map');
            nodes.map.setAttribute('aria-hidden', state.view === 'map' && state.mapEligible ? 'false' : 'true');
        }

        return { mapApi: mapApi };
    }

    function toggleMapNote(node, isVisible) {
        if (!node) {
            return;
        }

        if (isVisible) {
            node.removeAttribute('hidden');
        } else {
            node.setAttribute('hidden', 'hidden');
        }
    }

    function renderProducts(grid, products, config) {
        if (!grid) {
            return;
        }

        grid.innerHTML = '';

        if (!Array.isArray(products) || products.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'sbdp-po__empty';
            empty.textContent = (config.strings && config.strings.empty) || 'Geen resultaten.';
            grid.appendChild(empty);
            return;
        }

        products.forEach(function (product) {
            grid.appendChild(buildCard(product, config));
        });
    }

    function buildCard(product, config) {
        const card = document.createElement('article');
        card.className = 'sbdp-po__card';
        card.dataset.productId = String(product.id || '');
        card.dataset.type = (product.type && product.type.slug) || '';

        if (product.coordinates) {
            if (product.coordinates.lat !== undefined) {
                card.dataset.lat = String(product.coordinates.lat);
            }
            if (product.coordinates.lng !== undefined) {
                card.dataset.lng = String(product.coordinates.lng);
            }
        }

        if (product.image) {
            const media = document.createElement('div');
            media.className = 'sbdp-po__media';
            media.style.backgroundImage = "url('" + product.image + "')";
            card.appendChild(media);
        }

        const body = document.createElement('div');
        body.className = 'sbdp-po__card-content';
        card.appendChild(body);

        if (product.type && product.type.label) {
            const type = document.createElement('p');
            type.className = 'sbdp-po__type';
            type.textContent = product.type.label;
            body.appendChild(type);
        }

        const title = document.createElement('h3');
        title.className = 'sbdp-po__title';
        title.textContent = product.title || '';
        body.appendChild(title);

        if (product.excerpt) {
            const excerpt = document.createElement('p');
            excerpt.className = 'sbdp-po__excerpt';
            excerpt.textContent = product.excerpt;
            body.appendChild(excerpt);
        }

        if (product.location) {
            const location = document.createElement('p');
            location.className = 'sbdp-po__location';
            const label = document.createElement('span');
            label.className = 'sbdp-po__location-label';
            label.textContent = (config.strings.locationLabel || 'Locatie') + ':';
            const value = document.createElement('span');
            value.textContent = product.location;
            location.appendChild(label);
            location.appendChild(value);
            body.appendChild(location);
        }

        const meta = document.createElement('div');
        meta.className = 'sbdp-po__meta';
        body.appendChild(meta);

        if (product.duration && product.duration.formatted) {
            const badge = document.createElement('span');
            badge.className = 'sbdp-po__badge';
            badge.textContent = (config.strings.durationLabel || 'Duur') + ': ' + product.duration.formatted;
            meta.appendChild(badge);
        }

        if (product.price && product.price.formatted) {
            const badge = document.createElement('span');
            badge.className = 'sbdp-po__badge';
            badge.innerHTML = (config.strings.priceLabel || 'Prijs') + ': ' + product.price.formatted;
            meta.appendChild(badge);
        }

        const link = document.createElement('a');
        link.className = 'sbdp-po__button-link';
        link.href = product.permalink || '#';
        link.textContent = config.strings.viewDetails || 'Bekijk';
        body.appendChild(link);

        return card;
    }
})();

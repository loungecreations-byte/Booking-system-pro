/* global jQuery, window */
(function ($) {
    'use strict';

    const bootstrapData = window.SBDPSalesHubData || {};
    const state = $.extend(true, {}, bootstrapData.rules || {});

    ensureDefaults();

    const container = $('#sbdp-saleshub-app');
    if (container.length === 0) {
        return;
    }

    renderShell();
    renderAll();

    container.on('click', '[data-tab-target]', function (event) {
        event.preventDefault();

        const target = $(this).data('tab-target');
        setActiveTab(String(target));
    });

    container.on('change input', '.js-global-field', function () {
        const field = String($(this).data('field'));
        updateGlobal(field, $(this));
    });

    container.on('click', '.js-channel-add', function (event) {
        event.preventDefault();
        state.channels.push(defaultChannelRule());
        renderChannels();
    });

    container.on('click', '.js-channel-remove', function (event) {
        event.preventDefault();
        const index = Number($(this).data('index'));
        state.channels.splice(index, 1);
        renderChannels();
    });

    container.on('change input', '.js-channel-field', function () {
        const index = Number($(this).data('index'));
        const field = String($(this).data('field'));
        updateChannelField(index, field, $(this));
    });

    container.on('click', '.js-coupon-add', function (event) {
        event.preventDefault();
        state.coupons.push(defaultCoupon());
        renderCoupons();
    });

    container.on('click', '.js-coupon-remove', function (event) {
        event.preventDefault();
        const index = Number($(this).data('index'));
        state.coupons.splice(index, 1);
        renderCoupons();
    });

    container.on('change input', '.js-coupon-field', function () {
        const index = Number($(this).data('index'));
        const field = String($(this).data('field'));
        updateCouponField(index, field, $(this));
    });

    container.on('click', '.js-yield-add', function (event) {
        event.preventDefault();
        state.yield_rules.push(defaultYieldRule());
        renderYield();
    });

    container.on('click', '.js-yield-remove', function (event) {
        event.preventDefault();
        const index = Number($(this).data('index'));
        state.yield_rules.splice(index, 1);
        renderYield();
    });

    container.on('change input', '.js-yield-field', function () {
        const index = Number($(this).data('index'));
        const field = String($(this).data('field'));
        updateYieldField(index, field, $(this));
    });

    $('#sbdp-saleshub-form').on('submit', function () {
        $('#sbdp_saleshub_rules').val(JSON.stringify(state));
    });

    function renderShell() {
        const template = `
            <div class="sbdp-saleshub-tabs">
                <nav class="sbdp-saleshub-tablist">
                    <a href="#" class="sbdp-saleshub-tab is-active" data-tab-target="global">${esc('Algemeen')}</a>
                    <a href="#" class="sbdp-saleshub-tab" data-tab-target="channels">${esc('Kanalen')}</a>
                    <a href="#" class="sbdp-saleshub-tab" data-tab-target="coupons">${esc('Kortingen')}</a>
                    <a href="#" class="sbdp-saleshub-tab" data-tab-target="yield">${esc('Rendement')}</a>
                </nav>
                <section class="sbdp-saleshub-panel is-active" data-panel="global">
                    <div class="sbdp-pane" id="sbdp-saleshub-global"></div>
                </section>
                <section class="sbdp-saleshub-panel" data-panel="channels">
                    <div class="sbdp-pane" id="sbdp-saleshub-channels"></div>
                </section>
                <section class="sbdp-saleshub-panel" data-panel="coupons">
                    <div class="sbdp-pane" id="sbdp-saleshub-coupons"></div>
                </section>
                <section class="sbdp-saleshub-panel" data-panel="yield">
                    <div class="sbdp-pane" id="sbdp-saleshub-yield"></div>
                </section>
            </div>
        `;

        container.html(template);
    }

    function renderAll() {
        renderGlobal();
        renderChannels();
        renderCoupons();
        renderYield();
    }

    function renderGlobal() {
        const pane = $('#sbdp-saleshub-global');
        const g = state.global;

        const html = `
            <table class="form-table">
                <tbody>
                    <tr>
                        <th>${esc('Basismarge (%)')}</th>
                        <td>
                            <input type="number" class="regular-text js-global-field" data-field="base_margin_pct" step="0.1" value="${esc(g.base_margin_pct)}">
                            <p class="description">${esc('Wordt toegepast op elke boekingsregel voordat kanaalaanpassingen ingaan.')}</p>
                        </td>
                    </tr>
                    <tr>
                        <th>${esc('Vaste servicetoeslag')}</th>
                        <td>
                            <input type="number" class="regular-text js-global-field" data-field="flat_service_fee" step="0.01" value="${esc(g.flat_service_fee)}">
                            <p class="description">${esc('Wordt als vast bedrag per boeking toegevoegd.')}</p>
                        </td>
                    </tr>
                    <tr>
                        <th>${esc('Weekendtoeslag (%)')}</th>
                        <td>
                            <input type="number" class="regular-text js-global-field" data-field="weekend_surcharge_pct" step="0.1" value="${esc(g.weekend_surcharge_pct)}">
                        </td>
                    </tr>
                    <tr>
                        <th>${esc('Minimumprijs')}</th>
                        <td>
                            <input type="number" class="regular-text js-global-field" data-field="minimum_price" step="0.01" value="${esc(g.minimum_price)}">
                            <p class="description">${esc('Totalen onder deze grens worden automatisch opgehoogd.')}</p>
                        </td>
                    </tr>
                    <tr>
                        <th>${esc('Maximale korting (%)')}</th>
                        <td>
                            <input type="number" class="regular-text js-global-field" data-field="maximum_discount_pct" step="0.1" value="${esc(g.maximum_discount_pct)}">
                            <p class="description">${esc('Gecombineerde kortingen uit coupons en rendement mogen dit percentage niet overschrijden.')}</p>
                        </td>
                    </tr>
                    <tr>
                        <th>${esc('Meerdere kortingscodes toestaan')}</th>
                        <td>
                            <label>
                                <input type="checkbox" class="js-global-field" data-field="allow_coupon_stacking" ${g.allow_coupon_stacking ? 'checked' : ''}>
                                ${esc('Sta meerdere kortingscodes per boeking toe wanneer beschikbaar.')}
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
        `;

        pane.html(html);
    }

    function renderChannels() {
        const pane = $('#sbdp-saleshub-channels');

        const rows = state.channels.map((rule, index) => `
            <tr>
                <td><input type="text" class="regular-text js-channel-field" data-field="channel" data-index="${index}" value="${esc(rule.channel)}" placeholder="web"></td>
                <td>
                    <select class="js-channel-field" data-field="kind" data-index="${index}">
                        ${selectOptions({
                            percent_markup: 'Opslag %',
                            fixed_markup: 'Opslag €',
                            percent_discount: 'Korting %',
                            fixed_discount: 'Korting €'
                        }, rule.kind)}
                    </select>
                </td>
                <td><input type="number" class="small-text js-channel-field" data-field="value" data-index="${index}" step="0.1" value="${esc(rule.value)}"></td>
                <td><input type="text" class="regular-text js-channel-field" data-field="products" data-index="${index}" value="${esc(listToString(rule.products))}" placeholder="123,456"></td>
                <td><input type="text" class="regular-text js-channel-field" data-field="outlets" data-index="${index}" value="${esc(listToString(rule.outlets))}" placeholder="10,11"></td>
                <td><input type="text" class="small-text js-channel-field" data-field="days" data-index="${index}" value="${esc(listToString(rule.days))}" placeholder="1-7"></td>
                <td>
                    <input type="date" class="js-channel-field" data-field="start" data-index="${index}" value="${esc(rule.date_range.start || '')}">
                    <input type="date" class="js-channel-field" data-field="end" data-index="${index}" value="${esc(rule.date_range.end || '')}">
                </td>
                <td><input type="number" class="small-text js-channel-field" data-field="priority" data-index="${index}" value="${esc(rule.priority)}"></td>
                <td><input type="text" class="regular-text js-channel-field" data-field="notes" data-index="${index}" value="${esc(rule.notes || '')}"></td>
                <td><input type="text" class="regular-text js-channel-field" data-field="coupon_gate" data-index="${index}" value="${esc(rule.coupon_gate || '')}" placeholder="SUMMER10"></td>
                <td><button class="button-link-delete js-channel-remove" data-index="${index}">&times;</button></td>
            </tr>
        `).join('');

        const html = `
            <p class="description">${esc('Kanaalregels maken afwijkende prijzen mogelijk voor OTA’s, interne verkoop of outlet-specifieke acties.')}</p>
            <table class="widefat striped sbdp-saleshub-table">
                <thead>
                    <tr>
                        <th>${esc('Kanaal')}</th>
                        <th>${esc('Type')}</th>
                        <th>${esc('Waarde')}</th>
                        <th>${esc('Producten')}</th>
                        <th>${esc('Outlets')}</th>
                        <th>${esc('Dagen')}</th>
                        <th>${esc('Periode')}</th>
                        <th>${esc('Prioriteit')}</th>
                        <th>${esc('Notities')}</th>
                        <th>${esc('Couponfilter')}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    ${rows || `<tr><td colspan="11">${esc('Er zijn nog geen kanaalregels.')}</td></tr>`}
                </tbody>
            </table>
            <p><button class="button js-channel-add">${esc('Kanaalregel toevoegen')}</button></p>
        `;

        pane.html(html);
    }

    function renderCoupons() {
        const pane = $('#sbdp-saleshub-coupons');

        const rows = state.coupons.map((coupon, index) => `
            <tr>
                <td><input type="text" class="regular-text js-coupon-field" data-field="code" data-index="${index}" value="${esc(coupon.code)}"></td>
                <td><input type="text" class="regular-text js-coupon-field" data-field="label" data-index="${index}" value="${esc(coupon.label || '')}"></td>
                <td>
                    <select class="js-coupon-field" data-field="type" data-index="${index}">
                        ${selectOptions({percent: 'Procent', fixed: 'Vast bedrag'}, coupon.type)}
                    </select>
                </td>
                <td><input type="number" class="small-text js-coupon-field" data-field="amount" data-index="${index}" step="0.1" value="${esc(coupon.amount)}"></td>
                <td><input type="text" class="regular-text js-coupon-field" data-field="channels" data-index="${index}" value="${esc(listToString(coupon.channels, false))}" placeholder="web,viator"></td>
                <td><input type="text" class="regular-text js-coupon-field" data-field="products" data-index="${index}" value="${esc(listToString(coupon.products))}" placeholder="123,456"></td>
                <td><input type="text" class="regular-text js-coupon-field" data-field="outlets" data-index="${index}" value="${esc(listToString(coupon.outlets))}" placeholder="10,11"></td>
                <td>
                    <input type="date" class="js-coupon-field" data-field="valid_from" data-index="${index}" value="${esc(coupon.valid_from || '')}">
                    <input type="date" class="js-coupon-field" data-field="valid_until" data-index="${index}" value="${esc(coupon.valid_until || '')}">
                </td>
                <td><input type="number" class="small-text js-coupon-field" data-field="usage_limit" data-index="${index}" value="${esc(coupon.usage_limit)}"></td>
                <td>
                    <select class="js-coupon-field" data-field="status" data-index="${index}">
                        ${selectOptions({active: 'Actief', paused: 'Gepauzeerd', archived: 'Gearchiveerd'}, coupon.status)}
                    </select>
                </td>
                <td>
                    <label>
                        <input type="checkbox" class="js-coupon-field" data-field="stackable" data-index="${index}" ${coupon.stackable ? 'checked' : ''}>
                        ${esc('Combineerbaar')}
                    </label>
                </td>
                <td><button class="button-link-delete js-coupon-remove" data-index="${index}">&times;</button></td>
            </tr>
        `).join('');

        const html = `
            <p class="description">${esc('Kortingsregels hier worden toegepast in plannerflows voordat WooCommerce-coupons ingaan.')}</p>
            <table class="widefat striped sbdp-saleshub-table">
                <thead>
                    <tr>
                        <th>${esc('Code')}</th>
                        <th>${esc('Label')}</th>
                        <th>${esc('Type')}</th>
                        <th>${esc('Bedrag')}</th>
                        <th>${esc('Kanalen')}</th>
                        <th>${esc('Producten')}</th>
                        <th>${esc('Outlets')}</th>
                        <th>${esc('Geldigheid')}</th>
                        <th>${esc('Gebruiks­limiet')}</th>
                        <th>${esc('Status')}</th>
                        <th>${esc('Combineerbaar')}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    ${rows || `<tr><td colspan="12">${esc('Er zijn nog geen kortingsregels.')}</td></tr>`}
                </tbody>
            </table>
            <p><button class="button js-coupon-add">${esc('Kortingsregel toevoegen')}</button></p>
        `;

        pane.html(html);
    }

    function renderYield() {
        const pane = $('#sbdp-saleshub-yield');

        const rows = state.yield_rules.map((rule, index) => `
            <tr>
                <td><input type="text" class="regular-text js-yield-field" data-field="id" data-index="${index}" value="${esc(rule.id)}"></td>
                <td>
                    <select class="js-yield-field" data-field="metric" data-index="${index}">
                        ${selectOptions({occupancy: 'Bezetting', lead_time: 'Boekingshorizon', conversion: 'Conversie', manual: 'Handmatig'}, rule.metric)}
                    </select>
                </td>
                <td>
                    <select class="js-yield-field" data-field="operator" data-index="${index}">
                        ${selectOptions({gte: '>=', lte: '<=', eq: '='}, rule.operator)}
                    </select>
                </td>
                <td><input type="number" class="small-text js-yield-field" data-field="threshold" data-index="${index}" step="0.1" value="${esc(rule.threshold)}"></td>
                <td><input type="text" class="regular-text js-yield-field" data-field="channels" data-index="${index}" value="${esc(listToString(rule.channels, false))}"></td>
                <td><input type="text" class="regular-text js-yield-field" data-field="products" data-index="${index}" value="${esc(listToString(rule.products))}"></td>
                <td><input type="text" class="regular-text js-yield-field" data-field="outlets" data-index="${index}" value="${esc(listToString(rule.outlets))}"></td>
                <td>
                    <select class="js-yield-field" data-field="adj_type" data-index="${index}">
                        ${selectOptions({percent: 'Procent', fixed: 'Vast bedrag'}, rule.adjustment.type)}
                    </select>
                    <select class="js-yield-field" data-field="adj_direction" data-index="${index}">
                        ${selectOptions({increase: 'Verhogen', decrease: 'Verlagen'}, rule.adjustment.direction)}
                    </select>
                    <input type="number" class="small-text js-yield-field" data-field="adj_value" data-index="${index}" step="0.1" value="${esc(rule.adjustment.value)}">
                </td>
                <td><input type="number" class="small-text js-yield-field" data-field="cooldown" data-index="${index}" value="${esc(rule.cooldown)}"></td>
                <td><input type="text" class="regular-text js-yield-field" data-field="notes" data-index="${index}" value="${esc(rule.notes || '')}"></td>
                <td><button class="button-link-delete js-yield-remove" data-index="${index}">&times;</button></td>
            </tr>
        `).join('');

        const html = `
            <p class="description">${esc('Yield rules trigger automatic surcharges or discounts when metrics meet thresholds (occupancy, lead time, etc).')}</p>
            <table class="widefat striped sbdp-saleshub-table">
                <thead>
                    <tr>
                        <th>${esc('ID')}</th>
                        <th>${esc('Metric')}</th>
                        <th>${esc('Operator')}</th>
                        <th>${esc('Threshold')}</th>
                        <th>${esc('Channels')}</th>
                        <th>${esc('Products')}</th>
                        <th>${esc('Outlets')}</th>
                        <th>${esc('Adjustment')}</th>
                        <th>${esc('Cooldown')}</th>
                        <th>${esc('Notes')}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    ${rows || `<tr><td colspan="11">${esc('No yield rules defined yet.')}</td></tr>`}
                </tbody>
            </table>
            <p><button class="button js-yield-add">${esc('Add yield rule')}</button></p>
        `;

        pane.html(html);
    }

    function ensureDefaults() {
        state.version = state.version || 1;
        state.global = $.extend(
            {
                base_margin_pct: 0,
                flat_service_fee: 0,
                minimum_price: 0,
                maximum_discount_pct: 100,
                weekend_surcharge_pct: 0,
                allow_coupon_stacking: false
            },
            state.global || {}
        );
        state.channels = Array.isArray(state.channels) ? state.channels : [];
        state.coupons = Array.isArray(state.coupons) ? state.coupons : [];
        state.yield_rules = Array.isArray(state.yield_rules) ? state.yield_rules : [];
    }

    function defaultChannelRule() {
        return {
            id: '',
            channel: '',
            kind: 'percent_markup',
            value: 0,
            products: [],
            outlets: [],
            days: [],
            date_range: { start: null, end: null },
            notes: '',
            priority: 10,
            coupon_gate: ''
        };
    }

    function defaultCoupon() {
        return {
            code: '',
            label: '',
            type: 'percent',
            amount: 0,
            channels: [],
            products: [],
            outlets: [],
            valid_from: null,
            valid_until: null,
            usage_limit: 0,
            usage_count: 0,
            min_total: 0,
            max_total: 0,
            stackable: false,
            status: 'active'
        };
    }

    function defaultYieldRule() {
        return {
            id: '',
            metric: 'occupancy',
            operator: 'gte',
            threshold: 0,
            channels: [],
            products: [],
            outlets: [],
            adjustment: { type: 'percent', direction: 'increase', value: 0 },
            cooldown: 0,
            notes: ''
        };
    }

    function updateGlobal(field, $element) {
        if (field === 'allow_coupon_stacking') {
            state.global.allow_coupon_stacking = $element.is(':checked');

            return;
        }

        const numeric = ['base_margin_pct', 'flat_service_fee', 'weekend_surcharge_pct', 'minimum_price', 'maximum_discount_pct'];
        const value = $element.val();

        if (numeric.indexOf(field) !== -1) {
            state.global[field] = parseFloat(value) || 0;
        } else {
            state.global[field] = value;
        }
    }

    function updateChannelField(index, field, $element) {
        const rule = state.channels[index];
        if (!rule) {
            return;
        }

        const raw = $element.val();

        switch (field) {
            case 'channel':
                rule.channel = String(raw || '').toLowerCase();
                break;
            case 'kind':
                rule.kind = String(raw || 'percent_markup');
                break;
            case 'value':
                rule.value = parseFloat(raw) || 0;
                break;
            case 'products':
                rule.products = parseIntegerList(raw);
                break;
            case 'outlets':
                rule.outlets = parseIntegerList(raw);
                break;
            case 'days':
                rule.days = parseIntegerList(raw);
                break;
            case 'start':
                rule.date_range.start = raw ? String(raw) : null;
                break;
            case 'end':
                rule.date_range.end = raw ? String(raw) : null;
                break;
            case 'priority':
                rule.priority = parseInt(raw, 10) || 10;
                break;
            case 'notes':
                rule.notes = String(raw || '');
                break;
            case 'coupon_gate':
                rule.coupon_gate = String(raw || '').toUpperCase();
                break;
            default:
                break;
        }
    }

    function updateCouponField(index, field, $element) {
        const coupon = state.coupons[index];
        if (!coupon) {
            return;
        }

        const raw = $element.val();

        switch (field) {
            case 'code':
                coupon.code = String(raw || '').toUpperCase();
                break;
            case 'label':
                coupon.label = String(raw || '');
                break;
            case 'type':
                coupon.type = String(raw || 'percent');
                break;
            case 'amount':
                coupon.amount = parseFloat(raw) || 0;
                break;
            case 'channels':
                coupon.channels = parseStringList(raw);
                break;
            case 'products':
                coupon.products = parseIntegerList(raw);
                break;
            case 'outlets':
                coupon.outlets = parseIntegerList(raw);
                break;
            case 'valid_from':
                coupon.valid_from = raw ? String(raw) : null;
                break;
            case 'valid_until':
                coupon.valid_until = raw ? String(raw) : null;
                break;
            case 'usage_limit':
                coupon.usage_limit = parseInt(raw, 10) || 0;
                break;
            case 'status':
                coupon.status = String(raw || 'active').toLowerCase();
                break;
            case 'stackable':
                coupon.stackable = $element.is(':checked');
                break;
            default:
                break;
        }
    }

    function updateYieldField(index, field, $element) {
        const rule = state.yield_rules[index];
        if (!rule) {
            return;
        }

        const raw = $element.val();

        switch (field) {
            case 'id':
                rule.id = String(raw || '');
                break;
            case 'metric':
                rule.metric = String(raw || 'occupancy');
                break;
            case 'operator':
                rule.operator = String(raw || 'gte');
                break;
            case 'threshold':
                rule.threshold = parseFloat(raw) || 0;
                break;
            case 'channels':
                rule.channels = parseStringList(raw);
                break;
            case 'products':
                rule.products = parseIntegerList(raw);
                break;
            case 'outlets':
                rule.outlets = parseIntegerList(raw);
                break;
            case 'adj_type':
                rule.adjustment.type = String(raw || 'percent');
                break;
            case 'adj_direction':
                rule.adjustment.direction = String(raw || 'increase');
                break;
            case 'adj_value':
                rule.adjustment.value = parseFloat(raw) || 0;
                break;
            case 'cooldown':
                rule.cooldown = parseInt(raw, 10) || 0;
                break;
            case 'notes':
                rule.notes = String(raw || '');
                break;
            default:
                break;
        }
    }

    function parseIntegerList(value) {
        return String(value || '')
            .split(',')
            .map((item) => parseInt(item, 10))
            .filter((num) => !isNaN(num));
    }

    function parseStringList(value) {
        return String(value || '')
            .split(',')
            .map((item) => item.trim().toLowerCase())
            .filter((item) => item !== '');
    }

    function listToString(list, isNumeric = true) {
        if (!Array.isArray(list)) {
            return '';
        }

        if (!isNumeric) {
            return list.join(', ');
        }

        return list.map((value) => Number(value)).join(', ');
    }

    function selectOptions(map, selected) {
        return Object.keys(map).map((key) => {
            const value = map[key];
            const isSelected = key === selected ? 'selected' : '';

            return `<option value="${esc(key)}" ${isSelected}>${esc(value)}</option>`;
        }).join('');
    }

    function setActiveTab(tab) {
        container.find('.sbdp-saleshub-tab').removeClass('is-active');
        container.find('.sbdp-saleshub-tab[data-tab-target="' + tab + '"]').addClass('is-active');
        container.find('.sbdp-saleshub-panel').removeClass('is-active');
        container.find('.sbdp-saleshub-panel[data-panel="' + tab + '"]').addClass('is-active');
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }
})(jQuery);

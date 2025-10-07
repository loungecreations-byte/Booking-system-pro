(function($, i18n){
  'use strict';

  if (typeof $ === 'undefined') {
    return;
  }

  if (!window.SBDP_ADMIN_AV) {
    return;
  }

  const identity = function(value){ return value; };
  const noopSprintf = function(format){
    const args = Array.prototype.slice.call(arguments, 1);
    return format.replace(/%s/g, function(){ return args.length ? args.shift() : ''; });
  };

  const __ = i18n && typeof i18n.__ === 'function' ? i18n.__ : identity;
  const sprintf = i18n && typeof i18n.sprintf === 'function' ? i18n.sprintf : noopSprintf;

  const apiConfig = window.SBDP_ADMIN_AV;

  const withPath = function(base, suffix){
    if (!base) {
      return '';
    }
    return base.replace(/\/$/, '') + suffix;
  };

  const endpoints = {
    availabilityRules: withPath(apiConfig.api_base, '/rules'),
    availabilityPublish: apiConfig.publish_endpoint ? apiConfig.publish_endpoint : withPath(apiConfig.api_base, '/rules'),
    availabilityPreview: withPath(apiConfig.api_base, '/preview'),
    services: apiConfig.services_endpoint || '',
    resources: apiConfig.resources_endpoint || '',
    pricingRules: withPath(apiConfig.pricing_base, '/rules')
  };

  const weekdays = [
    __('Sunday', 'sbdp'),
    __('Monday', 'sbdp'),
    __('Tuesday', 'sbdp'),
    __('Wednesday', 'sbdp'),
    __('Thursday', 'sbdp'),
    __('Friday', 'sbdp'),
    __('Saturday', 'sbdp')
  ];

  const months = [
    __('January', 'sbdp'),
    __('February', 'sbdp'),
    __('March', 'sbdp'),
    __('April', 'sbdp'),
    __('May', 'sbdp'),
    __('June', 'sbdp'),
    __('July', 'sbdp'),
    __('August', 'sbdp'),
    __('September', 'sbdp'),
    __('October', 'sbdp'),
    __('November', 'sbdp'),
    __('December', 'sbdp')
  ];

  const defaultRuleSet = function(){
    return {
      default: 'open',
      exclude_weekdays: [],
      exclude_months: [],
      exclude_times: [],
      overrides: []
    };
  };

  const defaultPriceRule = function(){
    return {
      label: '',
      type: 'fixed',
      amount: 0,
      apply_to: 'booking',
      weekdays: [],
      time_from: '',
      time_to: '',
      date_from: '',
      date_to: '',
      enabled: true
    };
  };

  let productsCache = null;
  let resourcesCache = null;

  const request = function(url, options){
    const config = options || {};
    const headers = config.headers ? Object.assign({}, config.headers) : {};

    if (apiConfig.nonce) {
      headers['X-WP-Nonce'] = apiConfig.nonce;
    }

    if (config.body && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }

    return fetch(url, Object.assign({}, config, {
      headers: headers,
      credentials: 'same-origin'
    })).then(function(response){
      if (!response.ok) {
        return response.json().catch(function(){ return {}; }).then(function(data){
          const message = data && data.message ? data.message : __('Unexpected error', 'sbdp');
          throw new Error(message);
        });
      }
      if (response.status === 204) {
        return null;
      }
      return response.json().catch(function(){ return null; });
    });
  };

  const fetchProducts = function(){
    if (!endpoints.services) {
      return Promise.resolve([]);
    }
    if (!productsCache) {
      productsCache = request(endpoints.services).then(function(list){
        return Array.isArray(list) ? list : [];
      }).catch(function(){ return []; });
    }
    return productsCache;
  };

  const fetchResources = function(){
    if (!endpoints.resources) {
      return Promise.resolve([]);
    }
    if (!resourcesCache) {
      resourcesCache = request(endpoints.resources).then(function(list){
        const safeList = Array.isArray(list) ? list : [];
        cachedResources = safeList;
        return safeList;
      }).catch(function(){
        cachedResources = [];
        return [];
      });
    }
    return resourcesCache;
  };

  const escapeAttr = function(value){
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  };

  const setNotice = function(container, message, tone){
    const area = container.querySelector('[data-role="notices"]');
    if (!area) {
      return;
    }
    area.innerHTML = '';
    if (!message) {
      return;
    }
    const div = document.createElement('div');
    div.className = 'sbdp-alert sbdp-alert--' + (tone || 'info');
    div.textContent = message;
    area.appendChild(div);
    window.setTimeout(function(){
      if (area.contains(div)) {
        area.removeChild(div);
      }
    }, 6000);
  };

  const loading = function(container, isLoading){
    container.classList.toggle('sbdp-is-loading', !!isLoading);
  };

  const renderAvailabilityApp = function(container){
    const calendarId = (container.id ? container.id : 'sbdp-av') + '-calendar';
    const markup = '' +
      '<div class="sbdp-admin-app" data-app="availability">' +
        '<div class="sbdp-admin-toolbar">' +
          '<div class="sbdp-field">' +
            '<label>' + __('Product', 'sbdp') + '</label>' +
            '<select data-field="product"><option value="">' + __('Select a product', 'sbdp') + '</option></select>' +
          '</div>' +
          '<div class="sbdp-field">' +
            '<label>' + __('Resource', 'sbdp') + '</label>' +
            '<select data-field="resource"><option value="">' + __('All resources', 'sbdp') + '</option></select>' +
          '</div>' +
          '<div class="sbdp-field sbdp-field--capacity">' +
            '<label>' + __('Capacity', 'sbdp') + '</label>' +
            '<div class="sbdp-capacity-control">' +
              '<input type="number" min="0" step="1" value="0" data-field="capacity" />' +
              '<label class="sbdp-capacity-toggle">' +
                '<input type="checkbox" data-field="capacity-inherit" /> ' + __('Use resource capacity', 'sbdp') +
                ' <span data-role="resource-capacity-label"></span>' +
              '</label>' +
            '</div>' +
          '</div>' +
          '<div class="sbdp-field sbdp-field--actions">' +
            '<button type="button" class="button button-primary" data-action="save">' + __('Save rules', 'sbdp') + '</button>' +
          '</div>' +
        '</div>' +
        '<div class="sbdp-admin-notices" data-role="notices"></div>' +
        '<div class="sbdp-admin-columns">' +
          '<div class="sbdp-panel">' +
            '<h2>' + __('Availability pattern', 'sbdp') + '</h2>' +
            '<fieldset class="sbdp-fieldset">' +
              '<legend>' + __('Default status', 'sbdp') + '</legend>' +
              '<label><input type="radio" name="sbdp-default" value="open" checked /> ' + __('Open by default', 'sbdp') + '</label>' +
              '<label><input type="radio" name="sbdp-default" value="closed" /> ' + __('Closed by default', 'sbdp') + '</label>' +
            '</fieldset>' +
            '<fieldset class="sbdp-fieldset">' +
              '<legend>' + __('Closed weekdays', 'sbdp') + '</legend>' +
              '<div class="sbdp-weekday-grid" data-role="weekday-grid"></div>' +
            '</fieldset>' +
            '<fieldset class="sbdp-fieldset">' +
              '<legend>' + __('Closed months', 'sbdp') + '</legend>' +
              '<div class="sbdp-month-grid" data-role="month-grid"></div>' +
            '</fieldset>' +
            '<fieldset class="sbdp-fieldset">' +
              '<legend>' + __('Time ranges', 'sbdp') + '</legend>' +
              '<p class="description">' + __('Mark times within the chosen day as unavailable.', 'sbdp') + '</p>' +
              '<div data-role="time-rows"></div>' +
              '<button type="button" class="button" data-action="add-time">' + __('Add time range', 'sbdp') + '</button>' +
            '</fieldset>' +
            '<fieldset class="sbdp-fieldset">' +
              '<legend>' + __('Overrides', 'sbdp') + '</legend>' +
              '<p class="description">' + __('Override availability for specific date ranges.', 'sbdp') + '</p>' +
              '<div data-role="override-rows"></div>' +
              '<button type="button" class="button" data-action="add-override">' + __('Add override', 'sbdp') + '</button>' +
            '</fieldset>' +
          '</div>' +
          '<div class="sbdp-panel">' +
            '<h2>' + __('Calendar preview', 'sbdp') + '</h2>' +
            '<div class="sbdp-preview-toolbar">' +
              '<label>' + __('Preview date', 'sbdp') + ' <input type="date" data-field="preview-date" /></label>' +
              '<button type="button" class="button" data-action="preview">' + __('Refresh preview', 'sbdp') + '</button>' +
            '</div>' +
            '<div id="' + calendarId + '" class="sbdp-calendar"></div>' +
            '<p class="description" data-role="capacity-note"></p>' +
          '</div>' +
        '</div>' +
      '</div>';

    container.innerHTML = markup;

    const productSelect = container.querySelector('select[data-field="product"]');
    const resourceSelect = container.querySelector('select[data-field="resource"]');
    const capacityInput = container.querySelector('input[data-field="capacity"]');
    const capacityInheritToggle = container.querySelector('input[data-field="capacity-inherit"]');
    const resourceCapacityLabel = container.querySelector('[data-role="resource-capacity-label"]');
    const previewDateInput = container.querySelector('input[data-field="preview-date"]');
    const timeRows = container.querySelector('[data-role="time-rows"]');
    const overrideRows = container.querySelector('[data-role="override-rows"]');
    const weekdayGrid = container.querySelector('[data-role="weekday-grid"]');
    const monthGrid = container.querySelector('[data-role="month-grid"]');
    const capacityNote = container.querySelector('[data-role="capacity-note"]');

    let calendar = null;
    const state = {
      productId: 0,
      resourceId: 0,
      rules: defaultRuleSet(),
      capacity: 0,
      capacityMode: 'product',
      resourceCapacity: null
    };

    weekdays.forEach(function(label, index){
      const id = 'sbdp-weekday-' + index;
      const wrapper = document.createElement('label');
      wrapper.setAttribute('for', id);
      wrapper.innerHTML = '<input type="checkbox" id="' + id + '" value="' + index + '" data-role="weekday" /> ' + label.substr(0, 3);
      weekdayGrid.appendChild(wrapper);
    });

    months.forEach(function(label, index){
      const id = 'sbdp-month-' + (index + 1);
      const wrapper = document.createElement('label');
      wrapper.setAttribute('for', id);
      wrapper.innerHTML = '<input type="checkbox" id="' + id + '" value="' + (index + 1) + '" data-role="month" /> ' + label.substr(0, 3);
      monthGrid.appendChild(wrapper);
    });

    const ensureCalendar = function(){
      if (calendar) {
        return calendar;
      }
      const calendarEl = document.getElementById(calendarId);
      if (!calendarEl || typeof FullCalendar === 'undefined' || !FullCalendar.Calendar) {
        return null;
      }
      calendar = new FullCalendar.Calendar(calendarEl, {
        height: 'auto',
        locale: navigator.language || 'nl',
        initialView: 'timeGridDay',
        slotMinTime: '06:00:00',
        slotMaxTime: '24:00:00',
        headerToolbar: {
          left: 'title',
          center: '',
          right: 'timeGridDay,timeGridWeek,dayGridMonth'
        }
      });
      calendar.render();
      return calendar;
    };

    const destroyCalendar = function(){
      if (calendar) {
        calendar.destroy();
      }
      calendar = null;
    };

    const formatCapacityLabel = function(value){
      const numeric = parseInt(value, 10);
      if (!numeric || isNaN(numeric) || numeric <= 0) {
        return __('Unlimited', 'sbdp');
      }
      return numeric;
    };

    const updateResourceCapacityLabel = function(){
      if (!resourceCapacityLabel) {
        return;
      }
      if (!state.resourceId) {
        resourceCapacityLabel.textContent = __('n.v.t.', 'sbdp');
        return;
      }
      resourceCapacityLabel.textContent = formatCapacityLabel(state.resourceCapacity);
    };

    const updateCapacityNote = function(){
      if (!capacityNote) {
        return;
      }
      if (state.capacityMode === 'resource' && state.resourceId) {
        capacityNote.textContent = sprintf(__('Current capacity: %s participants (resource)', 'sbdp'), formatCapacityLabel(state.resourceCapacity));
        return;
      }
      capacityNote.textContent = sprintf(__('Current capacity: %s participants', 'sbdp'), formatCapacityLabel(capacityInput.value || state.capacity));
    };

    const syncCapacityControls = function(){
      if (!capacityInheritToggle) {
        return;
      }
      const hasResource = !!state.resourceId;
      const useResource = hasResource && state.capacityMode === 'resource';
      capacityInheritToggle.disabled = !hasResource;
      capacityInheritToggle.checked = useResource;
      capacityInput.disabled = useResource;
      if (!useResource) {
        capacityInput.value = state.capacity > 0 ? state.capacity : '';
      }
      updateResourceCapacityLabel();
      updateCapacityNote();
    };

    const addTimeRow = function(range){
      const row = document.createElement('div');
      row.className = 'sbdp-dynamic-row';
      row.innerHTML = '' +
        '<label>' + __('From', 'sbdp') + ' <input type="time" value="' + escapeAttr(range && range.start ? range.start : '') + '" data-role="time-start" /></label>' +
        '<label>' + __('To', 'sbdp') + ' <input type="time" value="' + escapeAttr(range && range.end ? range.end : '') + '" data-role="time-end" /></label>' +
        '<button type="button" class="button-link" data-action="remove-time">' + __('Remove', 'sbdp') + '</button>';
      timeRows.appendChild(row);
    };

    const addOverrideRow = function(override){
      const row = document.createElement('div');
      row.className = 'sbdp-dynamic-row';
      row.innerHTML = '' +
        '<label>' + __('Start', 'sbdp') + ' <input type="date" value="' + escapeAttr(override && override.from ? override.from : '') + '" data-role="override-from" /></label>' +
        '<label>' + __('End', 'sbdp') + ' <input type="date" value="' + escapeAttr(override && override.to ? override.to : '') + '" data-role="override-to" /></label>' +
        '<label>' + __('Mode', 'sbdp') + ' <select data-role="override-mode">' +
          '<option value="closed"' + ((override && override.mode === 'open') ? '' : ' selected') + '>' + __('Closed', 'sbdp') + '</option>' +
          '<option value="open"' + ((override && override.mode === 'open') ? ' selected' : '') + '>' + __('Open', 'sbdp') + '</option>' +
        '</select></label>' +
        '<button type="button" class="button-link" data-action="remove-override">' + __('Remove', 'sbdp') + '</button>';
      overrideRows.appendChild(row);
    };

    const applyRulesToForm = function(rules, context){
      const ctx = context || {};
      state.rules = Object.assign(defaultRuleSet(), rules || {});
      const parsedCapacity = parseInt(ctx.capacity, 10);
      state.capacity = (!isNaN(parsedCapacity) && parsedCapacity > 0) ? parsedCapacity : 0;
      state.capacityMode = ctx.capacity_source === 'resource' ? 'resource' : 'product';
      if (!state.resourceId) {
        state.capacityMode = 'product';
      }
      if (typeof ctx.resource_capacity === 'number') {
        state.resourceCapacity = ctx.resource_capacity;
      } else {
        state.resourceCapacity = null;
      }

      const defaultValue = state.rules.default === 'closed' ? 'closed' : 'open';
      const defaultRadio = container.querySelector('input[name="sbdp-default"][value="' + defaultValue + '"]');
      if (defaultRadio) {
        defaultRadio.checked = true;
      }

      weekdayGrid.querySelectorAll('input[data-role="weekday"]').forEach(function(input){
        const dayValue = parseInt(input.value, 10);
        input.checked = state.rules.exclude_weekdays.indexOf(dayValue) !== -1;
      });

      monthGrid.querySelectorAll('input[data-role="month"]').forEach(function(input){
        const monthValue = parseInt(input.value, 10);
        input.checked = state.rules.exclude_months.indexOf(monthValue) !== -1;
      });

      timeRows.innerHTML = '';
      (state.rules.exclude_times || []).forEach(function(range){
        addTimeRow(range);
      });
      if (!timeRows.children.length) {
        addTimeRow();
      }

      overrideRows.innerHTML = '';
      (state.rules.overrides || []).forEach(function(item){
        addOverrideRow(item);
      });
      if (!overrideRows.children.length) {
        addOverrideRow();
      }

      syncCapacityControls();
      const today = new Date();
      previewDateInput.value = today.toISOString().slice(0, 10);
    };

    const collectRulesFromForm = function(){
      const currentRules = defaultRuleSet();
      const checkedDefault = container.querySelector('input[name="sbdp-default"]:checked');
      currentRules.default = checkedDefault ? checkedDefault.value : 'open';

      currentRules.exclude_weekdays = Array.prototype.slice.call(weekdayGrid.querySelectorAll('input[data-role="weekday"]:checked')).map(function(input){
        return parseInt(input.value, 10);
      });

      currentRules.exclude_months = Array.prototype.slice.call(monthGrid.querySelectorAll('input[data-role="month"]:checked')).map(function(input){
        return parseInt(input.value, 10);
      });

      currentRules.exclude_times = Array.prototype.slice.call(timeRows.querySelectorAll('.sbdp-dynamic-row')).map(function(row){
        const start = row.querySelector('input[data-role="time-start"]').value;
        const end = row.querySelector('input[data-role="time-end"]').value;
        if (!start || !end) {
          return null;
        }
        return { start: start, end: end };
      }).filter(Boolean);

      currentRules.overrides = Array.prototype.slice.call(overrideRows.querySelectorAll('.sbdp-dynamic-row')).map(function(row){
        const from = row.querySelector('input[data-role="override-from"]').value;
        const to = row.querySelector('input[data-role="override-to"]').value;
        const mode = row.querySelector('select[data-role="override-mode"]').value;
        if (!from || !to) {
          return null;
        }
        return { from: from, to: to, mode: mode };
      }).filter(Boolean);

      const rawCapacity = parseInt(capacityInput.value || '0', 10);
      const sanitizedCapacity = (!isNaN(rawCapacity) && rawCapacity > 0) ? rawCapacity : 0;
      const useResource = !!(state.resourceId && capacityInheritToggle && capacityInheritToggle.checked);
      if (!useResource) {
        state.capacity = sanitizedCapacity;
      }

      return {
        rules: currentRules,
        capacity: sanitizedCapacity,
        capacity_mode: useResource ? 'resource' : 'product'
      };
    };

    const loadRules = function(){
      if (!state.productId) {
        setNotice(container, __('Please choose a product first.', 'sbdp'), 'info');
        destroyCalendar();
        return;
      }
      loading(container, true);
      const url = endpoints.availabilityRules + '?product_id=' + state.productId + '&resource_id=' + state.resourceId;
      request(url).then(function(response){
        const data = response || {};
        applyRulesToForm(data.rules || defaultRuleSet(), data);
        setNotice(container, '', '');
        refreshPreview();
      }).catch(function(error){
        setNotice(container, error.message, 'error');
        destroyCalendar();
      }).finally(function(){
        loading(container, false);
      });
    };

    const refreshPreview = function(){
      if (!state.productId) {
        return;
      }
      const dateValue = previewDateInput.value || new Date().toISOString().slice(0, 10);
      const payload = {
        product_id: state.productId,
        resource_id: state.resourceId,
        date: dateValue
      };
      request(endpoints.availabilityPreview, {
        method: 'POST',
        body: JSON.stringify(payload)
      }).then(function(response){
        if (!response) {
          return;
        }
        if (response.capacity_source) {
          state.capacityMode = response.capacity_source === 'resource' ? 'resource' : 'product';
          if (!state.resourceId) {
            state.capacityMode = 'product';
          }
        }
        if (typeof response.resource_capacity === 'number') {
          state.resourceCapacity = response.resource_capacity;
        }
        if (typeof response.capacity !== 'undefined') {
          const previewCapacity = parseInt(response.capacity, 10);
          state.capacity = (!isNaN(previewCapacity) && previewCapacity > 0) ? previewCapacity : 0;
        }
        const calendarInstance = ensureCalendar();
        if (!calendarInstance) {
          syncCapacityControls();
          return;
        }
        calendarInstance.gotoDate(dateValue);
        calendarInstance.removeAllEvents();
        (response.blocks || []).forEach(function(block){
          calendarInstance.addEvent(block);
        });
        syncCapacityControls();
      }).catch(function(error){
        setNotice(container, error.message, 'error');
      });
    };

    const saveRules = function(){
      if (!state.productId) {
        setNotice(container, __('Select a product before saving.', 'sbdp'), 'error');
        return;
      }
      const payload = collectRulesFromForm();
      state.rules = payload.rules;
      state.capacity = payload.capacity;
      state.capacityMode = payload.capacity_mode === 'resource' ? 'resource' : 'product';
      if (!state.resourceId) {
        state.capacityMode = 'product';
      }
      syncCapacityControls();

      request(endpoints.availabilityPublish, {
        method: 'POST',
        body: JSON.stringify({
          product_id: state.productId,
          resource_id: state.resourceId,
          rules: state.rules,
          capacity: state.capacity,
          capacity_mode: payload.capacity_mode
        })
      }).then(function(response){
        if (response) {
          if (typeof response.capacity !== 'undefined') {
            const serverCapacity = parseInt(response.capacity, 10);
            state.capacity = (!isNaN(serverCapacity) && serverCapacity > 0) ? serverCapacity : 0;
          }
          if (typeof response.resource_capacity === 'number') {
            state.resourceCapacity = response.resource_capacity;
          }
          if (response.capacity_source) {
            state.capacityMode = response.capacity_source === 'resource' ? 'resource' : 'product';
            if (!state.resourceId) {
              state.capacityMode = 'product';
            }
          }
          syncCapacityControls();
        }
        setNotice(container, __('Availability saved.', 'sbdp'), 'success');
        refreshPreview();
      }).catch(function(error){
        setNotice(container, error.message, 'error');
      });
    };

    productSelect.addEventListener('change', function(){
      state.productId = parseInt(this.value || '0', 10);
      state.capacityMode = 'product';
      state.capacity = 0;
      state.resourceCapacity = null;
      syncCapacityControls();
      loadRules();
    });

    resourceSelect.addEventListener('change', function(){
      state.resourceId = parseInt(this.value || '0', 10);
      state.capacityMode = 'product';
      const match = cachedResources.find(function(resource){
        return parseInt(resource.id, 10) === state.resourceId;
      });
      state.resourceCapacity = match ? (parseInt(match.capacity, 10) || 0) : null;
      syncCapacityControls();
      loadRules();
    });

    capacityInput.addEventListener('input', function(){
      const value = parseInt(this.value || '0', 10);
      state.capacity = (!isNaN(value) && value > 0) ? value : 0;
      updateCapacityNote();
    });
    if (capacityInheritToggle) {
      capacityInheritToggle.addEventListener('change', function(){
        if (!state.resourceId) {
          capacityInheritToggle.checked = false;
          state.capacityMode = 'product';
          syncCapacityControls();
          return;
        }
        state.capacityMode = capacityInheritToggle.checked ? 'resource' : 'product';
        syncCapacityControls();
      });
    }

    container.addEventListener('click', function(event){
      const action = event.target.getAttribute('data-action');
      if (!action) {
        return;
      }
      if (action === 'add-time') {
        event.preventDefault();
        addTimeRow();
      }
      if (action === 'remove-time') {
        event.preventDefault();
        const row = event.target.closest('.sbdp-dynamic-row');
        if (row && row.parentNode) {
          row.parentNode.removeChild(row);
        }
      }
      if (action === 'add-override') {
        event.preventDefault();
        addOverrideRow({ mode: 'closed' });
      }
      if (action === 'remove-override') {
        event.preventDefault();
        const row = event.target.closest('.sbdp-dynamic-row');
        if (row && row.parentNode) {
          row.parentNode.removeChild(row);
        }
      }
      if (action === 'preview') {
        event.preventDefault();
        refreshPreview();
      }
      if (action === 'save') {
        event.preventDefault();
        saveRules();
      }
    });

    fetchProducts().then(function(products){
      productSelect.innerHTML = '<option value="">' + __('Select a product', 'sbdp') + '</option>';
      products.forEach(function(product){
        const option = document.createElement('option');
        option.value = product.id;
        option.textContent = product.name + ' (ID ' + product.id + ')';
        productSelect.appendChild(option);
      });
    });

    fetchResources().then(function(resources){
      resourceSelect.innerHTML = '<option value="">' + __('All resources', 'sbdp') + '</option>';
      resources.forEach(function(resource){
        const option = document.createElement('option');
        option.value = resource.id;
        option.textContent = resource.title + ' (ID ' + resource.id + ')';
        resourceSelect.appendChild(option);
      });
    });

    const today = new Date();
    previewDateInput.value = today.toISOString().slice(0, 10);
    updateCapacityNote();
  };

  const renderPricingApp = function(container){
    const markup = '' +
      '<div class="sbdp-admin-app" data-app="pricing">' +
        '<div class="sbdp-admin-toolbar">' +
          '<div class="sbdp-field">' +
            '<label>' + __('Product', 'sbdp') + '</label>' +
            '<select data-field="product"><option value="">' + __('Select a product', 'sbdp') + '</option></select>' +
          '</div>' +
          '<div class="sbdp-field">' +
            '<label>' + __('Resource', 'sbdp') + '</label>' +
            '<select data-field="resource"><option value="">' + __('All resources', 'sbdp') + '</option></select>' +
          '</div>' +
          '<div class="sbdp-field sbdp-field--actions">' +
            '<button type="button" class="button" data-action="add-rule">' + __('Add rule', 'sbdp') + '</button>' +
            '<button type="button" class="button button-primary" data-action="save">' + __('Save pricing', 'sbdp') + '</button>' +
          '</div>' +
        '</div>' +
        '<div class="sbdp-admin-notices" data-role="notices"></div>' +
        '<div class="sbdp-panel">' +
          '<h2>' + __('Price rules', 'sbdp') + '</h2>' +
          '<p class="description">' + __('Create time aware price adjustments per product or resource.', 'sbdp') + '</p>' +
          '<div data-role="rules-list" class="sbdp-rules-list"></div>' +
          '<p><button type="button" class="button" data-action="add-rule">' + __('Add rule', 'sbdp') + '</button></p>' +
          '<div class="sbdp-pricing-preview">' +
            '<h3>' + __('Preview pricing', 'sbdp') + '</h3>' +
            '<div class="sbdp-pricing-preview__controls">' +
              '<label><span>' + __('Participants', 'sbdp') + '</span><input type="number" min="1" step="1" value="1" data-field="preview-participants" /></label>' +
              '<label><span>' + __('Date', 'sbdp') + '</span><input type="date" data-field="preview-date" /></label>' +
              '<label><span>' + __('Time', 'sbdp') + '</span><input type="time" data-field="preview-time" value="10:00" /></label>' +
              '<button type="button" class="button" data-action="preview-pricing">' + __('Preview', 'sbdp') + '</button>' +
            '</div>' +
            '<div class="sbdp-pricing-preview__result" data-role="pricing-result"></div>' +
          '</div>' +
        '</div>' +
      '</div>';

    container.innerHTML = markup;

    const productSelect = container.querySelector('select[data-field="product"]');
    const resourceSelect = container.querySelector('select[data-field="resource"]');
    const rulesList = container.querySelector('[data-role="rules-list"]');
    const previewParticipantsInput = container.querySelector('input[data-field="preview-participants"]');
    const previewDateInputPricing = container.querySelector('input[data-field="preview-date"]');
    const previewTimeInput = container.querySelector('input[data-field="preview-time"]');
    const previewResultArea = container.querySelector('[data-role="pricing-result"]');

    const state = {
      productId: 0,
      resourceId: 0,
      rules: []
    };

    const normalizeRule = function(rule){
      const normalized = Object.assign({}, defaultPriceRule(), rule || {});
      normalized.type = normalized.type === 'percent' ? 'percent' : 'fixed';
      normalized.apply_to = normalized.apply_to === 'participant' ? 'participant' : 'booking';
      normalized.amount = parseFloat(normalized.amount || '0');
      if (isNaN(normalized.amount)) {
        normalized.amount = 0;
      }
      normalized.weekdays = Array.isArray(normalized.weekdays) ? normalized.weekdays.map(function(value){
        const parsed = parseInt(value, 10);
        return isNaN(parsed) ? null : parsed;
      }).filter(function(value){ return value !== null; }) : [];
      normalized.enabled = normalized.enabled === false ? false : true;
      normalized.time_from = normalized.time_from || '';
      normalized.time_to = normalized.time_to || '';
      normalized.date_from = normalized.date_from || '';
      normalized.date_to = normalized.date_to || '';
      return normalized;
    };

    const renderRules = function(){
      if (!state.rules.length) {
        rulesList.innerHTML = '<div class="sbdp-empty">' + __('No price rules yet. Use "Add rule" to create one.', 'sbdp') + '</div>';
        return;
      }
      const rows = state.rules.map(function(rule, index){
        const normalized = normalizeRule(rule);
        state.rules[index] = normalized;
        const enabled = normalized.enabled !== false;
        const disabledClass = enabled ? '' : ' sbdp-price-rule--disabled';
        return '' +
          '<div class="sbdp-price-rule' + disabledClass + '" data-index="' + index + '">' +
            '<div class="sbdp-price-rule__header">' +
              '<strong>' + sprintf(__('Rule %s', 'sbdp'), index + 1) + '</strong>' +
              '<div class="sbdp-price-rule__header-buttons">' +
                '<button type="button" class="button-link" data-action="duplicate-rule" data-index="' + index + '">' + __('Duplicate', 'sbdp') + '</button>' +
                '<button type="button" class="button-link" data-action="move-up" data-index="' + index + '">' + __('Move up', 'sbdp') + '</button>' +
                '<button type="button" class="button-link" data-action="move-down" data-index="' + index + '">' + __('Move down', 'sbdp') + '</button>' +
                '<button type="button" class="button-link-delete" data-action="delete-rule" data-index="' + index + '">' + __('Remove', 'sbdp') + '</button>' +
              '</div>' +
            '</div>' +
            '<div class="sbdp-price-rule__grid">' +
              '<label class="sbdp-price-rule__toggle"><span>' + __('Active', 'sbdp') + '</span><input type="checkbox" data-field="enabled" data-index="' + index + '"' + (enabled ? ' checked' : '') + ' /></label>' +
              '<label><span>' + __('Label', 'sbdp') + '</span><input type="text" value="' + escapeAttr(normalized.label) + '" data-field="label" data-index="' + index + '" /></label>' +
              '<label><span>' + __('Type', 'sbdp') + '</span><select data-field="type" data-index="' + index + '">' +
                '<option value="fixed"' + (normalized.type === 'percent' ? '' : ' selected') + '>' + __('Fixed amount', 'sbdp') + '</option>' +
                '<option value="percent"' + (normalized.type === 'percent' ? ' selected' : '') + '>' + __('Percentage', 'sbdp') + '</option>' +
              '</select></label>' +
              '<label><span>' + __('Amount', 'sbdp') + '</span><input type="number" step="0.01" value="' + escapeAttr(normalized.amount) + '" data-field="amount" data-index="' + index + '" /></label>' +
              '<label><span>' + __('Apply to', 'sbdp') + '</span><select data-field="apply_to" data-index="' + index + '">' +
                '<option value="booking"' + (normalized.apply_to === 'participant' ? '' : ' selected') + '>' + __('Per booking', 'sbdp') + '</option>' +
                '<option value="participant"' + (normalized.apply_to === 'participant' ? ' selected' : '') + '>' + __('Per participant', 'sbdp') + '</option>' +
              '</select></label>' +
              '<fieldset class="sbdp-price-rule__fieldset"><legend>' + __('Weekdays', 'sbdp') + '</legend>' +
                weekdays.map(function(label, dayIndex){
                  const checked = normalized.weekdays.indexOf(dayIndex) !== -1 ? ' checked' : '';
                  return '<label><input type="checkbox" data-field="weekday" data-index="' + index + '" value="' + dayIndex + '"' + checked + ' /> ' + label.substr(0, 2) + '</label>';
                }).join('') +
              '</fieldset>' +
              '<label><span>' + __('Start time', 'sbdp') + '</span><input type="time" value="' + escapeAttr(normalized.time_from) + '" data-field="time_from" data-index="' + index + '" /></label>' +
              '<label><span>' + __('End time', 'sbdp') + '</span><input type="time" value="' + escapeAttr(normalized.time_to) + '" data-field="time_to" data-index="' + index + '" /></label>' +
              '<label><span>' + __('Start date', 'sbdp') + '</span><input type="date" value="' + escapeAttr(normalized.date_from) + '" data-field="date_from" data-index="' + index + '" /></label>' +
              '<label><span>' + __('End date', 'sbdp') + '</span><input type="date" value="' + escapeAttr(normalized.date_to) + '" data-field="date_to" data-index="' + index + '" /></label>' +
            '</div>' +
          '</div>';
      }).join('');
      rulesList.innerHTML = rows;
    };

    const addRule = function(){
      state.rules.push(defaultPriceRule());
      renderRules();
    };

    const deleteRule = function(index){\n      state.rules.splice(index, 1);\n      renderRules();\n    };\n\n    const moveRule = function(index, delta){
      const target = index + delta;
      if (target < 0 || target >= state.rules.length) {
        return;
      }
      const updated = state.rules.splice(index, 1)[0];
      state.rules.splice(target, 0, updated);
      renderRules();
    };

    const duplicateRule = function(index){
      if (!state.rules[index]) {
        return;
      }
      const copy = JSON.parse(JSON.stringify(state.rules[index]));
      state.rules.splice(index + 1, 0, normalizeRule(copy));
      renderRules();
    };
    const loadPriceRules = function(){\n
      if (!state.productId) {
        setNotice(container, __('Select a product to manage price rules.', 'sbdp'), 'info');
        state.rules = [];
        renderRules();
        return;
      }
      loading(container, true);
      const url = endpoints.pricingRules + '?product_id=' + state.productId + '&resource_id=' + state.resourceId;
      request(url).then(function(response){
        const data = response || {};
        state.rules = Array.isArray(data.rules) ? data.rules.slice() : [];
        renderRules();
        setNotice(container, '', '');
      }).catch(function(error){
        setNotice(container, error.message, 'error');
      }).finally(function(){
        loading(container, false);
      });
    };

    const savePriceRules = function(){
      if (!state.productId) {
        setNotice(container, __('Select a product before saving.', 'sbdp'), 'error');
        return;
      }
      const errors = validateRules();
      if (errors.length) {
        setNotice(container, errors[0], 'error');
        renderRules();
        return;
      }
      request(endpoints.pricingRules, {
        method: 'POST',
        body: JSON.stringify({
          product_id: state.productId,
          resource_id: state.resourceId,
          rules: state.rules
        })
      }).then(function(){
        setNotice(container, __('Pricing saved.', 'sbdp'), 'success');
      }).catch(function(error){
        setNotice(container, error.message, 'error');
      });
    };

    const formatPreviewAmount = function(value){
      const numeric = parseFloat(value);
      if (isNaN(numeric)) {
        return value;
      }
      return numeric.toFixed(2);
    };

    const renderPricingPreviewResult = function(result){
      if (!previewResultArea) {
        return;
      }
      if (!result) {
        previewResultArea.innerHTML = '';
        return;
      }
      const applied = Array.isArray(result.applied_rules) ? result.applied_rules : [];
      const adjustments = parseFloat(result.booking_adjustment || 0);
      var rulesHtml = '';
      if (applied.length) {
        rulesHtml = '<ul class="sbdp-pricing-preview__list">' + applied.map(function(rule){
          const scopeLabel = rule.scope === 'participant' ? __('per participant', 'sbdp') : __('per booking', 'sbdp');
          return '<li><strong>' + escapeAttr(rule.label || __('Unnamed rule', 'sbdp')) + '</strong> (' + scopeLabel + '): ' + formatPreviewAmount(rule.amount) + '</li>';
        }).join('') + '</ul>';
      } else {
        rulesHtml = '<p class="sbdp-pricing-preview__empty">' + __('No matching rules applied for the selected moment.', 'sbdp') + '</p>';
      }
      var summaryHtml = '' +
        '<div><strong>' + __('Unit price', 'sbdp') + ':</strong> ' + formatPreviewAmount(result.unit_price) + '</div>' +
        '<div><strong>' + __('Total', 'sbdp') + ':</strong> ' + formatPreviewAmount(result.total) + '</div>' +
        '<div><strong>' + __('Base price', 'sbdp') + ':</strong> ' + formatPreviewAmount(result.base_price) + '</div>';
      if (!isNaN(adjustments) && adjustments !== 0) {
        summaryHtml += '<div><strong>' + __('Booking adjustment', 'sbdp') + ':</strong> ' + formatPreviewAmount(adjustments) + '</div>';
      }
      previewResultArea.innerHTML = '' +
        '<div class="sbdp-pricing-preview__summary">' + summaryHtml + '</div>' +
        '<div class="sbdp-pricing-preview__rules">' + rulesHtml + '</div>';
    };

    const runPricingPreview = function(){
      if (!state.productId) {
        setNotice(container, __('Select a product before previewing.', 'sbdp'), 'error');
        return;
      }
      if (!previewParticipantsInput || !previewDateInputPricing || !previewTimeInput) {
        return;
      }
      const participants = Math.max(1, parseInt(previewParticipantsInput.value || '1', 10));
      const date = previewDateInputPricing.value;
      const time = previewTimeInput.value || '00:00';
      if (!date) {
        setNotice(container, __('Choose a date to run the pricing preview.', 'sbdp'), 'error');
        return;
      }
      const isoStart = date + 'T' + time + ':00';
      request(endpoints.pricingPreview, {
        method: 'POST',
        body: JSON.stringify({
          product_id: state.productId,
          resource_id: state.resourceId,
          participants: participants,
          start: isoStart
        })
      }).then(function(response){
        setNotice(container, '', '');
        renderPricingPreviewResult(response);
      }).catch(function(error){
        setNotice(container, error.message, 'error');
        if (previewResultArea) {
          previewResultArea.innerHTML = '';
        }
      });
    };
    container.addEventListener('click', function(event){
      const action = event.target.getAttribute('data-action');
      if (!action) {
        return;
      }
      if (action === 'add-rule') {
        event.preventDefault();
        addRule();
      }
      if (action === 'delete-rule') {
        event.preventDefault();
        const index = parseInt(event.target.getAttribute('data-index'), 10);
        if (!isNaN(index)) {
          deleteRule(index);
        }
      }
      if (action === 'move-up') {
        event.preventDefault();
        const index = parseInt(event.target.getAttribute('data-index'), 10);
        if (!isNaN(index)) {
          moveRule(index, -1);
        }
      }
      if (action === 'move-down') {
        event.preventDefault();
        const index = parseInt(event.target.getAttribute('data-index'), 10);
        if (!isNaN(index)) {
          moveRule(index, 1);
        }
      }
      if (action === 'duplicate-rule') {
        event.preventDefault();
        const index = parseInt(event.target.getAttribute('data-index'), 10);
        if (!isNaN(index)) {
          duplicateRule(index);
        }
      }
      if (action === 'save') {
        event.preventDefault();
        savePriceRules();
      }
      if (action === 'preview-pricing') {
        event.preventDefault();
        runPricingPreview();
      }
    });

    container.addEventListener('change', function(event){
      const field = event.target.getAttribute('data-field');
      const index = parseInt(event.target.getAttribute('data-index'), 10);
      if (!field || isNaN(index) || !state.rules[index]) {
        return;
      }
      if (field === 'weekday') {
        const value = parseInt(event.target.value, 10);
        const list = state.rules[index].weekdays || [];
        if (event.target.checked) {
          if (list.indexOf(value) === -1) {
            list.push(value);
          }
        } else {
          state.rules[index].weekdays = list.filter(function(item){ return item !== value; });
        }
        return;
      }
      if (field === 'amount') {
        state.rules[index][field] = parseFloat(event.target.value || '0');
        return;
      }
      if (field === 'type' || field === 'apply_to') {
        state.rules[index][field] = event.target.value;
        return;
      }
      state.rules[index][field] = event.target.value;
    });

    productSelect.addEventListener('change', function(){
      state.productId = parseInt(this.value || '0', 10);
      loadPriceRules();
    });

    resourceSelect.addEventListener('change', function(){
      state.resourceId = parseInt(this.value || '0', 10);
      loadPriceRules();
    });

    fetchProducts().then(function(products){
      productSelect.innerHTML = '<option value="">' + __('Select a product', 'sbdp') + '</option>';
      products.forEach(function(product){
        const option = document.createElement('option');
        option.value = product.id;
        option.textContent = product.name + ' (ID ' + product.id + ')';
        productSelect.appendChild(option);
      });
    });

    fetchResources().then(function(resources){
      resourceSelect.innerHTML = '<option value="">' + __('All resources', 'sbdp') + '</option>';
      resources.forEach(function(resource){
        const option = document.createElement('option');
        option.value = resource.id;
        option.textContent = resource.title + ' (ID ' + resource.id + ')';
        resourceSelect.appendChild(option);
      });
    });

    const todayPricing = new Date().toISOString().slice(0, 10);
    if (previewDateInputPricing) {
      previewDateInputPricing.value = todayPricing;
    }
    if (previewResultArea) {
      previewResultArea.innerHTML = '<p class="sbdp-pricing-preview__empty">' + __('Adjust the inputs and click preview to see the calculated price.', 'sbdp') + '</p>';
    }

    renderRules();
  };

  $(function(){
    const availabilityContainer = document.getElementById('sbdp-av-app');
    if (availabilityContainer) {
      renderAvailabilityApp(availabilityContainer);
    }

    const pricingContainer = document.getElementById('sbdp-pricing-app');
    if (pricingContainer) {
      renderPricingApp(pricingContainer);
    }
  });

})(window.jQuery, window.wp && window.wp.i18n ? window.wp.i18n : null);



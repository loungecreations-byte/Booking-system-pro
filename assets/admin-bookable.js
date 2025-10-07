(function($){
  'use strict';

  const state = window.SBDP_BOOKABLE || {};
  const __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function(str){ return str; };

  $(function(){
    const $root = $('.sbdp-bookable-meta');
    if (!$root.length) {
      return;
    }

    document.body.classList.add('sbdp-bookable-meta-enabled');

    initTabs($root);
    enhanceTabsAccessibility($root);
    initRepeaters($root);
    initAvailability($root);
    initDuplicate($root, state);
    initMaps($root, state);
  });

  function initTabs($root){
    const $tabs = $root.find('.sbdp-bookable-tabs .nav-tab');
    $tabs.on('click', function(){
      const panelId = $(this).data('panel');
      if (!panelId) {
        return;
      }
      $tabs.removeClass('nav-tab-active').attr('aria-selected','false');
      $(this).addClass('nav-tab-active').attr('aria-selected','true');
      $root.find('.sbdp-tab-panel').attr('hidden', 'hidden').removeClass('is-active');
      $('#' + panelId).removeAttr('hidden').addClass('is-active');
    });
  }

  function resolveTemplate(type){
    let template = document.getElementById('sbdp-tpl-' + type);
    if (!template && type.slice(-1) === 's') {
      template = document.getElementById('sbdp-tpl-' + type.slice(0, -1));
    }
    return template;
  }

  function initRepeaters($root){
    $root.find('.sbdp-repeater').each(function(){
      const $repeater = $(this);
      const type = String($repeater.data('repeater') || '');
      const template = resolveTemplate(type);
      if (!template) {
        return;
      }

      $repeater.data('template-id', template.id);

      $repeater.on('click', '[data-repeater-add]', function(event){
        event.preventDefault();
        addRepeaterRow($repeater, type, template, {});
      });

      $repeater.on('click', '[data-repeater-remove]', function(event){
        event.preventDefault();
        const $row = $(this).closest('.sbdp-repeater__row');
        if ($row.length) {
          $row.remove();
        }
      });
    });
  }

  function addRepeaterRow($repeater, type, template, data){
    if (!template) {
      template = resolveTemplate(type);
      if (!template) {
        return;
      }
    }

    const $rows = $repeater.find('.sbdp-repeater__rows');
    let nextIndex = parseInt($repeater.attr('data-next-index'), 10);
    if (Number.isNaN(nextIndex) || nextIndex < 0) {
      nextIndex = $rows.children().length;
    }

    let html = template.innerHTML;
    html = html.replace(/__index__/g, String(nextIndex));
    if (type === 'availability' || type === 'availability-rules') {
      // handled elsewhere
    }

    const $fragment = $(html.trim());
    populateRowInputs($fragment, data);
    $rows.append($fragment);
    $repeater.attr('data-next-index', nextIndex + 1);
  }

  function populateRowInputs($row, data){
    if (!data || typeof data !== 'object') {
      return;
    }
    Object.keys(data).forEach(function(key){
      const value = data[key];
      const selector = '[name$="[' + key + ']"]';
      const $field = $row.find(selector);
      if (!$field.length) {
        return;
      }
      if ($field.is(':checkbox')) {
        $field.prop('checked', !!value);
      } else {
        $field.val(value != null ? value : '');
      }
    });
  }

  function initAvailability($root){
    const slotTemplate = document.getElementById('sbdp-tpl-availability-slot');
    if (!slotTemplate) {
      return;
    }

    $root.find('.sbdp-availability-day').each(function(){
      const $day = $(this);
      const dayKey = $day.data('day');

      $day.on('click','[data-add-slot]', function(event){
        event.preventDefault();
        addAvailabilitySlot($day, dayKey, slotTemplate, {});
      });

      $day.on('click','[data-remove-slot]', function(event){
        event.preventDefault();
        $(this).closest('.sbdp-availability-slot').remove();
      });
    });
  }

  function addAvailabilitySlot($day, dayKey, template, data){
    const $slots = $day.find('.sbdp-availability-slots');
    let nextIndex = parseInt($day.attr('data-next-index'), 10);
    if (Number.isNaN(nextIndex) || nextIndex < 0) {
      nextIndex = $slots.children().length;
    }
    let html = template.innerHTML.replace(/__day__/g, dayKey).replace(/__index__/g, String(nextIndex));
    const $slot = $(html.trim());
    if (data.start) {
      $slot.find('input[name$="[start]"]').val(data.start);
    }
    if (data.end) {
      $slot.find('input[name$="[end]"]').val(data.end);
    }
    $slots.append($slot);
    $day.attr('data-next-index', nextIndex + 1);
  }

  function initDuplicate($root, state){
    const $button = $('#sbdp-bookable-duplicate');
    if (!$button.length) {
      return;
    }

    $button.on('click', function(event){
      event.preventDefault();
      const productId = state.productId || 0;
      const promptText = state.i18n ? state.i18n.duplicate_prompt : __('Enter the product ID to duplicate booking settings from:', 'sbdp');
      const input = window.prompt(promptText);
      if (!input) {
        return;
      }
      const sourceId = parseInt(input, 10);
      if (!sourceId) {
        window.alert(__('Invalid product ID.', 'sbdp'));
        return;
      }

      $.post(state.ajaxUrl, {
        action: state.ajaxAction,
        nonce: state.ajaxNonce,
        source_id: sourceId,
        target_id: productId
      }).done(function(response){
        if (!response || !response.success || !response.data) {
          window.alert(state.i18n ? state.i18n.duplicate_failed : __('Duplication failed.', 'sbdp'));
          return;
        }
        if (response.data.meta) {
          applyMeta($root, response.data.meta);
        }
        window.alert(state.i18n ? state.i18n.duplicate_success : __('Settings duplicated.', 'sbdp'));
      }).fail(function(){
        window.alert(state.i18n ? state.i18n.duplicate_failed : __('Duplication failed.', 'sbdp'));
      });
    });
  }

  function applyMeta($root, meta){
    if (!meta) {
      return;
    }

    setFieldValue($root,'booking_duration_type', meta.booking_duration_type);
    setFieldValue($root,'booking_min_duration', meta.booking_min_duration);
    setFieldValue($root,'booking_max_duration', meta.booking_max_duration);
    setFieldValue($root,'booking_default_start_date', meta.booking_default_start_date);
    setFieldValue($root,'booking_default_start_time', meta.booking_default_start_time);
    setFieldValue($root,'booking_terms_max_per_unit', meta.booking_terms_max_per_unit);
    setFieldValue($root,'booking_min_advance', meta.booking_min_advance);
    setFieldValue($root,'booking_max_advance', meta.booking_max_advance);
    setFieldValue($root,'booking_checkin', meta.booking_checkin);
    setFieldValue($root,'booking_checkout', meta.booking_checkout);
    setFieldValue($root,'booking_buffer_time', meta.booking_buffer_time);
    setCheckboxValue($root,'booking_time_increment_based', meta.booking_time_increment_based);
    setCheckboxValue($root,'booking_requires_confirmation', meta.booking_requires_confirmation);
    setCheckboxValue($root,'booking_allow_cancellation', meta.booking_allow_cancellation);
    setCheckboxValue($root,'booking_sync_google_calendar', meta.booking_sync_google_calendar);
    setFieldValue($root,'booking_location', meta.booking_location);

    updateAllowedDays($root, meta.booking_allowed_start_days || []);

    setCheckboxValue($root,'people_enabled', meta.people_enabled);
    setCheckboxValue($root,'people_count_as_booking', meta.people_count_as_booking);
    setCheckboxValue($root,'people_type_enabled', meta.people_type_enabled);
    setFieldValue($root,'people_min', meta.people_min);
    setFieldValue($root,'people_max', meta.people_max);

    setFieldValue($root,'base_price', meta.base_price);
    setFieldValue($root,'fixed_fee', meta.fixed_fee);
    setFieldValue($root,'last_minute_discount', meta.last_minute_discount);
    setFieldValue($root,'last_minute_days_before', meta.last_minute_days_before);
    setCheckboxValue($root,'base_price_per_person', meta.base_price_per_person);
    setCheckboxValue($root,'fixed_fee_per_person', meta.fixed_fee_per_person);

    renderRepeaterWithData($root.find('[data-repeater="people-types"]'), 'people-types', meta.people_types || []);
    renderRepeaterWithData($root.find('[data-repeater="extra-costs"]'), 'extra-costs', meta.extra_costs || []);
    renderRepeaterWithData($root.find('[data-repeater="advanced-rules"]'), 'advanced-rules', meta.advanced_price_rules || []);
    renderRepeaterWithData($root.find('[data-repeater="availability-rules"]'), 'availability-rules', meta.additional_rules || []);

    renderAvailability($root, meta.default_availability || {});

    setFieldValue($root,'exclusions', meta.exclusions);
    setFieldValue($root,'permalink_override', meta.permalink_override);
  }

  function renderRepeaterWithData($repeater, type, rows){
    if (!$repeater.length) {
      return;
    }
    const template = resolveTemplate(type);
    if (!template) {
      return;
    }
    const $rows = $repeater.find('.sbdp-repeater__rows');
    $rows.empty();
    $repeater.attr('data-next-index', 0);
    (rows || []).forEach(function(row){
      addRepeaterRow($repeater, type, template, row);
    });
  }

  function renderAvailability($root, availability){
    const slotTemplate = document.getElementById('sbdp-tpl-availability-slot');
    if (!slotTemplate) {
      return;
    }
    $root.find('.sbdp-availability-day').each(function(){
      const $day = $(this);
      const dayKey = $day.data('day');
      const slots = availability && availability[dayKey] ? availability[dayKey] : [];
      const $slots = $day.find('.sbdp-availability-slots');
      $slots.empty();
      $day.attr('data-next-index', 0);
      (slots || []).forEach(function(slot){
        addAvailabilitySlot($day, dayKey, slotTemplate, slot);
      });
    });
  }

  function setFieldValue($root, key, value){
    const selector = '[name="sbdp_bookable[' + key + ']"]';
    const $field = $root.find(selector);
    if (!$field.length) {
      return;
    }
    if ($field.is(':checkbox')) {
      $field.prop('checked', !!value);
      return;
    }
    $field.val(value != null ? value : '');
  }

  function setCheckboxValue($root, key, value){
    const selector = '[name="sbdp_bookable[' + key + ']"]';
    const $field = $root.find(selector);
    if ($field.length) {
      $field.prop('checked', !!value);
    }
  }

  function updateAllowedDays($root, days){
    const values = Array.isArray(days) ? days : [];
    const $checkboxes = $root.find('input[name="sbdp_bookable[booking_allowed_start_days][]"]');
    $checkboxes.prop('checked', false);
    $checkboxes.each(function(){
      if (values.indexOf($(this).val()) !== -1) {
        $(this).prop('checked', true);
      }
    });
  }

  function initMaps($root, state){
    const apiKey = state.mapsApiKey || '';
    const $input = $('#sbdp-booking-location');
    const $mapContainer = $('#sbdp-booking-location-map');
    if (!apiKey || !$input.length || !$mapContainer.length) {
      if (!apiKey && $mapContainer.length) {
        const note = state.i18n ? state.i18n.maps_unavailable : __('Google Maps API key missing.', 'sbdp');
        $mapContainer.attr('data-note', note);
      }
      return;
    }

    loadGoogleMaps(apiKey).then(function(){
      const map = new google.maps.Map($mapContainer[0], {
        zoom: 13,
        center: { lat: 52.0907, lng: 5.1214 },
      });
      const marker = new google.maps.Marker({ map: map });
      const autocomplete = new google.maps.places.Autocomplete($input[0], {
        fields: ['geometry','formatted_address','name']
      });
      autocomplete.addListener('place_changed', function(){
        const place = autocomplete.getPlace();
        if (!place.geometry || !place.geometry.location) {
          return;
        }
        const location = place.geometry.location;
        map.setCenter(location);
        map.setZoom(15);
        marker.setPosition(location);
      });

      const initialValue = $input.val();
      if (initialValue) {
        const geocoder = new google.maps.Geocoder();
        geocoder.geocode({ address: initialValue }, function(results, status){
          if (status === 'OK' && results[0]) {
            map.setCenter(results[0].geometry.location);
            marker.setPosition(results[0].geometry.location);
          }
        });
      }
    }).catch(function(){
      console.warn('Failed to load Google Maps API');
    });
  }

  function loadGoogleMaps(apiKey){
    if (window.SBDPMapsPromise) {
      return window.SBDPMapsPromise;
    }
    window.SBDPMapsPromise = new Promise(function(resolve, reject){
      const script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey) + '&libraries=places';
      script.async = true;
      script.onload = function(){ resolve(); };
      script.onerror = reject;
      document.head.appendChild(script);
    });
    return window.SBDPMapsPromise;
  }

  function enhanceTabsAccessibility(){
    const  = .find('.sbdp-bookable-tabs .nav-tab');
    if (!.length) {
      return;
    }

    const  = .find('.sbdp-tab-panel');

    const applyState = function(, focus){
      if (! || !.length) {
        return;
      }
      .attr('tabindex', '-1');
      .attr('tabindex', '0');
      if (focus) {
        .trigger('focus');
      }
    };

    .each(function(){
      const  = ;
      .attr('role', 'tab');
      const panelId = .data('panel');
      if (panelId && !.attr('aria-controls')) {
        .attr('aria-controls', panelId);
      }
    });

    .each(function(){
      const  = ;
      .attr('role', 'tabpanel');
      const panelId = .attr('id');
      if (!panelId) {
        return;
      }
      const  = .filter(function(){
        return .data('panel') === panelId;
      }).first();
      if (.length && !.attr('aria-labelledby')) {
        .attr('aria-labelledby', .attr('id') || '');
      }
    });

    .on('click.sbdpEnhance', function(){
      applyState(, false);
    });

    .on('keydown.sbdpEnhance', function(event){
      if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
        return;
      }
      event.preventDefault();
      const index = .index(this);
      const direction = event.key === 'ArrowRight' ? 1 : -1;
      const nextIndex = (index + direction + .length) % .length;
      const  = .eq(nextIndex);
      .trigger('click');
      applyState(, true);
    });

    applyState(.filter('.nav-tab-active').first(), false);
  }
  function enhanceTabsAccessibility($root){
    const $tabs = $root.find('.sbdp-bookable-tabs .nav-tab');
    if (!$tabs.length) {
      return;
    }

    const $panels = $root.find('.sbdp-tab-panel');

    const applyState = function($tab, focus){
      if (!$tab || !$tab.length) {
        return;
      }
      $tabs.attr('tabindex', '-1');
      $tab.attr('tabindex', '0');
      if (focus) {
        $tab.trigger('focus');
      }
    };

    $tabs.each(function(){
      const $tab = $(this);
      $tab.attr('role', 'tab');
      const panelId = $tab.data('panel');
      if (panelId && !$tab.attr('id')) {
        $tab.attr('id', 'sbdp-tab-' + panelId);
      }
      if (panelId && !$tab.attr('aria-controls')) {
        $tab.attr('aria-controls', panelId);
      }
    });

    $panels.each(function(){
      const $panel = $(this);
      $panel.attr('role', 'tabpanel');
      const panelId = $panel.attr('id');
      if (!panelId) {
        return;
      }
      const $label = $tabs.filter(function(){
        return $(this).data('panel') === panelId;
      }).first();
      if ($label.length && !$panel.attr('aria-labelledby')) {
        $panel.attr('aria-labelledby', $label.attr('id') || '');
      }
    });

    $tabs.on('click.sbdpEnhance', function(){
      applyState($(this), false);
    });

    $tabs.on('keydown.sbdpEnhance', function(event){
      if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
        return;
      }
      event.preventDefault();
      const index = $tabs.index(this);
      const direction = event.key === 'ArrowRight' ? 1 : -1;
      const nextIndex = (index + direction + $tabs.length) % $tabs.length;
      const $next = $tabs.eq(nextIndex);
      $next.trigger('click');
      applyState($next, true);
    });

    applyState($tabs.filter('.nav-tab-active').first(), false);
  }
})(jQuery);




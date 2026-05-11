(function () {
  function applyThemeFromAdminScheme() {
    var dark = document.body.classList.contains('admin-color-midnight');
    document.body.setAttribute('data-ddb-theme', dark ? 'dark' : 'light');
  }

  function initDDBAdmin() {
    var root = document.getElementById('ddb-spots-editor');
    if (!root) return;
    if (root.getAttribute('data-ddb-initialized') === '1') return;
    root.setAttribute('data-ddb-initialized', '1');

    var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
  var panels = Array.prototype.slice.call(root.querySelectorAll('[role="tabpanel"]'));
  var settings = window.ddbSpotsAdmin || {};
  var storageKey = (settings.tabStorageKey || 'ddb_spots_tab') + ':' + (settings.postId || 0);

  function moveToSlot(slotId, sourceId) {
    var slot = root.querySelector('[data-ddb-slot="' + slotId + '"]');
    var source = document.getElementById(sourceId);
    if (!slot || !source || slot.contains(source)) return;
    slot.appendChild(source);
  }

  function moveFirstToSlot(slotId, sourceIds) {
    if (!Array.isArray(sourceIds)) return;
    for (var i = 0; i < sourceIds.length; i += 1) {
      var sourceId = sourceIds[i];
      if (document.getElementById(sourceId)) {
        moveToSlot(slotId, sourceId);
        return;
      }
    }
  }

  function initSlots() {
    moveFirstToSlot('titlediv', ['titlediv']);
    moveFirstToSlot('taxonomy-ddb_spot_type', ['taxonomy-ddb_spot_type', 'tagsdiv-ddb_spot_type', 'ddb_spot_typediv']);
    moveFirstToSlot('postexcerpt', ['postexcerpt']);
    moveFirstToSlot('postdivrich', ['postdivrich']);
    moveFirstToSlot('taxonomy-ddb_area', ['taxonomy-ddb_area', 'ddb_areadiv', 'categorydiv-ddb_area', 'tagsdiv-ddb_area']);
    moveFirstToSlot('taxonomy-ddb_tag', ['taxonomy-ddb_tag', 'tagsdiv-ddb_tag', 'ddb_tagdiv']);
    moveFirstToSlot('taxonomy-ddb_category', ['taxonomy-ddb_category', 'ddb_categorydiv', 'categorydiv-ddb_category', 'tagsdiv-ddb_category']);
    moveFirstToSlot('postimagediv', ['postimagediv']);
    moveFirstToSlot('ddb_spot_health', ['ddb_spot_health']);
    moveFirstToSlot('rank_math_metabox', ['rank_math_metabox']);
    moveFirstToSlot('rank_math_content_ai', ['rank_math_content_ai']);
  }

  function activeTypes() {
    var checkboxes = root.querySelectorAll('#taxonomy-ddb_spot_type input[type="checkbox"]');
    var selected = [];
    checkboxes.forEach(function (cb) {
      if (cb.checked) selected.push(cb.value);
    });
    return selected;
  }

  function syncTypeRows() {
    var selected = activeTypes();
    var rows = root.querySelectorAll('[data-ddb-types]');
    rows.forEach(function (row) {
      var accepts = (row.getAttribute('data-ddb-types') || '').split(',').map(function (s) {
        return s.trim();
      }).filter(Boolean);

      if (accepts.indexOf('all') !== -1) {
        row.hidden = false;
        return;
      }

      var show = selected.length === 0 ? true : accepts.some(function (type) {
        return selected.indexOf(type) !== -1;
      });
      row.hidden = !show;
    });
  }

  function activeProvider() {
    var select = root.querySelector('#ddb_booking_provider');
    return select ? select.value : 'none';
  }

  function syncProviderRows() {
    var provider = activeProvider();
    var rows = root.querySelectorAll('[data-ddb-provider]');
    rows.forEach(function (row) {
      var accepted = (row.getAttribute('data-ddb-provider') || '').split(',').map(function (item) {
        return item.trim();
      }).filter(Boolean);
      if (!accepted.length) {
        row.hidden = false;
        return;
      }
      row.hidden = accepted.indexOf(provider) === -1;
    });
  }

  function activateTab(tab, shouldFocus) {
    if (!tab) return;
    var name = tab.getAttribute('data-ddb-tab');

    tabs.forEach(function (item) {
      var active = item === tab;
      item.setAttribute('aria-selected', active ? 'true' : 'false');
      item.tabIndex = active ? 0 : -1;
    });

    panels.forEach(function (panel) {
      var active = panel.getAttribute('data-ddb-panel') === name;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
    });

    try {
      localStorage.setItem(storageKey, name);
    } catch (e) {
      // Ignore storage errors.
    }

    if (shouldFocus) {
      tab.focus();
    }
  }

  function tabByName(name) {
    for (var i = 0; i < tabs.length; i += 1) {
      if (tabs[i].getAttribute('data-ddb-tab') === name) return tabs[i];
    }
    return null;
  }

  function activateByName(name) {
    var alias = {
      basis: 'essentials',
      content: 'essentials',
      booking: 'essentials',
      location: 'daylogic',
      advanced: 'health',
      seo: 'health',
      premium: 'bundles'
    };
    if (alias[name]) {
      name = alias[name];
    }
    var tab = tabByName(name);
    if (tab) activateTab(tab, false);
  }

  function isPublishIntentButton(button) {
    if (!button) return false;
    if (button.id === 'publish') return true;
    var className = String(button.className || '');
    return className.indexOf('editor-post-publish') !== -1;
  }

  function focusFailure(failure) {
    if (!failure) return;
    if (failure.fix_tab) activateByName(failure.fix_tab);
    if (failure.fix_focus) {
      window.setTimeout(function () {
        var focusEl = document.getElementById(failure.fix_focus);
        if (focusEl && typeof focusEl.focus === 'function') focusEl.focus();
      }, 80);
    }
  }

  function clickWithBypass(button, state) {
    state.bypass = true;
    window.setTimeout(function () {
      if (typeof button.click === 'function') {
        button.click();
      } else if (button.form && typeof button.form.submit === 'function') {
        button.form.submit();
      }
    }, 0);
  }

  function prePublishValidationRequest() {
    var ajaxUrl = settings.ajaxUrl || window.ajaxurl;
    if (!ajaxUrl || typeof window.fetch !== 'function') {
      return Promise.resolve({ ok: true, hard_block: false, failures: [] });
    }

    var postId = parseInt(settings.postId || 0, 10);
    if (!postId) {
      return Promise.resolve({ ok: true, hard_block: false, failures: [] });
    }

    var body = new URLSearchParams();
    body.append('action', settings.prePublishAction || 'ddb_spots_prepublish_validate');
    body.append('nonce', settings.prePublishNonce || '');
    body.append('post_id', String(postId));
    body.append('area_term_count', String(getSelectedAreaCount()));
    body.append('excerpt_length', String(getExcerptLength()));
    body.append('content_length', String(getContentLength()));

    return fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || payload.success !== true || !payload.data) {
          return { ok: true, hard_block: false, failures: [] };
        }
        return {
          ok: !!payload.data.ok,
          hard_block: !!payload.data.hard_block,
          failures: Array.isArray(payload.data.failures) ? payload.data.failures : []
        };
      })
      .catch(function () {
        return { ok: true, hard_block: false, failures: [] };
      });
  }

  function getSelectedAreaCount() {
    var quickSelect = document.getElementById('ddb_area_term_id');
    if (quickSelect && String(quickSelect.value || '0') !== '0') {
      return 1;
    }

    var boxes = [
      '#taxonomy-ddb_area input[type="checkbox"]',
      '#ddb_areadiv input[type="checkbox"]',
      '#categorydiv-ddb_area input[type="checkbox"]',
      '#tagsdiv-ddb_area input[type="checkbox"]'
    ];

    var count = 0;
    boxes.forEach(function (selector) {
      var checks = root.querySelectorAll(selector);
      checks.forEach(function (cb) {
        if (cb.checked) count += 1;
      });
    });
    return count;
  }

  function getExcerptLength() {
    var excerpt = document.getElementById('excerpt');
    if (!excerpt) return 0;
    return String(excerpt.value || '').trim().length;
  }

  function stripHtmlToText(value) {
    return String(value || '')
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function getContentLength() {
    if (window.tinymce && typeof window.tinymce.get === 'function') {
      var editor = window.tinymce.get('content');
      if (editor && typeof editor.getContent === 'function') {
        var text = String(editor.getContent({ format: 'text' }) || '').replace(/\s+/g, ' ').trim();
        if (text.length > 0) return text.length;
      }
    }
    var content = document.getElementById('content');
    if (!content) return 0;
    return stripHtmlToText(content.value).length;
  }

  var prePublishState = {
    checking: false,
    bypass: false
  };

  document.addEventListener('click', function (event) {
    var target = event.target;
    var button = target && target.closest ? target.closest('button, input[type="submit"]') : null;
    if (!isPublishIntentButton(button)) return;

    if (prePublishState.bypass) {
      prePublishState.bypass = false;
      return;
    }
    if (prePublishState.checking) {
      event.preventDefault();
      return;
    }

    event.preventDefault();
    prePublishState.checking = true;

    prePublishValidationRequest().then(function (result) {
      if (!result || result.ok) {
        clickWithBypass(button, prePublishState);
        return;
      }

      var labels = result.failures.map(function (failure) {
        return '- ' + (failure.label || '');
      }).filter(Boolean);

      if (result.hard_block) {
        window.alert('Publicatie geblokkeerd door kritieke Spot Health checks:\n' + labels.join('\n'));
        focusFailure(result.failures[0]);
        return;
      }

      var confirmMessage = 'Kritieke Spot Health checks niet gehaald:\n' + labels.join('\n') + '\n\nToch publiceren?';
      if (window.confirm(confirmMessage)) {
        clickWithBypass(button, prePublishState);
      } else {
        focusFailure(result.failures[0]);
      }
    }).finally(function () {
      prePublishState.checking = false;
    });
  }, true);

  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function () {
      activateTab(tab, false);
    });

    tab.addEventListener('keydown', function (event) {
      var nextIndex = index;
      if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
      if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
      if (event.key === 'Home') nextIndex = 0;
      if (event.key === 'End') nextIndex = tabs.length - 1;
      if (nextIndex !== index) {
        event.preventDefault();
        activateTab(tabs[nextIndex], true);
      }
    });
  });

  document.addEventListener('change', function (event) {
    if (event.target && event.target.closest('#taxonomy-ddb_spot_type')) {
      syncTypeRows();
    }
    if (event.target && event.target.id === 'ddb_booking_provider') {
      syncProviderRows();
    }
  });

  document.addEventListener('click', function (event) {
    var fix = event.target.closest('[data-ddb-tab-link]');
    if (!fix) return;
    event.preventDefault();
    var tabName = fix.getAttribute('data-ddb-tab-link');
    activateByName(tabName);
    var focusId = fix.getAttribute('data-ddb-focus');
    if (focusId) {
      var focusEl = document.getElementById(focusId);
      if (focusEl && typeof focusEl.focus === 'function') focusEl.focus();
    }
  });

  function getFieldValue(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '').trim() : '';
  }

  function splitLines(raw) {
    return String(raw || '')
      .split(/\r?\n/)
      .map(function (line) { return line.trim(); })
      .filter(Boolean);
  }

  function getSpotTypeLabel() {
    var selected = activeTypes();
    if (!selected.length) return 'spot';
    var type = selected[0];
    if (type === 'restaurants') return 'restaurant';
    if (type === 'events') return 'event';
    if (type === 'hotels') return 'hotel';
    return type;
  }

  function buildPrompt(context) {
    return [
      'Schrijf in het Nederlands een conversiegerichte Spot-pagina tekst voor DagjeDenBosch.',
      '',
      'Context:',
      '- Titel: ' + context.title,
      '- Type: ' + context.type,
      '- Stad: ' + context.city,
      '- Adres: ' + context.address,
      '- Provider: ' + context.provider,
      '- Tone of voice: ' + context.tone,
      '- Waarom nu: ' + context.angle,
      '- Dag-context: ' + (context.dayfit.length ? context.dayfit.join(' | ') : 'n.v.t.'),
      '- Bewijs/USP: ' + (context.proof.length ? context.proof.join(' | ') : 'n.v.t.'),
      '',
      'Output format (exact in deze volgorde):',
      '1) Excerpt van 80-120 woorden, scanbaar.',
      '2) Sectie "Waarom dit in je dag past" met 3 bullets.',
      '3) Sectie "Wat je krijgt" met 3 korte alinea\'s.',
      '4) Krachtige CTA-regel naar reserveren of toevoegen aan dag.',
      '',
      'Regels:',
      '- Geen verzonnen feiten.',
      '- Korte zinnen, actief taalgebruik.',
      '- Geen superlatieven zonder onderbouwing.',
      '- Focus op planning-context en conversie.'
    ].join('\n');
  }

  function buildDraft(context) {
    var toneMap = {
      fris: 'direct en energiek',
      premium: 'rustig, stijlvol en overtuigend',
      familie: 'warm en toegankelijk'
    };
    var toneLine = toneMap[context.tone] || 'duidelijk en overtuigend';
    var lead = context.angle || (context.title + ' is een sterke stop in ' + (context.city || 'de stad') + '.');

    var dayfit = context.dayfit.length ? context.dayfit : [
      'Past logisch als stop in je route door de binnenstad',
      'Makkelijk te combineren met een tweede activiteit',
      'Geschikt voor spontane planning zonder omweg'
    ];
    var proof = context.proof.length ? context.proof : [
      'Centrale ligging',
      'Consistente bezoekerservaring',
      'Duidelijke call-to-action voor de volgende stap'
    ];

    return [
      lead,
      '',
      'Waarom dit in je dag past',
      '- ' + dayfit.slice(0, 3).join('\n- '),
      '',
      'Wat je krijgt',
      context.title + ' is geschreven in een ' + toneLine + ' stijl, met focus op snelle keuzehulp.',
      'Je vindt hier de belangrijkste informatie compact: locatie, timing en wat je direct kunt doen.',
      'Door de combinatie van praktische data en belevingstekst voelt deze plek meteen planbaar aan.',
      '',
      'Sterke punten',
      '- ' + proof.slice(0, 3).join('\n- '),
      '',
      'CTA',
      'Voeg deze spot toe aan je dag en regel direct je volgende stap.'
    ].join('\n');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function plainTextToHtml(value) {
    var blocks = String(value || '')
      .replace(/\r\n/g, '\n')
      .split(/\n{2,}/)
      .map(function (block) { return block.trim(); })
      .filter(Boolean);

    if (!blocks.length) return '';
    return blocks.map(function (block) {
      return '<p>' + escapeHtml(block).replace(/\n/g, '<br>') + '</p>';
    }).join('');
  }

  function setEditorContent(text) {
    var value = String(text || '');
    var contentField = document.getElementById('content');
    var html = plainTextToHtml(value);
    var changed = false;

    // 1) Prefer WordPress old editor API when available.
    if (window.wp && window.wp.oldEditor && typeof window.wp.oldEditor.setContent === 'function') {
      try {
        window.wp.oldEditor.setContent('content', html || value);
        changed = true;
      } catch (e) {
        // Ignore and continue with other fallbacks.
      }
    }

    // 2) TinyMCE visual editor fallback.
    if (window.tinyMCE && typeof window.tinyMCE.get === 'function') {
      var editor = window.tinyMCE.get('content');
      if (editor) {
        try {
          editor.setContent(html || value, { format: 'raw' });
          if (typeof editor.save === 'function') editor.save();
          changed = true;
        } catch (e) {
          // Ignore and continue with textarea fallback.
        }
      }
    }

    // 3) Raw textarea fallback (text mode / save safety).
    if (contentField) {
      contentField.value = value;
      contentField.dispatchEvent(new Event('input', { bubbles: true }));
      contentField.dispatchEvent(new Event('change', { bubbles: true }));
      changed = true;
    }

    // Keep textarea in sync with TinyMCE before save.
    if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') {
      try {
        window.tinyMCE.triggerSave();
      } catch (e) {
        // Ignore triggerSave errors.
      }
    }

    return changed;
  }

  function getDraftContext() {
    return {
      title: getFieldValue('title') || document.title || 'Spot',
      type: getSpotTypeLabel(),
      city: getFieldValue('ddb_city'),
      address: getFieldValue('ddb_address'),
      provider: getFieldValue('ddb_booking_provider') || 'none',
      tone: getFieldValue('ddb_composer_tone') || 'fris',
      angle: getFieldValue('ddb_composer_angle'),
      dayfit: splitLines(getFieldValue('ddb_composer_dayfit')),
      proof: splitLines(getFieldValue('ddb_composer_proof'))
    };
  }

  function deriveExcerpt(context, draft) {
    var excerpt = context.angle || draft.split('\n')[0] || '';
    excerpt = excerpt.replace(/\s+/g, ' ').trim();
    if (excerpt.length > 190) excerpt = excerpt.slice(0, 187) + '...';
    return excerpt;
  }

  function copyText(value) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(value);
      return;
    }
    var tmp = document.createElement('textarea');
    tmp.value = value;
    document.body.appendChild(tmp);
    tmp.select();
    document.execCommand('copy');
    document.body.removeChild(tmp);
  }

  function initComposer() {
    var composer = document.getElementById('ddb-content-composer');
    if (!composer) return;

    var promptEl = document.getElementById('ddb_composer_prompt');
    var draftEl = document.getElementById('ddb_composer_draft');
    var excerptEl = document.getElementById('excerpt');
    var highlightsEl = document.getElementById('ddb_spot_highlights');

    var btnPrompt = document.getElementById('ddb-composer-generate-prompt');
    var btnDraft = document.getElementById('ddb-composer-generate-draft');
    var btnCopyPrompt = document.getElementById('ddb-composer-copy-prompt');
    var btnApplyExcerpt = document.getElementById('ddb-composer-apply-excerpt');
    var btnApplyContent = document.getElementById('ddb-composer-apply-content');
    var btnApplyHighlights = document.getElementById('ddb-composer-apply-highlights');

    if (btnPrompt) {
      btnPrompt.addEventListener('click', function () {
        var context = getDraftContext();
        var prompt = buildPrompt(context);
        if (promptEl) promptEl.value = prompt;
      });
    }

    if (btnDraft) {
      btnDraft.addEventListener('click', function () {
        var context = getDraftContext();
        var draft = buildDraft(context);
        if (draftEl) draftEl.value = draft;
        if (promptEl && !promptEl.value.trim()) {
          promptEl.value = buildPrompt(context);
        }
      });
    }

    if (btnCopyPrompt) {
      btnCopyPrompt.addEventListener('click', function () {
        if (!promptEl || !promptEl.value.trim()) return;
        copyText(promptEl.value);
      });
    }

    if (btnApplyExcerpt) {
      btnApplyExcerpt.addEventListener('click', function () {
        var context = getDraftContext();
        var draft = draftEl ? draftEl.value : '';
        var excerpt = deriveExcerpt(context, draft);
        if (excerptEl) excerptEl.value = excerpt;
      });
    }

    if (btnApplyContent) {
      btnApplyContent.addEventListener('click', function () {
        var source = '';
        if (draftEl && draftEl.value.trim()) {
          source = draftEl.value;
        } else if (promptEl && promptEl.value.trim()) {
          source = promptEl.value;
        }
        if (!source) return;

        var ok = setEditorContent(source);
        if (!ok) {
          window.alert('Kon contentveld niet vinden. Open de Content-tab en probeer opnieuw.');
        }
      });
    }

    if (btnApplyHighlights) {
      btnApplyHighlights.addEventListener('click', function () {
        var context = getDraftContext();
        var lines = context.dayfit.concat(context.proof).slice(0, 8);
        if (highlightsEl) highlightsEl.value = lines.join('\n');
      });
    }
  }

  initSlots();
  syncTypeRows();
  syncProviderRows();
  initComposer();

  // Handle Tab Clicks
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function (e) {
      e.preventDefault();
      activateTab(tab, true);
    });
  });

  var initial = 'essentials';
  try {
    var stored = localStorage.getItem(storageKey);
    if (stored) initial = stored;
  } catch (e) {
    // Ignore storage errors.
  }
  if (window.location.hash && window.location.hash.indexOf('#ddb-tab-') === 0) {
    initial = window.location.hash.replace('#ddb-tab-', '');
  }
  activateByName(initial);

  // Dynamic Listeners for Contextual UI
  var providerSelect = document.getElementById('ddb_booking_provider');
  if (providerSelect) {
    providerSelect.addEventListener('change', syncProviderRows);
  }
  var typeCheckboxes = root.querySelectorAll('#taxonomy-ddb_spot_type input[type="checkbox"]');
  typeCheckboxes.forEach(function (cb) {
    cb.addEventListener('change', syncTypeRows);
  });
  


  // ### Media Controllers Start ###
  function initMediaControllers() {
    var mediaBtns = root.querySelectorAll('.ddb-media-select-btn');
    if (!mediaBtns.length) return;

    mediaBtns.forEach(function (btn) {
      if (btn.dataset.mediaInitialized) return;
      btn.dataset.mediaInitialized = 'true';

      btn.addEventListener('click', function (e) {
        e.preventDefault();

        var inputId = btn.dataset.input;
        var previewId = btn.dataset.preview;
        var isMultiple = btn.dataset.multiple === 'true';

        var frame = wp.media({
          title: 'Selecteer Afbeelding(en)',
          button: { text: 'Gebruik deze afbeelding(en)' },
          multiple: isMultiple
        });

        frame.on('select', function () {
          var attachments = frame.state().get('selection').toJSON();
          var inputField = document.getElementById(inputId);
          if (!inputField) return;

          if (isMultiple) {
            var existingIds = inputField.value ? inputField.value.split(',') : [];
            attachments.forEach(function (att) {
              if (existingIds.indexOf(att.id.toString()) === -1) {
                existingIds.push(att.id);
              }
            });
            inputField.value = existingIds.join(',');
          } else {
            inputField.value = attachments[0].id;
          }
          renderMediaPreview(inputId, previewId, isMultiple);
        });

        frame.open();
      });
    });

    renderMediaPreview('ddb_gallery_ids', 'ddb-gallery-preview', true);
    renderMediaPreview('ddb_logo_id', 'ddb-logo-preview', false);
  }

  function renderMediaPreview(inputId, previewId, isMultiple) {
    if (!jQuery || !ddbSpotsAdmin || !ddbSpotsAdmin.ajaxUrl) return;

    var inputField = document.getElementById(inputId);
    var previewContainer = document.getElementById(previewId);
    if (!inputField || !previewContainer) return;

    var ids = inputField.value;
    if (!ids) {
      previewContainer.innerHTML = '';
      return;
    }

    previewContainer.innerHTML = '<span class="spinner is-active" style="float:none;"></span> Laden...';

    jQuery.post(ddbSpotsAdmin.ajaxUrl, {
      action: 'ddb_spots_get_media_preview',
      ids: ids
    }, function(res) {
      if (res.success && res.data && res.data.length > 0) {
        var html = '';
        res.data.forEach(function(item) {
           html += '<div class="ddb-media-thumb" style="display:inline-block; margin:5px; position:relative;" data-id="' + item.id + '">' +
                   '<img src="' + item.url + '" alt="Preview" style="max-width:100px; height:auto; display:block;" />' +
                   '<button type="button" class="ddb-media-remove button-link" style="position:absolute; top:-5px; right:-5px; background:white; border-radius:50%; width:20px; height:20px; text-align:center; padding:0; line-height:20px; color:red; border:1px solid #ccc; font-weight:bold; cursor:pointer;" data-id="' + item.id + '">&times;</button>' +
                   '</div>';
        });
        previewContainer.innerHTML = html;

        if (isMultiple && typeof jQuery(previewContainer).sortable === 'function') {
          jQuery(previewContainer).sortable({
            update: function() {
              var newIds = [];
              jQuery(previewContainer).find('.ddb-media-thumb').each(function() {
                newIds.push(jQuery(this).data('id'));
              });
              inputField.value = newIds.join(',');
            }
          });
        }

        var removeBtns = previewContainer.querySelectorAll('.ddb-media-remove');
        removeBtns.forEach(function(btn) {
          btn.addEventListener('click', function(e) {
            e.preventDefault();
            var removeId = btn.dataset.id.toString();
            var currentIds = inputField.value.split(',');
            var index = currentIds.indexOf(removeId);
            if (index !== -1) {
              currentIds.splice(index, 1);
            }
            inputField.value = currentIds.join(',');
            renderMediaPreview(inputId, previewId, isMultiple);
          });
        });
      } else {
        previewContainer.innerHTML = 'Kan afbeelding niet laden.';
      }
    });
  }

  initMediaControllers();
  // ### Media Controllers End ###

  } // End of initDDBAdmin

  function initGoogleImportSelection() {
    var forms = document.querySelectorAll('.ddb-google-import-results-form');
    if (!forms.length) return;

    forms.forEach(function (form) {
      if (form.getAttribute('data-ddb-select-init') === '1') return;
      form.setAttribute('data-ddb-select-init', '1');

      var rowChecks = Array.prototype.slice.call(form.querySelectorAll('.ddb-place-select'));
      var headerToggle = form.querySelector('.ddb-select-all-toggle');
      var selectAllBtn = form.querySelector('.ddb-select-all-places');
      var clearBtn = form.querySelector('.ddb-clear-all-places');

      if (!rowChecks.length) return;

      function setAll(checked) {
        rowChecks.forEach(function (cb) {
          cb.checked = checked;
        });
        syncHeader();
      }

      function syncHeader() {
        if (!headerToggle) return;
        var checkedCount = rowChecks.filter(function (cb) {
          return cb.checked;
        }).length;
        headerToggle.checked = checkedCount > 0 && checkedCount === rowChecks.length;
        headerToggle.indeterminate = checkedCount > 0 && checkedCount < rowChecks.length;
      }

      if (headerToggle) {
        headerToggle.addEventListener('change', function () {
          setAll(headerToggle.checked);
        });
      }

      if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
          setAll(true);
        });
      }

      if (clearBtn) {
        clearBtn.addEventListener('click', function () {
          setAll(false);
        });
      }

      rowChecks.forEach(function (cb) {
        cb.addEventListener('change', syncHeader);
      });

      syncHeader();
    });
  }

  function initGoogleImportRetry() {
    var retryNotice = document.querySelector('.ddb-google-import-retry');
    if (!retryNotice || retryNotice.getAttribute('data-ddb-retry-init') === '1') return;
    retryNotice.setAttribute('data-ddb-retry-init', '1');

    var retryUrl = retryNotice.getAttribute('data-retry-url');
    var retryDelay = parseInt(retryNotice.getAttribute('data-retry-delay') || '4', 10);
    if (!retryUrl || !retryDelay || retryDelay < 1) return;

    var textNode = retryNotice.querySelector('p');
    var remaining = retryDelay;

    function updateCopy() {
      if (!textNode) return;
      textNode.textContent = 'Automatisch opnieuw proberen over ' + remaining + ' seconde' + (remaining === 1 ? '' : 'n') + '...';
    }

    updateCopy();

    var interval = window.setInterval(function () {
      remaining -= 1;
      if (remaining <= 0) {
        window.clearInterval(interval);
        window.location.href = retryUrl;
        return;
      }
      updateCopy();
    }, 1000);
  }

  applyThemeFromAdminScheme();
  
  // Run on load
  document.addEventListener('DOMContentLoaded', initDDBAdmin);
  document.addEventListener('DOMContentLoaded', initGoogleImportSelection);
  document.addEventListener('DOMContentLoaded', initGoogleImportRetry);
  initDDBAdmin(); // In case it's a standard page load where DOM is already ready
  initGoogleImportSelection();
  initGoogleImportRetry();
  
  // Poll for Gutenberg
  var pollCount = 0;
  var pollInterval = setInterval(function () {
    pollCount++;
    if (document.getElementById('ddb-spots-editor')) {
      initDDBAdmin();
      clearInterval(pollInterval);
    } else if (pollCount > 40) {
      clearInterval(pollInterval);
    }
  }, 250);

})();





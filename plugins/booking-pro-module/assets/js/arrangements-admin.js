(() => {
  const root = document.querySelector("[data-sbdp-arrangement-editor]");
  if (!root) return;

  const config = window.SBDP_ARRANGEMENTS_ADMIN || {};
  const strings = config.strings || {};
  const tabs = Array.from(root.querySelectorAll("[data-tab]"));
  const panels = Array.from(root.querySelectorAll("[data-panel]"));
  const segmentsBody = root.querySelector("#sbdp-arrangement-segments");
  const template = root.querySelector("#sbdp-arrangement-segment-template");
  const addButtons = Array.from(root.querySelectorAll("[data-add-segment]"));

  const activateTab = (name) => {
    tabs.forEach((tab) => {
      tab.classList.toggle("is-active", tab.getAttribute("data-tab") === name);
    });
    panels.forEach((panel) => {
      const active = panel.getAttribute("data-panel") === name;
      panel.hidden = !active;
      panel.classList.toggle("is-active", active);
    });
  };

  const roleLabels = {
    anchor: "Hoofdactiviteit",
    pre: "Vooraf",
    post: "Achteraf",
  };

  const renderSnapshot = (snapshot) => {
    if (!snapshot || !snapshot.id) {
      return `<p class="sbdp-arrangement-product-picker__empty">${strings.invalid || "Geen geldig Woo product gekoppeld."}</p>`;
    }

    const meta = [
      snapshot.price_label,
      snapshot.tax_label,
      snapshot.duration_label,
      snapshot.people_label,
      snapshot.availability_label,
    ].filter(Boolean);

    return `
      <div class="sbdp-arrangement-product-card">
        <strong class="sbdp-arrangement-product-card__title">${escapeHtml(snapshot.title || "")}</strong>
        <span class="sbdp-arrangement-product-card__meta">#${escapeHtml(String(snapshot.id || ""))} · ${escapeHtml(snapshot.type || "")}</span>
        <div class="sbdp-arrangement-product-card__chips">
          ${meta.map((item) => `<span class="sbdp-arrangement-product-card__chip">${escapeHtml(item)}</span>`).join("")}
        </div>
        ${snapshot.edit_url ? `<a class="sbdp-arrangement-product-card__link" href="${escapeAttribute(snapshot.edit_url)}">Open Woo product</a>` : ""}
      </div>
    `;
  };

  const renderResults = (picker, results) => {
    const resultsNode = picker.querySelector("[data-product-results]");
    if (!resultsNode) return;

    if (!Array.isArray(results) || results.length === 0) {
      resultsNode.innerHTML = `<div class="sbdp-arrangement-product-picker__result is-empty">${escapeHtml(strings.empty || "Geen boekbare producten gevonden.")}</div>`;
      resultsNode.hidden = false;
      return;
    }

    resultsNode.innerHTML = results
      .map(
        (item) => `
          <button
            type="button"
            class="sbdp-arrangement-product-picker__result"
            data-product-option
            data-product='${escapeAttribute(JSON.stringify(item))}'
          >
            <strong>${escapeHtml(item.title || "")}</strong>
            <span>${escapeHtml([item.price_label, item.duration_label, item.availability_label].filter(Boolean).join(" · "))}</span>
          </button>
        `
      )
      .join("");
    resultsNode.hidden = false;
  };

  const applySelection = (picker, snapshot) => {
    const row = picker.closest("[data-segment-row]");
    const hiddenInput = picker.querySelector("[data-product-id-input]");
    const searchInput = picker.querySelector("[data-product-search]");
    const snapshotNode = picker.querySelector("[data-product-snapshot]");
    const resultsNode = picker.querySelector("[data-product-results]");

    if (hiddenInput) {
      hiddenInput.value = snapshot && snapshot.id ? String(snapshot.id) : "0";
    }
    if (searchInput) {
      searchInput.value = snapshot && snapshot.title ? snapshot.title : "";
    }
    if (snapshotNode) {
      snapshotNode.innerHTML = snapshot
        ? renderSnapshot(snapshot)
        : `<p class="sbdp-arrangement-product-picker__empty">Nog geen boekbaar product gekoppeld.</p>`;
    }
    if (resultsNode) {
      resultsNode.hidden = true;
      resultsNode.innerHTML = "";
    }

    if (row && snapshot) {
      const titleField = row.querySelector("[data-segment-title]");
      const durationField = row.querySelector("[data-segment-duration]");
      if (titleField && !titleField.value.trim()) {
        titleField.value = snapshot.title || strings.newSegment || "Nieuw onderdeel";
      }
      if (durationField && (!durationField.value || parseInt(durationField.value, 10) <= 0) && snapshot.duration_minutes) {
        durationField.value = String(snapshot.duration_minutes);
      }
    }

    updatePreview();
  };

  const lookupProducts = (() => {
    let controller = null;

    return async (query) => {
      if (!config.ajaxUrl || !config.lookupAction || !config.lookupNonce) {
        return [];
      }

      if (controller) {
        controller.abort();
      }

      controller = new AbortController();
      const body = new URLSearchParams();
      body.set("action", config.lookupAction);
      body.set("nonce", config.lookupNonce);
      body.set("query", query || "");

      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: body.toString(),
        signal: controller.signal,
      });

      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload || !payload.success) {
        return [];
      }

      return Array.isArray(payload.data?.results) ? payload.data.results : [];
    };
  })();

  const suggestProducts = async (productId) => {
    if (!config.ajaxUrl || !config.suggestAction || !config.lookupNonce || !productId) {
      return [];
    }

    const body = new URLSearchParams();
    body.set("action", config.suggestAction);
    body.set("nonce", config.lookupNonce);
    body.set("product_id", String(productId));

    const response = await fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: body.toString(),
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload || !payload.success) {
      return [];
    }

    return Array.isArray(payload.data?.results) ? payload.data.results : [];
  };

  const initializeProductPicker = (picker) => {
    if (!picker || picker.dataset.initialized === "1") return;
    picker.dataset.initialized = "1";

    const searchInput = picker.querySelector("[data-product-search]");
    const resultsNode = picker.querySelector("[data-product-results]");
    const clearButton = picker.querySelector("[data-clear-product]");
    let debounceId = null;

    const runLookup = (query) => {
      if (!resultsNode) return;
      resultsNode.hidden = false;
      resultsNode.innerHTML = `<div class="sbdp-arrangement-product-picker__result is-empty">${escapeHtml(strings.searching || "Zoeken...")}</div>`;

      lookupProducts(query)
        .then((results) => renderResults(picker, results))
        .catch(() => renderResults(picker, []));
    };

    if (searchInput) {
      searchInput.addEventListener("focus", () => {
        if (!searchInput.value.trim()) {
          runLookup("");
        }
      });

      searchInput.addEventListener("input", () => {
        const query = searchInput.value.trim();
        window.clearTimeout(debounceId);
        debounceId = window.setTimeout(() => runLookup(query), 180);
      });
    }

    if (clearButton) {
      clearButton.addEventListener("click", () => applySelection(picker, null));
    }

    picker.addEventListener("click", (event) => {
      const option = event.target.closest("[data-product-option]");
      if (!option) return;
      const raw = option.getAttribute("data-product");
      if (!raw) return;

      try {
        applySelection(picker, JSON.parse(raw));
      } catch (error) {
        applySelection(picker, null);
      }
    });

    document.addEventListener("click", (event) => {
      if (!picker.contains(event.target) && resultsNode) {
        resultsNode.hidden = true;
      }
    });
  };

  const applyPresetToRow = (row, preset) => {
    const roleField = row.querySelector("[data-segment-role]");
    const timingModeField = row.querySelector("[data-segment-timing-mode]");
    if (roleField) {
      roleField.value = preset || "post";
    }
    if (timingModeField) {
      timingModeField.value = preset === "anchor" ? "fixed_start" : (preset === "pre" ? "before_next" : "after_previous");
    }
  };

  const cloneCurrentRow = (row) => {
    if (!segmentsBody || !row) return;
    const clone = row.cloneNode(true);
    clone.querySelectorAll("[data-product-results]").forEach((node) => {
      node.hidden = true;
      node.innerHTML = "";
    });
    clone.querySelectorAll("[data-product-picker]").forEach((picker) => {
      picker.dataset.initialized = "0";
    });
    segmentsBody.insertBefore(clone, row.nextSibling);
    initializePickers(clone);
    reindexRows();
    updatePreview();
  };

  const reindexRows = () => {
    if (!segmentsBody) return;

    const rows = Array.from(segmentsBody.querySelectorAll("[data-segment-row]"));
    rows.forEach((row, index) => {
      row.setAttribute("data-segment-index", String(index));
      row.querySelectorAll("input, select, textarea").forEach((field) => {
        const name = field.getAttribute("name");
        if (!name) return;
        field.setAttribute("name", name.replace(/segments\]\[(\d+|__INDEX__)\]/, `segments][${index}]`));
      });
      const seq = row.querySelector("[data-segment-sequence]");
      if (seq) {
        seq.value = String(index);
      }
    });
  };

  const refreshRoleBadges = () => {
    root.querySelectorAll("[data-segment-row]").forEach((row) => {
      const roleField = row.querySelector("[data-segment-role]");
      const badge = row.querySelector("[data-role-badge]");
      const role = roleField ? roleField.value : "post";
      row.dataset.role = role;
      if (badge) {
        badge.textContent = roleLabels[role] || roleLabels.post;
      }
    });
  };

  const getRows = () => Array.from(root.querySelectorAll("[data-segment-row]"));

  const updatePreview = () => {
    const previewList = root.querySelector("[data-preview-list]");
    const previewHealth = root.querySelector("[data-preview-health]");
    const previewWindow = root.querySelector("[data-preview-window]");
    const previewStatusPill = root.querySelector(".sbdp-arrangement-preview__status-pill");
    if (!previewList || !previewHealth || !previewWindow || !previewStatusPill) return;

    const rows = getRows().map((row) => {
      const title = row.querySelector("[data-segment-title]")?.value.trim() || row.querySelector("[data-product-search]")?.value.trim() || "Nieuw onderdeel";
      const role = row.querySelector("[data-segment-role]")?.value || "post";
      const start = row.querySelector("[data-segment-start]")?.value.trim() || "";
      const timingMode = row.querySelector("[data-segment-timing-mode]")?.value || "after_previous";
      const offset = parseInt(row.querySelector("[data-segment-offset]")?.value || "0", 10) || 0;
      const duration = parseInt(row.querySelector("[data-segment-duration]")?.value || "0", 10) || 0;
      const productId = parseInt(row.querySelector("[data-product-id-input]")?.value || "0", 10) || 0;
      const hidden = row.querySelector('input[name*="[is_hidden]"]')?.checked || false;
      return { title, role, start, timingMode, offset, duration, productId, hidden };
    });

    const visibleRows = rows.filter((row) => !row.hidden);
    const anchorCount = visibleRows.filter((row) => row.role === "anchor").length;
    const warnings = [];
    const errors = [];

    if (visibleRows.length === 0) {
      errors.push("Alle onderdelen staan verborgen.");
    }
    if (anchorCount !== 1) {
      errors.push("Er moet exact één hoofdactiviteit zijn.");
    }
    visibleRows.forEach((row) => {
      if (!row.productId) {
        errors.push(`"${row.title}" mist een Woo product.`);
      }
      if (row.start && !row.duration) {
        warnings.push(`"${row.title}" heeft een starttijd maar geen duur.`);
      }
    });

    const scheduledRows = buildScheduledRows(visibleRows);

    previewList.innerHTML = scheduledRows
      .map((row) => {
        const meta = [roleLabels[row.role] || roleLabels.post, row.calculatedStart || row.start, row.duration ? `${row.duration} min` : "", row.calculatedEnd || (row.start && row.duration ? computeEndTime(row.start, row.duration) : "")].filter(Boolean).join(" · ");
        return `<li class="sbdp-arrangement-preview__item"><strong>${escapeHtml(row.title)}</strong><span>${escapeHtml(meta)}</span></li>`;
      })
      .join("");

    const totalDuration = visibleRows.reduce((sum, row) => sum + (Number.isFinite(row.duration) ? row.duration : 0), 0);
    const firstStart = scheduledRows.find((row) => row.calculatedStart)?.calculatedStart || visibleRows.find((row) => row.start)?.start || "";
    previewWindow.textContent = firstStart ? `Start ${firstStart} · ${totalDuration} min totaal` : (totalDuration ? `${totalDuration} min totaal` : "Nog geen plannerprojectie beschikbaar.");
    previewStatusPill.textContent = errors.length ? "invalid" : warnings.length ? "warning" : "valid";
    previewStatusPill.classList.toggle("is-invalid", errors.length > 0);
    previewStatusPill.classList.toggle("is-warning", errors.length === 0 && warnings.length > 0);
    previewStatusPill.classList.toggle("is-valid", errors.length === 0 && warnings.length === 0);

    previewHealth.innerHTML = [
      ...errors.map((message) => `<div class="sbdp-arrangement-preview__notice is-error">${escapeHtml(message)}</div>`),
      ...warnings.map((message) => `<div class="sbdp-arrangement-preview__notice is-warning">${escapeHtml(message)}</div>`),
      ...(errors.length === 0 && warnings.length === 0 ? ['<div class="sbdp-arrangement-preview__notice is-valid">Programma is compact en logisch opgebouwd.</div>'] : []),
    ].join("");

    refreshRoleBadges();
    refreshEndTimePills(scheduledRows);
    updateSuggestions(visibleRows);
  };

  const refreshEndTimePills = (scheduledRows = []) => {
    getRows().forEach((row, index) => {
      const start = scheduledRows[index]?.calculatedStart || row.querySelector("[data-segment-start]")?.value.trim() || "";
      const duration = parseInt(row.querySelector("[data-segment-duration]")?.value || "0", 10) || 0;
      const pill = row.querySelector("[data-segment-endtime]");
      if (!pill) return;
      pill.textContent = start && duration
        ? `${strings.endTimePrefix || "Eindtijd"} ${computeEndTime(start, duration)}`
        : "Eindtijd volgt automatisch zodra starttijd en duur bekend zijn.";
    });
  };

  const buildScheduledRows = (rows) => {
    const cloned = rows.map((row) => ({ ...row, calculatedStart: row.start || "", calculatedEnd: "" }));
    const anchorIndex = cloned.findIndex((row) => row.role === "anchor");

    if (anchorIndex >= 0) {
      for (let index = anchorIndex; index < cloned.length; index += 1) {
        const current = cloned[index];
        if (index === anchorIndex) {
          current.calculatedStart = current.start || current.calculatedStart;
        } else if (!current.calculatedStart && current.timingMode === "after_previous") {
          const previous = cloned[index - 1];
          if (previous?.calculatedStart && previous.duration) {
            current.calculatedStart = addMinutes(previous.calculatedStart, previous.duration + current.offset);
          }
        }
        current.calculatedEnd = current.calculatedStart && current.duration ? computeEndTime(current.calculatedStart, current.duration) : "";
      }

      for (let index = anchorIndex - 1; index >= 0; index -= 1) {
        const current = cloned[index];
        const next = cloned[index + 1];
        if (!current.calculatedStart && current.timingMode === "before_next" && next?.calculatedStart && current.duration) {
          current.calculatedStart = addMinutes(next.calculatedStart, -1 * (current.duration + current.offset));
        }
        current.calculatedEnd = current.calculatedStart && current.duration ? computeEndTime(current.calculatedStart, current.duration) : "";
      }
    }

    return cloned;
  };

  const updateSuggestions = (() => {
    let currentAnchorProductId = 0;
    let requestId = 0;

    return (visibleRows) => {
      const panel = root.querySelector("[data-suggestions-list]");
      if (!panel) return;

      const anchor = visibleRows.find((row) => row.role === "anchor" && row.productId);
      if (!anchor) {
        panel.innerHTML = `<p class="sbdp-arrangement-product-picker__empty">${escapeHtml(strings.suggestionsEmpty || "Kies eerst een hoofdactiviteit voor gerichte suggesties.")}</p>`;
        currentAnchorProductId = 0;
        return;
      }

      if (currentAnchorProductId === anchor.productId) {
        return;
      }

      currentAnchorProductId = anchor.productId;
      requestId += 1;
      const localRequestId = requestId;
      panel.innerHTML = `<p class="sbdp-arrangement-product-picker__empty">${escapeHtml(strings.searching || "Zoeken...")}</p>`;

      suggestProducts(anchor.productId).then((results) => {
        if (localRequestId !== requestId) return;
        if (!results.length) {
          panel.innerHTML = `<p class="sbdp-arrangement-product-picker__empty">${escapeHtml(strings.empty || "Geen boekbare producten gevonden.")}</p>`;
          return;
        }

        panel.innerHTML = results.map((item) => `
          <button type="button" class="sbdp-arrangement-suggestion" data-suggestion='${escapeAttribute(JSON.stringify(item))}'>
            <strong>${escapeHtml(item.title || "")}</strong>
            <span>${escapeHtml([item.price_label, item.duration_label].filter(Boolean).join(" · "))}</span>
          </button>
        `).join("");
      }).catch(() => {
        if (localRequestId !== requestId) return;
        panel.innerHTML = `<p class="sbdp-arrangement-product-picker__empty">${escapeHtml(strings.empty || "Geen boekbare producten gevonden.")}</p>`;
      });
    };
  })();

  const initializeSortable = () => {
    if (!segmentsBody || !window.jQuery || !window.jQuery.fn?.sortable) return;
    window.jQuery(segmentsBody).sortable({
      axis: "y",
      handle: ".sbdp-arrangement-segment-card__handle",
      items: "> [data-segment-row]",
      tolerance: "pointer",
      update() {
        reindexRows();
        updatePreview();
      },
    });
  };

  const initializePickers = (scope = root) => {
    scope.querySelectorAll("[data-product-picker]").forEach((picker) => initializeProductPicker(picker));
  };

  tabs.forEach((tab) => {
    tab.addEventListener("click", () => activateTab(tab.getAttribute("data-tab")));
  });

  root.addEventListener("input", (event) => {
    if (event.target.closest("[data-segment-row]")) {
      updatePreview();
    }
  });

  root.addEventListener("change", (event) => {
    if (event.target.closest("[data-segment-row]")) {
      updatePreview();
    }
  });

  root.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (target.closest(".sbdp-arrangement-remove-row")) {
      event.preventDefault();
      const row = target.closest("[data-segment-row]");
      if (row) {
        row.remove();
        reindexRows();
        updatePreview();
      }
    }

    if (target.closest(".sbdp-arrangement-duplicate-row")) {
      event.preventDefault();
      const row = target.closest("[data-segment-row]");
      if (row) {
        cloneCurrentRow(row);
      }
    }

    const suggestion = target.closest("[data-suggestion]");
    if (suggestion && segmentsBody && template) {
      event.preventDefault();
      const raw = suggestion.getAttribute("data-suggestion");
      if (!raw) return;
      const nextIndex = segmentsBody.querySelectorAll("[data-segment-row]").length;
      const html = template.innerHTML.replaceAll("__INDEX__", String(nextIndex));
      segmentsBody.insertAdjacentHTML("beforeend", html);
      const row = segmentsBody.querySelector(`[data-segment-index="${nextIndex}"]`) || segmentsBody.lastElementChild;
      if (!row) return;
      applyPresetToRow(row, "post");
      initializePickers(row);
      const picker = row.querySelector("[data-product-picker]");
      if (picker) {
        try {
          applySelection(picker, JSON.parse(raw));
        } catch (error) {}
      }
      reindexRows();
      updatePreview();
    }
  });

  addButtons.forEach((button) => {
    button.addEventListener("click", () => {
      if (!template || !(segmentsBody instanceof HTMLElement)) return;
      const nextIndex = segmentsBody.querySelectorAll("[data-segment-row]").length;
      const html = template.innerHTML.replaceAll("__INDEX__", String(nextIndex));
      segmentsBody.insertAdjacentHTML("beforeend", html);
      const row = segmentsBody.querySelector(`[data-segment-index="${nextIndex}"]`) || segmentsBody.lastElementChild;
      if (row) {
        applyPresetToRow(row, button.getAttribute("data-segment-preset") || "post");
        initializePickers(row);
      }
      reindexRows();
      updatePreview();
    });
  });

  activateTab("basis");
  reindexRows();
  initializePickers();
  initializeSortable();
  updatePreview();

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value == null ? "" : String(value);
    return div.innerHTML;
  }

  function escapeAttribute(value) {
    return escapeHtml(value).replace(/"/g, "&quot;");
  }

  function computeEndTime(start, duration) {
    const match = /^(\d{2}):(\d{2})$/.exec(start || "");
    if (!match || !duration) return "";
    const hours = parseInt(match[1], 10);
    const minutes = parseInt(match[2], 10);
    const total = (hours * 60) + minutes + duration;
    const endHours = Math.floor((total % 1440) / 60);
    const endMinutes = total % 60;
    return `${String(endHours).padStart(2, "0")}:${String(endMinutes).padStart(2, "0")}`;
  }

  function addMinutes(start, delta) {
    const match = /^(\d{2}):(\d{2})$/.exec(start || "");
    if (!match) return "";
    const total = (parseInt(match[1], 10) * 60) + parseInt(match[2], 10) + delta;
    const normalized = ((total % 1440) + 1440) % 1440;
    const hours = Math.floor(normalized / 60);
    const minutes = normalized % 60;
    return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}`;
  }
})();

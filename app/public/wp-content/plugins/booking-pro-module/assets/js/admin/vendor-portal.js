(function (window, document) {
  if (typeof window === "undefined" || typeof document === "undefined") {
    return;
  }

  const config = window.SBDP_VENDOR_PORTAL_ADMIN || null;
  const root = document.getElementById("sbdp-vendor-portal-admin");

  if (!config || !root) {
    return;
  }

  const state = {
    loading: false,
    error: "",
    notice: "",
    vendors: [],
    search: "",
    status: "all",
    page: 1,
    perPage: 20,
    hasMore: false,
    detail: null,
    detailLoading: false,
  };

  function setState(patch) {
    Object.assign(state, patch);
    render();
  }

  function buildQuery(params) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || value === "") {
        return;
      }
      query.append(key, value);
    });
    return query.toString();
  }

  async function fetchVendors() {
    setState({ loading: true, error: "", notice: "" });

    const query = buildQuery({
      search: state.search,
      status: state.status,
      page: state.page,
      per_page: state.perPage,
    });

    try {
      const response = await fetch(`${config.restUrl}/vendors?${query}`, {
        headers: {
          "X-WP-Nonce": config.nonce,
        },
      });

      if (!response.ok) {
        throw new Error(await extractError(response));
      }

      const data = await response.json();

      setState({
        vendors: Array.isArray(data.vendors) ? data.vendors : [],
        hasMore: !!data.has_more,
        loading: false,
      });
    } catch (error) {
      setState({
        loading: false,
        error: error instanceof Error ? error.message : String(error),
      });
    }
  }

  async function fetchVendorDetail(vendorId) {
    setState({ detailLoading: true, error: "", notice: "" });

    try {
      const response = await fetch(`${config.restUrl}/vendors/${vendorId}`, {
        headers: {
          "X-WP-Nonce": config.nonce,
        },
      });

      if (!response.ok) {
        throw new Error(await extractError(response));
      }

      const data = await response.json();
      setState({
        detail: data.vendor || null,
        detailLoading: false,
      });
    } catch (error) {
      setState({
        detailLoading: false,
        error: error instanceof Error ? error.message : String(error),
      });
    }
  }

  async function updateAccessKey(vendorId, action, providedKey) {
    const payload =
      action === "set"
        ? { action: "set", key: providedKey || "" }
        : { action: "generate" };

    setState({ detailLoading: true, error: "", notice: "" });

    try {
      const response = await fetch(
        `${config.restUrl}/vendors/${vendorId}/access-key`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": config.nonce,
          },
          body: JSON.stringify(payload),
        }
      );

      if (!response.ok) {
        throw new Error(await extractError(response));
      }

      const data = await response.json();
      const key = data.key || "";

      const vendors = state.vendors.map((vendor) =>
        vendor.id === vendorId ? data.vendor : vendor
      );

      const detail =
        state.detail && state.detail.id === vendorId
          ? Object.assign({}, state.detail, data.vendor)
          : state.detail;

      setState({
        detail,
        vendors,
        detailLoading: false,
        notice:
          key !== ""
            ? `Nieuwe toegangssleutel: ${key}`
            : "Toegangssleutel bijgewerkt.",
      });
    } catch (error) {
      setState({
        detailLoading: false,
        error: error instanceof Error ? error.message : String(error),
      });
    }
  }

  function render() {
    root.innerHTML = `
      <div class="sbdp-vendor-portal-admin">
        ${state.error ? `<div class="sbdp-vendor-portal-admin__notice sbdp-vendor-portal-admin__error">${state.error}</div>` : ""}
        ${state.notice ? `<div class="sbdp-vendor-portal-admin__notice">${state.notice}</div>` : ""}
        ${renderFilters()}
        ${renderTable()}
        ${renderPagination()}
        ${renderDetail()}
      </div>
    `;

    bindEvents();
  }

  function renderFilters() {
    return `
      <form id="sbdp-vendor-portal-admin-filters" class="sbdp-vendor-portal-admin__filters">
        <label>
          ${__("Zoeken", "sbdp")}
          <input type="search" name="search" value="${escapeHtml(state.search)}" placeholder="${__("Zoek op naam of e-mail", "sbdp")}"/>
        </label>
        <label>
          ${__("Status", "sbdp")}
          <select name="status">
            ${renderStatusOption("all", __("Alle statussen", "sbdp"))}
            ${renderStatusOption("active", __("Actief", "sbdp"))}
            ${renderStatusOption("pending", __("In afwachting", "sbdp"))}
            ${renderStatusOption("suspended", __("Gepauzeerd", "sbdp"))}
            ${renderStatusOption("archived", __("Gearchiveerd", "sbdp"))}
          </select>
        </label>
        <label>
          ${__("Resultaten per pagina", "sbdp")}
          <select name="per_page">
            ${renderPerPageOption(10)}
            ${renderPerPageOption(20)}
            ${renderPerPageOption(50)}
          </select>
        </label>
        <div>
          <button type="submit" class="button button-primary">${__("Vernieuwen", "sbdp")}</button>
          <button type="button" class="button" data-action="reset-filters">${__("Reset", "sbdp")}</button>
        </div>
      </form>
    `;
  }

  function renderStatusOption(value, label) {
    const selected = state.status === value ? "selected" : "";
    return `<option value="${value}" ${selected}>${label}</option>`;
  }

  function renderPerPageOption(value) {
    const selected = state.perPage === value ? "selected" : "";
    return `<option value="${value}" ${selected}>${value}</option>`;
  }

  function renderTable() {
    if (state.loading) {
      return `<p>${__("Gegevens laden...", "sbdp")}</p>`;
    }

    if (!state.vendors.length) {
      return `<p>${__("Geen vendors gevonden.", "sbdp")}</p>`;
    }

    const rows = state.vendors
      .map(
        (vendor) => `
        <tr>
          <td>#${vendor.id}</td>
          <td>${escapeHtml(vendor.name || "")}</td>
          <td><span class="sbdp-vendor-portal-admin__badge">${escapeHtml(
            vendor.status || ""
          )}</span></td>
          <td>${escapeHtml(vendor.contact_name || "")}</td>
          <td>${escapeHtml(vendor.contact_email || "")}</td>
          <td>${escapeHtml(vendor.access_key?.hint || __("Onbekend", "sbdp"))}</td>
          <td>${vendor.last_login ? formatDate(vendor.last_login) : __("Nooit", "sbdp")}</td>
          <td>
            <div class="sbdp-vendor-portal-admin__actions">
              <button type="button" class="button button-secondary" data-action="view" data-vendor="${vendor.id}">${__("Details", "sbdp")}</button>
              <button type="button" class="button" data-action="generate-key" data-vendor="${vendor.id}">${__("Nieuwe sleutel", "sbdp")}</button>
              <button type="button" class="button" data-action="set-key" data-vendor="${vendor.id}">${__("Eigen sleutel", "sbdp")}</button>
            </div>
          </td>
        </tr>
      `
      )
      .join("");

    return `
      <table class="sbdp-vendor-portal-admin__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>${__("Naam", "sbdp")}</th>
            <th>${__("Status", "sbdp")}</th>
            <th>${__("Contactpersoon", "sbdp")}</th>
            <th>${__("E-mail", "sbdp")}</th>
            <th>${__("Sleutel hint", "sbdp")}</th>
            <th>${__("Laatste login", "sbdp")}</th>
            <th>${__("Acties", "sbdp")}</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  function renderPagination() {
    const hasPrev = state.page > 1;
    const hasNext = state.hasMore;

    if (!hasPrev && !hasNext) {
      return "";
    }

    return `
      <div class="sbdp-vendor-portal-admin__pagination">
        <button type="button" class="button" data-action="prev-page" ${hasPrev ? "" : "disabled"}>
          ${__("Vorige", "sbdp")}
        </button>
        <span>${sprintf(__("Pagina %d", "sbdp"), state.page)}</span>
        <button type="button" class="button" data-action="next-page" ${hasNext ? "" : "disabled"}>
          ${__("Volgende", "sbdp")}
        </button>
      </div>
    `;
  }

  function renderDetail() {
    if (state.detailLoading) {
      return `<p>${__("Vendor laden...", "sbdp")}</p>`;
    }

    if (!state.detail) {
      return "";
    }

    const detail = state.detail;
    const dashboard = detail.dashboard || {};
    const portalUrl = config.portalUrl || "";
    const portalUrlHref = portalUrl ? encodeURI(portalUrl) : "";
    const portalUrlLabel = portalUrl ? escapeHtml(portalUrl) : "";

    return `
      <section class="sbdp-vendor-portal-admin__detail">
        <h2>${escapeHtml(detail.name || "")}</h2>
        <p><strong>${__("Status", "sbdp")}:</strong> ${escapeHtml(detail.status || "")}</p>
        <p><strong>${__("Contact", "sbdp")}:</strong> ${escapeHtml(detail.contact_name || "-")} (${escapeHtml(detail.contact_email || "-")})</p>
        <p><strong>${__("Laatste login", "sbdp")}:</strong> ${detail.last_login ? formatDate(detail.last_login) : __("Nooit", "sbdp")}</p>
        <p><strong>${__("Toegangssleutel", "sbdp")}:</strong> ${escapeHtml(detail.access_key?.hint || __("Onbekend", "sbdp"))}</p>
        ${portalUrl ? `<p><strong>${__("Portal URL", "sbdp")}:</strong> <a href="${portalUrlHref}" target="_blank" rel="noopener noreferrer">${portalUrlLabel}</a></p>` : ""}

        ${renderDashboardSummary(dashboard)}
        ${renderResources(detail.resources || [])}
      </section>
    `;
  }

  function renderDashboardSummary(dashboard) {
    if (!dashboard || typeof dashboard !== "object") {
      return "";
    }

    const financial = dashboard.financial || {};
    const upcoming = Array.isArray(dashboard.upcoming) ? dashboard.upcoming.length : 0;

    return `
      <div>
        <h3>${__("KPI's", "sbdp")}</h3>
        <ul>
          <li>${__("Totaal boekingen", "sbdp")}: ${financial.total_bookings || 0}</li>
          <li>${__("Betaalde omzet", "sbdp")}: ${formatCurrency(financial.paid_revenue, financial.currency)}</li>
          <li>${__("Openstaand", "sbdp")}: ${formatCurrency(financial.pending_revenue, financial.currency)}</li>
          <li>${__("Komende boekingen", "sbdp")}: ${upcoming}</li>
        </ul>
      </div>
    `;
  }

  function renderResources(resources) {
    if (!Array.isArray(resources) || resources.length === 0) {
      return "";
    }

    const items = resources
      .map(
        (resource) => `
        <li>
          <strong>${escapeHtml(resource.title || "")}</strong>
          <br/>
          <small>ID: ${resource.id}</small>
        </li>
      `
      )
      .join("");

    return `
      <div>
        <h3>${__("Resources", "sbdp")}</h3>
        <ul>${items}</ul>
      </div>
    `;
  }

  function bindEvents() {
    const form = document.getElementById("sbdp-vendor-portal-admin-filters");
    if (form) {
      form.addEventListener("submit", onFilterSubmit);
    }

    const resetBtn = root.querySelector('[data-action="reset-filters"]');
    if (resetBtn) {
      resetBtn.addEventListener("click", onResetFilters);
    }

    root.querySelectorAll('[data-action="view"]').forEach((button) => {
      button.addEventListener("click", () => {
        const vendorId = parseInt(button.getAttribute("data-vendor") || "0", 10);
        if (Number.isInteger(vendorId) && vendorId > 0) {
          fetchVendorDetail(vendorId);
        }
      });
    });

    root.querySelectorAll('[data-action="generate-key"]').forEach((button) => {
      button.addEventListener("click", () => {
        const vendorId = parseInt(button.getAttribute("data-vendor") || "0", 10);
        if (Number.isInteger(vendorId) && vendorId > 0 && confirm(__("Genereer een nieuwe toegangssleutel?", "sbdp"))) {
          updateAccessKey(vendorId, "generate");
        }
      });
    });

    root.querySelectorAll('[data-action="set-key"]').forEach((button) => {
      button.addEventListener("click", () => {
        const vendorId = parseInt(button.getAttribute("data-vendor") || "0", 10);
        if (!Number.isInteger(vendorId) || vendorId <= 0) {
          return;
        }

        const key = window.prompt(__("Voer een nieuwe toegangssleutel in:", "sbdp"), "");
        if (key && key.trim() !== "") {
          updateAccessKey(vendorId, "set", key.trim());
        }
      });
    });

    const prev = root.querySelector('[data-action="prev-page"]');
    if (prev) {
      prev.addEventListener("click", () => {
        if (state.page > 1) {
          setState({ page: state.page - 1 });
          fetchVendors();
        }
      });
    }

    const next = root.querySelector('[data-action="next-page"]');
    if (next) {
      next.addEventListener("click", () => {
        if (state.hasMore) {
          setState({ page: state.page + 1 });
          fetchVendors();
        }
      });
    }
  }

  function onFilterSubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);

    setState({
      search: (formData.get("search") || "").toString(),
      status: (formData.get("status") || "all").toString(),
      perPage: parseInt((formData.get("per_page") || "20").toString(), 10),
      page: 1,
    });

    fetchVendors();
  }

  function onResetFilters() {
    setState({
      search: "",
      status: "all",
      page: 1,
    });
    fetchVendors();
  }

  function escapeHtml(value) {
    if (value === undefined || value === null) {
      return "";
    }
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function formatDate(value) {
    try {
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return value;
      }
      return date.toLocaleString();
    } catch (error) {
      return value;
    }
  }

  function formatCurrency(amount, currency) {
    if (!Number.isFinite(amount)) {
      return "-";
    }
    if (typeof Intl !== "undefined" && Intl.NumberFormat) {
      try {
        return new Intl.NumberFormat(undefined, {
          style: "currency",
          currency: currency || "EUR",
          maximumFractionDigits: 2,
        }).format(amount);
      } catch (error) {
        // ignore
      }
    }
    return `${amount.toFixed(2)} ${currency || ""}`.trim();
  }

  async function extractError(response) {
    try {
      const payload = await response.json();
      if (payload && payload.message) {
        return payload.message;
      }
    } catch (error) {
      // ignore
    }
    return `${__("Onbekende fout", "sbdp")} (${response.status})`;
  }

  function __(text) {
    if (typeof window.wp !== "undefined" && window.wp.i18n && typeof window.wp.i18n.__ === "function") {
      return window.wp.i18n.__(text, "sbdp");
    }
    return text;
  }

  function sprintf(text, value) {
    if (typeof window.wp !== "undefined" && window.wp.i18n && typeof window.wp.i18n.sprintf === "function") {
      return window.wp.i18n.sprintf(text, value);
    }
    return text.replace("%d", value);
  }

  render();
  fetchVendors();
})(window, document);

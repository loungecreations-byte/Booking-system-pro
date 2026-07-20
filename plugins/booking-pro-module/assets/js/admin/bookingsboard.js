function trimSlash(value) {
  return String(value || "").replace(/\/+$/, "");
}

function buildUrl(baseUrl, path = "", params = {}) {
  const base = trimSlash(baseUrl);
  const url = new URL(`${base}${path}`, window.location.origin);

  Object.entries(params || {}).forEach(([key, value]) => {
    if (value === undefined || value === null || value === "") {
      return;
    }

    if (Array.isArray(value)) {
      value.forEach((item) => {
        if (item !== undefined && item !== null && item !== "") {
          url.searchParams.append(key, String(item));
        }
      });
      return;
    }

    url.searchParams.set(key, String(value));
  });

  return url.toString();
}

async function parseResponse(response) {
  const text = await response.text();
  const data = text ? JSON.parse(text) : {};

  if (!response.ok) {
    throw new Error(data?.message || response.statusText || "Request failed");
  }

  return data;
}

export function computeEndTime({ startDate, startTime, durationMinutes }) {
  if (!startDate || !startTime || !durationMinutes) {
    return { date: startDate || "", time: startTime || "" };
  }

  const start = new Date(`${startDate}T${startTime}`);
  if (Number.isNaN(start.getTime())) {
    return { date: startDate, time: startTime };
  }

  start.setMinutes(start.getMinutes() + Number(durationMinutes));
  const year = start.getFullYear();
  const month = String(start.getMonth() + 1).padStart(2, "0");
  const day = String(start.getDate()).padStart(2, "0");
  const hours = String(start.getHours()).padStart(2, "0");
  const minutes = String(start.getMinutes()).padStart(2, "0");

  return {
    date: `${year}-${month}-${day}`,
    time: `${hours}:${minutes}`,
  };
}

export function computePrice(product, persons) {
  const participants = Math.max(1, Number.parseInt(persons, 10) || 1);
  const base = Number.parseFloat(product?.base_price ?? product?.price ?? 0) || 0;
  const perPerson = Number.parseFloat(product?.per_person_price ?? product?.price_per_person ?? 0) || 0;
  const total = perPerson > 0 ? base + perPerson * participants : base;

  return { total, currency: product?.currency || "EUR" };
}

export function normaliseCustomer(customer) {
  const billing = customer?.billing && typeof customer.billing === "object" ? customer.billing : {};
  const shipping = customer?.shipping && typeof customer.shipping === "object" ? customer.shipping : {};

  return {
    id: customer?.id || customer?.customer_id || "",
    name: customer?.name || customer?.display_name || customer?.customer_name || "",
    email: customer?.email || customer?.customer_email || "",
    phone: customer?.phone || customer?.billing_phone || "",
    company: customer?.company || customer?.billing_company || "",
    billing,
    shipping,
  };
}

export class BookingsBoardApi {
  constructor({ baseUrl = "", boardBase = "", nonce = "" } = {}) {
    this.baseUrl = trimSlash(baseUrl);
    this.boardBase = trimSlash(boardBase);
    this.nonce = nonce;
  }

  request(url, options = {}) {
    const headers = {
      Accept: "application/json",
      ...(options.body ? { "Content-Type": "application/json" } : {}),
      ...(this.nonce ? { "X-WP-Nonce": this.nonce } : {}),
      ...(options.headers || {}),
    };

    return fetch(url, {
      credentials: "same-origin",
      ...options,
      headers,
      body: options.body && typeof options.body !== "string" ? JSON.stringify(options.body) : options.body,
    }).then(parseResponse);
  }

  getStatusOptions(labels = {}) {
    const fallback = ["created", "requested", "captured", "pending", "paid", "completed", "cancelled"];
    const keys = Object.keys(labels).length > 0 ? Object.keys(labels) : fallback;

    return keys.map((value) => ({
      value,
      label: labels[value] || value,
    }));
  }

  listBookings(filters = {}) {
    return this.request(buildUrl(this.boardBase, "/bookings", filters));
  }

  getPresets() {
    return this.request(buildUrl(this.boardBase, "/presets"));
  }

  savePreset(payload) {
    return this.request(buildUrl(this.boardBase, "/presets"), {
      method: "POST",
      body: payload,
    });
  }

  deletePreset(presetId, scope = "personal") {
    return this.request(buildUrl(this.boardBase, `/presets/${encodeURIComponent(presetId)}`, { scope }), {
      method: "DELETE",
    });
  }

  searchCustomers(term) {
    return this.request(buildUrl(this.baseUrl, "/customers", { search: term }));
  }

  searchProducts(term) {
    return this.request(buildUrl(this.baseUrl, "/products", { search: term }));
  }

  addBooking(payload) {
    return this.request(buildUrl(this.baseUrl, "/bookings"), {
      method: "POST",
      body: payload,
    });
  }

  updateBookingDetails(payload) {
    return this.request(buildUrl(this.baseUrl, `/bookings/${encodeURIComponent(payload.booking_id)}`), {
      method: "POST",
      body: payload,
    });
  }

  invoiceBooking(payload) {
    return this.request(buildUrl(this.baseUrl, `/bookings/${encodeURIComponent(payload.booking_id)}/invoice`), {
      method: "POST",
      body: payload,
    });
  }

  downloadInvoice(payload) {
    return this.request(buildUrl(this.baseUrl, `/bookings/${encodeURIComponent(payload.booking_id)}/invoice/download`), {
      method: "POST",
      body: payload,
    });
  }

  exportBookings(payload = {}) {
    return this.request(buildUrl(this.boardBase, "/export"), {
      method: "POST",
      body: payload,
    });
  }

  rescheduleBooking(payload) {
    return this.request(buildUrl(this.baseUrl, `/bookings/${encodeURIComponent(payload.booking_id)}/reschedule`), {
      method: "POST",
      body: payload,
    });
  }

  checkConflict(payload) {
    return this.request(buildUrl(this.baseUrl, "/availability/conflicts"), {
      method: "POST",
      body: payload,
    });
  }
}

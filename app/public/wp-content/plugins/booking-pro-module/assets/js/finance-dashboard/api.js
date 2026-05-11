const DEFAULT_HEADERS = {
  "Content-Type": "application/json",
};

export function createFinanceApi(config) {
  const base = (config?.restBase ?? "").replace(/\/$/, "");
  const nonce = config?.nonce ?? "";

  async function request(path, query = {}) {
    const url = new URL(`${base}${path}`, window.location.origin);

    Object.entries(query).forEach(([key, value]) => {
      if (value === undefined || value === null || value === "") {
        return;
      }

      url.searchParams.set(key, String(value));
    });

    const response = await fetch(url.toString(), {
      method: "GET",
      headers: {
        ...DEFAULT_HEADERS,
        ...(nonce ? { "X-WP-Nonce": nonce } : {}),
      },
    });

    if (!response.ok) {
      const message = await extractError(response);
      throw new Error(message ?? `Request to ${url.pathname} failed with status ${response.status}.`);
    }

    return response.json();
  }

  return {
    getSummary(options = {}) {
      return request("/summary", options);
    },
    getRevenue(options = {}) {
      return request("/revenue", options);
    },
    getRefunds(options = {}) {
      return request("/refunds", options);
    },
    getMargins(options = {}) {
      return request("/margins", options);
    },
    getForecast(options = {}) {
      return request("/forecast", options);
    },
    getPayments(options = {}) {
      return request("/payments", options);
    },
    getLosses(options = {}) {
      return request("/losses", options);
    },
    getFilters() {
      return request("/filters");
    },
  };
}

async function extractError(response) {
  try {
    const payload = await response.json();
    if (payload && typeof payload === "object") {
      return payload.message ?? payload.error ?? null;
    }

    return null;
  } catch (error) {
    return null;
  }
}

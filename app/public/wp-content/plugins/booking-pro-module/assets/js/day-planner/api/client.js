const DEFAULT_HEADERS = {
  "Content-Type": "application/json",
};

/**
 * Create a REST client for the day planner endpoints.
 *
 * @param {Object} options
 * @param {string} options.restBase
 * @param {string} options.nonce
 */
export function createPlannerApi({ restBase, nonce }) {
  const baseUrl = sanitiseBase(restBase);

  /**
   * @param {string} path
   * @param {Object} [options]
   * @param {"GET"|"POST"|"PATCH"} [options.method]
   * @param {Object} [options.body]
   * @param {Object} [options.params]
   */
  async function request(path, { method = "GET", body, params } = {}) {
    const url = buildUrl(baseUrl, path, params);

    const headers = {
      ...DEFAULT_HEADERS,
    };

    if (typeof nonce === "string" && nonce.length > 0) {
      headers["X-WP-Nonce"] = nonce;
      headers["x-sbdp-nonce"] = nonce;
    }

    const response = await fetch(url, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
      credentials: "same-origin",
    });

    const payload = await parseJson(response);

    if (!response.ok) {
      const message = payload && payload.message ? payload.message : response.statusText;
      throw new Error(message || "Request failed");
    }

    return payload;
  }

  return {
    listActivities(filters = {}) {
      return request("/activities", { params: filters });
    },

    createPlan(payload) {
      return request("/plan", { method: "POST", body: payload });
    },

    getPlan(planId, options = {}) {
      const params = buildTokenParams(options.token);
      return request(`/plan/${planId}`, { params });
    },

    updatePlan(planId, payload) {
      return request(`/plan/${planId}`, { method: "PATCH", body: payload });
    },

    sharePlan(planId, options = {}) {
      const params = buildTokenParams(options.token);
      return request(`/plan/${planId}/share`, { method: "POST", params });
    },

    requestQuote(planId, options = {}) {
      const params = buildTokenParams(options.token);
      return request(`/plan/${planId}/quote`, { method: "POST", params });
    },

    queueBooking(planId, options = {}) {
      const params = buildTokenParams(options.token);
      return request(`/plan/${planId}/book`, { method: "POST", params });
    },

    exportPlan(planId, type, options = {}) {
      const exportType = type === "ics" ? "ics" : "pdf";
      const params = buildTokenParams(options.token);
      return request(`/plan/${planId}/export/${exportType}`, { method: "POST", params });
    },

    suggestActivities(preferences) {
      return request("/plan/ai/suggest", { method: "POST", body: preferences });
    },

    detectConflicts(plan) {
      return request("/plan/conflicts", { method: "POST", body: plan });
    },
  };
}

function buildTokenParams(token) {
  if (typeof token !== "string") {
    return undefined;
  }

  const trimmed = token.trim();
  if (trimmed === "") {
    return undefined;
  }

  return { edit_token: trimmed };
}

function sanitiseBase(restBase) {
  if (typeof restBase !== "string" || restBase.length === 0) {
    return "";
  }

  return restBase.replace(/\/+$/, "");
}

function buildUrl(base, path, params = {}) {
  const query = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === "") {
      return;
    }

    if (Array.isArray(value)) {
      value.forEach((item) => query.append(`${key}[]`, String(item)));
      return;
    }

    query.append(key, String(value));
  });

  const suffix = path.startsWith("/") ? path : `/${path}`;
  const queryString = query.toString();

  return queryString ? `${base}${suffix}?${queryString}` : `${base}${suffix}`;
}

async function parseJson(response) {
  const contentType = response.headers.get("Content-Type");
  if (!contentType || contentType.indexOf("application/json") === -1) {
    return null;
  }

  try {
    return await response.json();
  } catch (error) {
    console.warn("Failed to parse JSON response", error);
    return null;
  }
}

export default createPlannerApi;

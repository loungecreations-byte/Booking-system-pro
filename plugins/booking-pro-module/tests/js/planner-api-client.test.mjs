import assert from "node:assert/strict";
import test from "node:test";

import { buildNonceHeaders, createPlannerApi } from "../../assets/js/day-planner/api/client.js";

test("public planner nonce is not sent as the WordPress REST cookie nonce", () => {
  assert.deepEqual(buildNonceHeaders("public-nonce", "sbdp_public_rest"), {
    "x-sbdp-nonce": "public-nonce",
  });
});

test("logged-in planner nonce keeps the WordPress REST header", () => {
  assert.deepEqual(buildNonceHeaders("wp-rest-nonce", "wp_rest"), {
    "X-WP-Nonce": "wp-rest-nonce",
    "x-sbdp-nonce": "wp-rest-nonce",
  });
});

test("empty planner nonce sends no security headers", () => {
  assert.deepEqual(buildNonceHeaders("", "sbdp_public_rest"), {});
});

test("queue booking keeps same-origin credentials so Woo cart cookies can persist", async () => {
  const previousFetch = globalThis.fetch;
  const calls = [];

  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return {
      ok: true,
      headers: {
        get() {
          return "application/json";
        },
      },
      async json() {
        return { ok: true };
      },
    };
  };

  try {
    const api = createPlannerApi({
      restBase: "https://example.test/wp-json/planner/v1",
      nonce: "public-nonce",
      nonceAction: "sbdp_public_rest",
    });

    await api.queueBooking(42, { token: "edit-token" });

    assert.equal(calls.length, 1);
    assert.equal(calls[0].options.credentials, "same-origin");
    assert.equal(calls[0].options.headers["x-sbdp-nonce"], "public-nonce");
    assert.equal(calls[0].options.headers["X-WP-Nonce"], undefined);
    assert.match(calls[0].url, /\/plan\/42\/book\?edit_token=edit-token$/);
  } finally {
    globalThis.fetch = previousFetch;
  }
});

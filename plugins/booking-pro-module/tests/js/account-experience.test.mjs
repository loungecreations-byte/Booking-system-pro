import test from "node:test";
import assert from "node:assert/strict";

const assetUrl = new URL("../../modules/experience/assets/account.js", import.meta.url);

async function loadFrontend({ readyState = "complete", root = makeRoot(), fetchImpl, config = {} } = {}) {
  const listeners = new Map();
  globalThis.document = {
    readyState,
    querySelector: () => root,
    addEventListener: (name, callback) => listeners.set(name, callback),
    createElement: () => ({
      set textContent(value) {
        this.innerHTML = escapeHtml(value);
      },
      innerHTML: "",
    }),
  };
  globalThis.fetch = fetchImpl || (async () => response({}));
  globalThis.bspExperienceAccount = {
    endpoint: "https://example.test/wp-json/bsp/v1/me/experience",
    nonce: "rest-nonce",
    timeoutMs: 25,
    ...config,
  };
  delete globalThis.BSPExperienceAccountFrontend;
  await import(`${assetUrl.href}?case=${Math.random()}`);
  return { api: globalThis.BSPExperienceAccountFrontend, root, listeners };
}

function makeRoot() {
  return { innerHTML: '<p class="bsp-experience__loading">Je ervaringen worden geladen…</p>' };
}

function response(data, { ok = true, jsonError = null } = {}) {
  return {
    ok,
    json: async () => {
      if (jsonError) throw jsonError;
      return data;
    },
  };
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

test("successful response reaches a rendered end state", async () => {
  const { root, api } = await loadFrontend({
    fetchImpl: async () => response({ progress: { xp: 12, level: { number: 2 } }, tours: [] }),
  });
  await api.pending(root);
  assert.match(root.innerHTML, /<strong>12<\/strong><span>XP<\/span>/);
  assert.doesNotMatch(root.innerHTML, /worden geladen/);
});

test("duplicate tour records render once per tour", async () => {
  const duplicate = { id: 42, title: "Jeroen Bosch Tour", url: "/tour/42", completion_percent: 95 };
  const { root, api } = await loadFrontend({
    fetchImpl: async () => response({ tours: [duplicate, { ...duplicate }] }),
  });
  await api.pending(root);
  assert.equal((root.innerHTML.match(/Jeroen Bosch Tour/g) || []).length, 1);
});

test("HTTP error reaches the safe error end state", async () => {
  const { root, api } = await loadFrontend({ fetchImpl: async () => response({}, { ok: false }) });
  await api.pending(root);
  assert.match(root.innerHTML, /konden niet veilig worden geladen/);
});

test("invalid JSON reaches the safe error end state", async () => {
  const { root, api } = await loadFrontend({
    fetchImpl: async () => response({}, { jsonError: new SyntaxError("invalid JSON") }),
  });
  await api.pending(root);
  assert.match(root.innerHTML, /konden niet veilig worden geladen/);
});

test("fetch timeout aborts and reaches the safe error end state", async () => {
  const fetchImpl = (_url, options) => new Promise((_resolve, reject) => {
    options.signal.addEventListener("abort", () => reject(new DOMException("Aborted", "AbortError")));
  });
  const { root, api } = await loadFrontend({ fetchImpl, config: { timeoutMs: 5 } });
  await api.pending(root);
  assert.match(root.innerHTML, /konden niet veilig worden geladen/);
});

test("missing root container performs no fetch", async () => {
  let calls = 0;
  const { api } = await loadFrontend({ root: null, fetchImpl: async () => { calls += 1; return response({}); } });
  assert.equal(api.init(), null);
  assert.equal(calls, 0);
});

test("duplicate initialization performs only one fetch", async () => {
  let calls = 0;
  const root = makeRoot();
  const { api } = await loadFrontend({ root, fetchImpl: async () => { calls += 1; return response({}); } });
  api.init();
  await api.pending(root);
  assert.equal(calls, 1);
});

test("late DOM ready initializes after DOMContentLoaded", async () => {
  let calls = 0;
  const { root, api, listeners } = await loadFrontend({
    readyState: "loading",
    fetchImpl: async () => { calls += 1; return response({}); },
  });
  assert.equal(calls, 0);
  listeners.get("DOMContentLoaded")();
  await api.pending(root);
  assert.equal(calls, 1);
});

(function (global) {
  "use strict";

  var requests = new WeakMap();

  function escapeHtml(value) {
    var node = document.createElement("span");
    node.textContent = String(value == null ? "" : value);
    return node.innerHTML;
  }

  function renderSuccess(root, data) {
    var payload = data && typeof data === "object" ? data : {};
    var resume = payload.resume;
    var tours = Array.isArray(payload.tours) ? payload.tours : [];
    var badges = Array.isArray(payload.badges) ? payload.badges.filter(function (item) { return item.awarded_at && !item.revoked_at; }) : [];
    var favorites = Array.isArray(payload.favorites) ? payload.favorites : [];
    var collectibles = Array.isArray(payload.collectibles) ? payload.collectibles.filter(function (item) { return item.unlocked; }) : [];
    var certificates = Array.isArray(payload.certificates) ? payload.certificates.filter(function (item) { return !item.revoked_at; }) : [];
    var rewards = Array.isArray(payload.rewards) ? payload.rewards.filter(function (item) { return item.status === "earned" && !item.revoked_at; }) : [];
    var timeline = Array.isArray(payload.timeline) ? payload.timeline.slice(0, 10) : [];
    var progress = payload.progress && typeof payload.progress === "object" ? payload.progress : {};
    var level = progress.level && typeof progress.level === "object" ? progress.level : {};

    root.innerHTML = '<h2>Mijn DagjeDenBosch</h2>'
      + (resume ? '<article class="bsp-experience__resume"><p>Ga verder waar je gebleven was</p><h3>' + escapeHtml(resume.title) + '</h3><p>' + escapeHtml(resume.completion_percent) + '% voltooid</p><a class="button alt" href="' + escapeHtml(resume.url) + '">Hervat je tour</a></article>' : '<p>Je hebt momenteel geen actieve tour om te hervatten.</p>')
      + '<div class="bsp-experience__stats"><p><strong>' + escapeHtml(progress.xp || 0) + '</strong> XP</p><p><strong>' + escapeHtml(level.number || 1) + '</strong> Level</p><p><strong>' + badges.length + '</strong> Badges</p><p><strong>' + collectibles.length + '</strong> Collectibles</p></div>'
      + '<h3>Mijn tours</h3>' + (tours.length ? '<ul>' + tours.map(function (tour) { return '<li><a href="' + escapeHtml(tour.url) + '">' + escapeHtml(tour.title) + '</a> — ' + escapeHtml(tour.completion_percent) + '%</li>'; }).join("") + '</ul>' : '<p>Nog geen tours beschikbaar.</p>')
      + '<h3>Favorieten</h3>' + (favorites.length ? '<ul>' + favorites.map(function (favorite) { return '<li><a href="' + escapeHtml(favorite.url) + '">' + escapeHtml(favorite.title) + '</a></li>'; }).join("") + '</ul>' : '<p>Nog geen accountfavorieten.</p>')
      + '<h3>Certificaten en rewards</h3><p>' + certificates.length + ' certificaat/certificaten · ' + rewards.length + ' verdiende reward(s)</p>'
      + '<h3>Recente activiteit</h3>' + (timeline.length ? '<ul>' + timeline.map(function (event) { return '<li>' + escapeHtml(event.event_type) + ' · ' + escapeHtml(event.occurred_at) + '</li>'; }).join("") + '</ul>' : '<p>Nog geen activiteit.</p>');
  }

  function renderError(root) {
    root.innerHTML = '<h2>Mijn DagjeDenBosch</h2><p>Je gegevens konden niet veilig worden geladen. Probeer het later opnieuw.</p>';
  }

  function load(root, config) {
    var controller = new AbortController();
    var timeoutMs = Number(config.timeoutMs) > 0 ? Number(config.timeoutMs) : 10000;
    var timeoutId;
    var timeout = new Promise(function (_resolve, reject) {
      timeoutId = global.setTimeout(function () {
        controller.abort();
        reject(new Error("request_timeout"));
      }, timeoutMs);
    });
    var request = global.fetch(config.endpoint, {
      credentials: "same-origin",
      headers: { "X-WP-Nonce": config.nonce },
      signal: controller.signal,
    }).then(function (response) {
      if (!response.ok) {
        throw new Error("request_failed");
      }
      return response.json();
    });

    return Promise.race([request, timeout])
      .then(function (data) { renderSuccess(root, data); })
      .catch(function () { renderError(root); })
      .finally(function () { global.clearTimeout(timeoutId); });
  }

  function init() {
    var root = document.querySelector(".bsp-experience");
    var config = global.bspExperienceAccount || {};
    if (!root || !config.endpoint || !config.nonce) {
      return null;
    }
    if (requests.has(root)) {
      return requests.get(root);
    }

    var request = load(root, config);
    requests.set(root, request);
    return request;
  }

  var api = {
    init: init,
    pending: function (root) { return requests.get(root) || Promise.resolve(null); },
  };
  global.BSPExperienceAccountFrontend = api;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})(globalThis);

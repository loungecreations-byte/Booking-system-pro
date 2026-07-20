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
    var tours = uniqueBy(Array.isArray(payload.tours) ? payload.tours : [], function (tour) { return tour.id || tour.url || tour.title; });
    var badges = Array.isArray(payload.badges) ? payload.badges.filter(function (item) { return item.awarded_at && !item.revoked_at; }) : [];
    var favorites = Array.isArray(payload.favorites) ? payload.favorites : [];
    var collectibles = Array.isArray(payload.collectibles) ? payload.collectibles.filter(function (item) { return item.unlocked; }) : [];
    var certificates = Array.isArray(payload.certificates) ? payload.certificates.filter(function (item) { return !item.revoked_at; }) : [];
    var rewards = Array.isArray(payload.rewards) ? payload.rewards.filter(function (item) { return item.status === "earned" && !item.revoked_at; }) : [];
    var timeline = Array.isArray(payload.timeline) ? payload.timeline.slice(0, 10) : [];
    var progress = payload.progress && typeof payload.progress === "object" ? payload.progress : {};
    var level = progress.level && typeof progress.level === "object" ? progress.level : {};

    root.innerHTML = '<header class="bsp-experience__header"><p class="bsp-experience__eyebrow">Jouw beleving</p><h2>Mijn DagjeDenBosch</h2></header>'
      + (resume ? '<article class="bsp-experience__resume"><div><p class="bsp-experience__eyebrow">Ga verder waar je gebleven was</p><h3>' + escapeHtml(resume.title) + '</h3><p>' + escapeHtml(resume.completion_percent) + '% voltooid</p></div><a class="bsp-experience__button" href="' + escapeHtml(resume.url) + '">Hervat je tour</a></article>' : '<div class="bsp-experience__empty">Je hebt momenteel geen actieve tour om te hervatten.</div>')
      + '<div class="bsp-experience__stats"><article><strong>' + escapeHtml(progress.xp || 0) + '</strong><span>XP</span></article><article><strong>' + escapeHtml(level.number || 1) + '</strong><span>Level</span></article><article><strong>' + badges.length + '</strong><span>Badges</span></article><article><strong>' + collectibles.length + '</strong><span>Collectibles</span></article></div>'
      + '<div class="bsp-experience__grid"><section class="bsp-experience__panel bsp-experience__panel--tours"><h3>Mijn tours</h3>' + (tours.length ? '<div class="bsp-experience__tour-list">' + tours.map(function (tour) { return '<a class="bsp-experience__tour" href="' + escapeHtml(tour.url) + '"><span>' + escapeHtml(tour.title) + '</span><strong>' + escapeHtml(tour.completion_percent) + '%</strong></a>'; }).join("") + '</div>' : '<p class="bsp-experience__empty">Nog geen tours beschikbaar.</p>') + '</section>'
      + '<section class="bsp-experience__panel"><h3>Favorieten</h3>' + (favorites.length ? '<ul class="bsp-experience__list">' + favorites.map(function (favorite) { return '<li><a href="' + escapeHtml(favorite.url) + '">' + escapeHtml(favorite.title) + '</a></li>'; }).join("") + '</ul>' : '<p class="bsp-experience__empty">Nog geen accountfavorieten.</p>') + '</section>'
      + '<section class="bsp-experience__panel"><h3>Certificaten en rewards</h3><p><strong>' + certificates.length + '</strong> certificaten · <strong>' + rewards.length + '</strong> rewards</p></section>'
      + '<section class="bsp-experience__panel"><h3>Recente activiteit</h3>' + (timeline.length ? '<ul class="bsp-experience__timeline">' + timeline.map(function (event) { return '<li><span>' + eventLabel(event.event_type) + '</span><time>' + escapeHtml(event.occurred_at) + '</time></li>'; }).join("") + '</ul>' : '<p class="bsp-experience__empty">Nog geen activiteit.</p>') + '</section></div>';
  }

  function uniqueBy(items, keyFor) {
    var seen = new Set();
    return items.filter(function (item) {
      var key = String(keyFor(item) || "");
      if (!key || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function eventLabel(type) {
    var labels = { "badge.awarded": "Badge verdiend", "collectible.unlocked": "Collectible ontgrendeld", "quiz.passed": "Quiz voltooid", "tour.completed": "Tour voltooid" };
    return escapeHtml(labels[type] || String(type || "Activiteit").replaceAll(".", " "));
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

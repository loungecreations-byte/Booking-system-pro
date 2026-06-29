/**
 * Tour Experience Engine
 * Lightweight, instance-safe navigation for private tour pages.
 */
(function () {
  "use strict";

  const SELECTORS = {
    root: "[data-tour-navigation]",
    summaryPanel: "[data-tour-summary-panel]",
    stepList: "[data-tour-step-list]",
    storyPanel: "[data-tour-story-panel]",
    navigationPanel: "[data-tour-navigation-panel]",
    navigationCopy: "[data-tour-navigation-copy]",
    navPrev: "[data-tour-prev]",
    navNext: "[data-tour-next]",
    complete: "[data-tour-complete]",
    map: "[data-tour-map]",
    mapPanel: "[data-tour-map-panel]",
    mapStatus: "[data-tour-map-status]",
    mapMeta: "[data-tour-map-meta]",
    navStatus: "[data-tour-nav-status]",
    locate: "[data-tour-locate]",
    routeLink: "[data-tour-route-link]",
    routeStart: "[data-tour-start-route]",
    arrivalConfirm: "[data-tour-arrival-confirm]",
    nextChapterStart: "[data-tour-start-next-step]",
    stepJump: "[data-tour-step-jump]",
    openNavigation: "[data-tour-open-navigation]",
    openMap: "[data-tour-open-map]",
    closeNavigation: "[data-tour-close-navigation]",
    modeToggle: "[data-tour-mode]",
    routeEmbedFrame: "[data-tour-route-embed-frame]",
    routeSheet: "[data-tour-route-sheet]",
    routeSheetOpen: "[data-tour-route-sheet-open]",
    routeSheetClose: "[data-tour-route-sheet-close]",
    routeSheetBackdrop: "[data-tour-route-sheet-backdrop]",
    routeSheetFrame: "[data-tour-route-sheet-frame]",
    routeEmbedDiagnostic: "[data-tour-route-embed-diagnostic]",
    mobilePrev: "[data-tour-mobile-prev]",
    mobileNext: "[data-tour-mobile-next]",
    mobileRoute: "[data-tour-mobile-route]",
  };

  const MAP_CONFIG = {
    zoom: 15,
    refreshDistanceMeters: 24,
    refreshIntervalMs: 12000,
    nearThresholdMeters: 80,
    almostThresholdMeters: 35,
    arrivalThresholdMeters: 18,
  };

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function safeJsonParse(value, fallback) {
    try {
      return JSON.parse(value);
    } catch (error) {
      return fallback;
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function splitStepContent(html) {
    const raw = String(html || "").trim();
    if (!raw) {
      return { introHtml: "", bodyHtml: "" };
    }

    if (/<[a-z][\s\S]*>/i.test(raw)) {
      const wrapper = document.createElement("div");
      wrapper.innerHTML = raw;
      wrapper
        .querySelectorAll(
          "script, style, iframe, video, audio, svg, canvas, figure, .tour-media, .tour-story-flow, .tour-story-route, .tour-route-rail, .tour-arrival, .tour-navigation-card, .elementor-widget-video, .wp-block-embed"
        )
        .forEach((node) => node.remove());

      wrapper.querySelectorAll(".ddb-app, [data-app]").forEach((node) => {
        const text = String(node.textContent || "").replace(/\s+/g, " ").trim();
        if (!text) {
          node.remove();
          return;
        }

        const paragraph = document.createElement("p");
        paragraph.textContent = text;
        node.replaceWith(paragraph);
      });

      wrapper.querySelectorAll("h1, h2, h3, h4, h5, h6").forEach((node) => node.remove());

      const blocks = Array.from(wrapper.querySelectorAll("p, li, blockquote"))
        .map((node) => String(node.textContent || "").replace(/\s+/g, " ").trim())
        .filter(Boolean);

      const uniqueBlocks = blocks.filter((block, index) => index === 0 || block !== blocks[index - 1]);

      if (!uniqueBlocks.length) {
        const fallbackText = String(wrapper.textContent || "").replace(/\s+/g, " ").trim();
        if (!fallbackText) {
          return { introHtml: "", bodyHtml: "" };
        }

        return {
          introHtml: `<p>${escapeHtml(fallbackText)}</p>`,
          bodyHtml: "",
        };
      }

      const [intro, ...rest] = uniqueBlocks;
      return {
        introHtml: intro ? `<p>${escapeHtml(intro)}</p>` : "",
        bodyHtml: rest.map((part) => `<p>${escapeHtml(part)}</p>`).join(""),
      };
    }

    const parts = raw
      .split(/\n{2,}/)
      .map((part) => part.replace(/\s+/g, " ").trim())
      .filter(Boolean);

    const intro = parts.shift() || raw.replace(/\s+/g, " ").trim();
    const bodyHtml = parts.map((part) => `<p>${escapeHtml(part)}</p>`).join("");

    return {
      introHtml: intro ? `<p>${escapeHtml(intro)}</p>` : "",
      bodyHtml,
    };
  }

  function normalizeHeygenUrl(value) {
    if (!value) {
      return "";
    }

    try {
      const parsed = new URL(String(value), window.location.origin);
      if (parsed.hostname !== "app.heygen.com") {
        return "";
      }

      const segments = parsed.pathname.split("/").filter(Boolean);
      if (segments.length < 2) {
        return "";
      }

      const context = segments[0].toLowerCase();
      const id = segments[1];
      if (!["embeds", "share", "videos"].includes(context) || !/^[A-Za-z0-9_-]+$/.test(id)) {
        return "";
      }

      const query = parsed.search && parsed.search !== "?" ? parsed.search : "";
      return `https://app.heygen.com/embeds/${encodeURIComponent(id)}${query}`;
    } catch (error) {
      return "";
    }
  }

  function sanitizeMediaUrl(value) {
    if (!value) {
      return "";
    }

    try {
      const parsed = new URL(String(value), window.location.origin);
      if (!["http:", "https:"].includes(parsed.protocol)) {
        return "";
      }

      return parsed.toString();
    } catch (error) {
      return "";
    }
  }

  function toStepLocation(step) {
    return String(step.location_label || step.locationLabel || "");
  }

  function toCompactText(value, maxLength = 48) {
    const raw = String(value || "")
      .replace(/\s+/g, " ")
      .trim();
    if (!raw) {
      return "";
    }

    const firstSegment = raw.split(",")[0].split("•")[0].trim() || raw;
    if (firstSegment.length <= maxLength) {
      return firstSegment;
    }

    return `${firstSegment.slice(0, Math.max(0, maxLength - 1)).trimEnd()}…`;
  }

  function humanizeDisplayValue(value, fallback = "") {
    const raw = String(value || "")
      .replace(/[_-]+/g, " ")
      .replace(/\s+/g, " ")
      .trim();

    if (!raw) {
      return fallback;
    }

    if (/^[a-z0-9 ]+$/i.test(raw) && raw === raw.toLowerCase()) {
      return raw.charAt(0).toUpperCase() + raw.slice(1);
    }

    return raw;
  }

  function looksLikeInternalToken(value) {
    const raw = String(value || "").trim();
    if (!raw) {
      return false;
    }

    if (raw.length > 64) {
      return false;
    }

    if (/[.!?]/.test(raw) || /[A-Z]/.test(raw)) {
      return false;
    }

    return /^[a-z0-9_-]+$/.test(raw);
  }

  function normalizeMissionText(value, fallback = "") {
    const raw = String(value || "")
      .replace(/\s+/g, " ")
      .trim();

    if (!raw) {
      return fallback;
    }

    if (looksLikeInternalToken(raw)) {
      return fallback;
    }

    return humanizeDisplayValue(raw, fallback);
  }

  function toGamification(step) {
    return step && step.gamification && typeof step.gamification === "object" ? step.gamification : {};
  }

  function toNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function toLatLngPair(value) {
    if (!Array.isArray(value) || value.length < 2) {
      return null;
    }

    const lat = Number(value[0]);
    const lng = Number(value[1]);
    return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
  }

  function getActLabel(index, total) {
    if (total <= 1) {
      return "Act I";
    }

    const third = Math.ceil(total / 3);
    if (index < third) {
      return "Act I";
    }

    if (index < third * 2) {
      return "Act II";
    }

    return "Act III";
  }

  function formatDistance(meters) {
    const safeMeters = Number.isFinite(meters) ? Math.max(0, meters) : 0;
    return safeMeters >= 1000 ? `${(safeMeters / 1000).toFixed(1)} km` : `${Math.round(safeMeters)} m`;
  }

  function formatDuration(seconds) {
    const minutes = Math.max(0, Math.round((Number(seconds) || 0) / 60));
    if (minutes < 1) {
      return "< 1 min";
    }

    if (minutes >= 60) {
      const hours = Math.floor(minutes / 60);
      const rest = minutes % 60;
      return rest > 0 ? `${hours}u ${rest}m` : `${hours} uur`;
    }

    return `${minutes} min`;
  }

  function haversineMeters(a, b) {
    if (!a || !b) {
      return 0;
    }

    const earthRadius = 6371000;
    const dLat = ((b.lat - a.lat) * Math.PI) / 180;
    const dLng = ((b.lng - a.lng) * Math.PI) / 180;
    const sinLat = Math.sin(dLat / 2);
    const sinLng = Math.sin(dLng / 2);
    const aa =
      sinLat * sinLat +
      Math.cos((a.lat * Math.PI) / 180) * Math.cos((b.lat * Math.PI) / 180) * sinLng * sinLng;
    return earthRadius * (2 * Math.atan2(Math.sqrt(aa), Math.sqrt(Math.max(0, 1 - aa))));
  }

  class TourExperience {
    constructor(root) {
      this.root = root;
      this.tourId = String(root.dataset.tourId || "0");
      this.tourTitle = String(root.dataset.tourTitle || "").trim();
      this.tourSummary = String(root.dataset.tourSummary || "").trim();
      this.tourDuration = Number(root.dataset.tourDuration || 0);
      this.tourSupportEmail = String(root.dataset.tourSupportEmail || "").trim();
      this.mapHeight = clamp(Number(root.dataset.mapHeight || 520) || 520, 420, 680);
      this.steps = safeJsonParse(root.dataset.tourSteps || "[]", []);
      this.currentIndex = 0;
      this.completed = new Set();
      this.mode = "story";
      this.map = null;
      this.markers = [];
      this.routeOverview = null;
      this.route = null;
      this.liveRoute = null;
      this.userMarker = null;
      this.userAccuracy = null;
      this.targetZone = null;
      this.currentLocation = null;
      this.watchId = null;
      this.routeAbortController = null;
      this.transitionAbortController = null;
      this.lastRouteFetch = null;
      this.lastFittedStep = null;
      this.lastStoryFittedStep = -1;
      this.mapStatus = "";
      this.routeStartedFor = null;
      this.arrivedTransitions = new Set();
      this.transitionCache = new Map();
      this.autoOpenOnArrival = false;
      this.embedDiagnostics = new Map();
      this.serverEmbedUrls = new Map();
      this.embedDiagnosticController = null;

      const config = window.sbdpTourNavigation || {};
      this.routeEndpoint = String(root.dataset.routeEndpoint || config.routeEndpoint || "").trim();
      this.embedDiagnosticsEndpoint = String(root.dataset.embedDiagnosticsEndpoint || config.embedDiagnosticsEndpoint || "").trim();
      this.routeProfile = String(root.dataset.routeProfile || "walking").trim() || "walking";
      this.mapTiles = String(root.dataset.mapTiles || config.defaultMapTiles || "").trim();
      this.mapAttribution = String(root.dataset.mapAttribution || config.defaultMapAttribution || "").trim();
      this.restNonce = String(config.nonce || "").trim();
      this.googleMapsEmbedApiKey = String(root.dataset.googleMapsApiKey || config.googleMapsEmbedApiKey || "").trim();
      this.googleMapsEmbedLanguage = String(root.dataset.googleMapsLanguage || config.googleMapsEmbedLanguage || document.documentElement.lang || "nl").trim();
      this.googleMapsEmbedRegion = String(root.dataset.googleMapsRegion || config.googleMapsEmbedRegion || "").trim().toUpperCase();
      this.googleMapsEmbedUnits =
        String(root.dataset.googleMapsUnits || config.googleMapsEmbedUnits || "metric")
          .trim()
          .toLowerCase() === "imperial"
          ? "imperial"
          : "metric";

      if (!this.embedDiagnosticsEndpoint && this.routeEndpoint) {
        this.embedDiagnosticsEndpoint = this.routeEndpoint.replace(/\/route(?:\?.*)?$/i, "/embed-diagnostics");
      }
    }

    init() {
      if (!Array.isArray(this.steps) || this.steps.length === 0) {
        return;
      }

      this.hydrateState();
      this.bindBaseEvents();
      this.initMap();
      this.render();
    }

    ensureLayoutScaffold() {
      return;
    }

    hydrateState() {
      const hash = window.location.hash.replace("#step-", "");
      const fromHash = Number.parseInt(hash, 10);
      const fromStorage = Number.parseInt(localStorage.getItem(this.storageStepKey()) || "0", 10);
      const savedMode = String(localStorage.getItem(this.storageModeKey()) || "story").toLowerCase();
      const savedCompleted = safeJsonParse(localStorage.getItem(this.storageProgressKey()) || "[]", []);

      if (Array.isArray(savedCompleted)) {
        savedCompleted.forEach((value) => {
          const idx = Number.parseInt(String(value), 10);
          if (Number.isFinite(idx) && idx >= 0 && idx < this.steps.length) {
            this.completed.add(idx);
          }
        });
      }

      const savedArrivals = safeJsonParse(localStorage.getItem(this.storageArrivalKey()) || "[]", []);
      if (Array.isArray(savedArrivals)) {
        savedArrivals.forEach((value) => {
          const idx = Number.parseInt(String(value), 10);
          if (Number.isFinite(idx) && idx >= 0 && idx < this.steps.length - 1 && this.completed.has(idx)) {
            this.arrivedTransitions.add(idx);
          }
        });
      }

      if (Number.isFinite(fromHash) && fromHash > 0) {
        this.currentIndex = clamp(fromHash - 1, 0, this.steps.length - 1);
      } else if (Number.isFinite(fromStorage)) {
        this.currentIndex = clamp(fromStorage, 0, this.steps.length - 1);
      }

      this.mode = savedMode === "navigation" ? "navigation" : "story";
      this.normalizeProgressState();
    }

    normalizeProgressState() {
      const total = this.steps.length;
      if (total <= 0) {
        return;
      }

      const safeCurrent = clamp(this.currentIndex, 0, total - 1);
      const trimmedCompleted = new Set();
      this.completed.forEach((value) => {
        if (Number.isInteger(value) && value >= 0 && value <= safeCurrent && value < total) {
          trimmedCompleted.add(value);
        }
      });

      const trimmedArrivals = new Set();
      this.arrivedTransitions.forEach((value) => {
        if (Number.isInteger(value) && value >= 0 && value <= safeCurrent && value < total - 1 && trimmedCompleted.has(value)) {
          trimmedArrivals.add(value);
        }
      });

      const hasFutureStep = safeCurrent < total - 1;
      const keepCurrentCompleted =
        trimmedCompleted.has(safeCurrent) && (!hasFutureStep || this.mode === "navigation" || trimmedArrivals.has(safeCurrent) || this.isRouteStarted(safeCurrent));

      if (!keepCurrentCompleted) {
        trimmedCompleted.delete(safeCurrent);
        trimmedArrivals.delete(safeCurrent);
      }

      this.completed = trimmedCompleted;
      this.arrivedTransitions = trimmedArrivals;
    }

    bindBaseEvents() {
      this.root.querySelectorAll(SELECTORS.navPrev).forEach((button) => {
        button.addEventListener("click", () => this.goTo(this.currentIndex - 1));
      });

      this.root.querySelectorAll(SELECTORS.navNext).forEach((button) => {
        button.addEventListener("click", () => this.handleNext());
      });

      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          this.closeRouteSheet();
        }

        if (!this.root.contains(document.activeElement)) {
          return;
        }

        if (event.target && ["INPUT", "TEXTAREA"].includes(event.target.tagName)) {
          return;
        }

        if (event.key === "ArrowLeft") {
          event.preventDefault();
          this.goTo(this.currentIndex - 1);
        }

        if (event.key === "ArrowRight") {
          event.preventDefault();
          this.handleNext();
        }
      });
    }

    initMap() {
      const mapElement = this.root.querySelector(SELECTORS.map);
      if (!mapElement) {
        return;
      }

      if (typeof window.L === "undefined") {
        this.renderStaticRouteMap();
        this.updateMapStatus("Eenvoudige routekaart actief. Start route voor live afstand.");
        return;
      }

      const points = this.steps
        .map((step, index) => ({
          index,
          lat: Number(step.lat),
          lng: Number(step.lng),
          title: step.title || `Stap ${index + 1}`,
        }))
        .filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng));

      if (!points.length) {
        mapElement.innerHTML = '<p class="tour-map__placeholder">Geen locaties beschikbaar.</p>';
        this.updateMapStatus("Geen geolocaties gevonden in deze tour.");
        return;
      }

      this.map = window.L.map(mapElement, {
        center: [points[0].lat, points[0].lng],
        zoom: MAP_CONFIG.zoom,
      });

      if (this.mapTiles !== "") {
        const tileLayer = window.L.tileLayer(this.mapTiles, {
          maxZoom: 19,
          attribution: this.mapAttribution || "",
        });

        tileLayer.on("load", () => {
          mapElement.classList.remove("tour-map--tileless");
          this.updateMapStatus("Kaart geladen. Live navigatie start zodra locatie is toegestaan.");
        });

        tileLayer.on("tileerror", () => {
          mapElement.classList.add("tour-map--tileless");
          this.updateMapStatus("Kaarttiles laden niet. Route en stappen blijven wel beschikbaar.");
        });

        tileLayer.addTo(this.map);
      } else {
        mapElement.classList.add("tour-map--tileless");
        this.updateMapStatus("Kaarttiles ontbreken. Fallback-route blijft beschikbaar.");
      }

      this.markers = points.map((point) => {
        const icon = window.L.divIcon({
          className: 'tour-map-pin tour-map-pin--upcoming',
          html: `<span class="tour-map-pin__num">${point.index + 1}</span>`,
          iconSize: [32, 32],
          iconAnchor: [16, 16],
          popupAnchor: [0, -18],
        });
        const marker = window.L.marker([point.lat, point.lng], { icon }).addTo(this.map);

        marker.bindPopup(`<strong>${escapeHtml(point.title)}</strong>`);
        marker.on("click", () => this.goTo(point.index));
        return { marker, index: point.index };
      });

      if (points.length > 1) {
        this.route = window.L.polyline(
          points.map((point) => [point.lat, point.lng]),
          {
            color: "#9a6433",
            weight: 2.5,
            opacity: 0.35,
            dashArray: "6, 5",
          }
        ).addTo(this.map);
      }

      const bounds = window.L.featureGroup(this.markers.map((item) => item.marker)).getBounds();
      this.map.fitBounds(bounds.pad(0.15));
      this.updateMapStatus("Live navigatie start zodra locatie is toegestaan.");
    }

    render() {
      this.normalizeProgressState();
      this.renderSummary();
      this.renderStepList();
      this.renderStepContent();
      this.renderNavigationPanel();
      this.renderControls();
      this.renderMobileNav();
      this.syncModeState();
      this.renderMapState();
      this.bindDynamicEvents();
      this.ensureTransitionData();
      this.updateNavigationStatus();
      this.syncEmbeddedRouteUrls();
      this.runEmbedDiagnostics();
      this.persistState();
    }

    renderSummary() {
      const target = this.root.querySelector(SELECTORS.summaryPanel);
      if (!target) {
        return;
      }

      const progressState = this.getTourProgressState();
      const step = progressState.currentStep;
      if (!step) {
        return;
      }

      const total = progressState.total;
      const progressPercent = progressState.progressPercent;
      const arrivedCurrent = progressState.arrivalReady;
      const nextStep = progressState.nextStep;
      
      const completedCount = progressState.completedCount;
      const stateLabel = !nextStep
        ? "Laatste stop"
        : arrivedCurrent || this.mode === 'story'
        ? "Stop actief"
        : "Loop naar volgende locatie";
      const tourDurationLabel = this.tourDuration > 0 ? `${this.tourDuration} min` : "";
      const currentLocation = toStepLocation(step);
      const nextLabel = nextStep ? nextStep.title || `Stop ${progressState.currentIndex + 2}` : "Eindpunt";

      target.innerHTML = `
        <div class="tour-summary-panel__head">
          <div>
            <p class="tour-summary-panel__eyebrow">DagjeDenBosch Experience</p>
            <h1 class="tour-summary-panel__tour-title">${escapeHtml(this.tourTitle || "Private tour")}</h1>
          </div>
          <span class="tour-summary-panel__counter">Stop ${progressState.currentIndex + 1}/${total}</span>
        </div>
        <div class="tour-summary-panel__chips">
          <span class="tour-chip">${total} stops</span>
          ${tourDurationLabel ? `<span class="tour-chip">${escapeHtml(tourDurationLabel)}</span>` : ""}
          <span class="tour-chip ${arrivedCurrent || this.mode === 'story' ? 'tour-chip--route-ready' : ''}">${escapeHtml(stateLabel)}</span>
          <span class="tour-chip">Hierna: ${escapeHtml(toCompactText(nextLabel, 34))}</span>
        </div>
        <div class="tour-summary-panel__progress">
          <div class="tour-summary-panel__progress-bar" aria-hidden="true">
            <span style="width:${progressPercent}%;"></span>
          </div>
          <p class="tour-summary-panel__progress-text">${completedCount} van ${total} stops afgerond</p>
        </div>
      `;
    }

    renderStepContent() {
      const target = this.root.querySelector(SELECTORS.storyPanel);
      if (!target) {
        return;
      }

      this.closeRouteSheet(true);

      const progressState = this.getTourProgressState();
      const step = this.steps[this.currentIndex];
      if (!step) {
        return;
      }

      const contentParts = splitStepContent(step.content || "");
      const storyFlow = this.renderStoryFlow(step, toGamification(step), progressState);
      const continueBlock = this.renderContinueBlock(step, progressState);
      const media = this.renderMedia(step);
      const location = toStepLocation(step);

      target.innerHTML = `
        <article class="tour-story-layout">
          <header class="tour-step-current">
            <p class="tour-step-current__eyebrow">Hoofdstuk ${progressState.currentIndex + 1} van ${progressState.total}</p>
            <h2 class="tour-step-current__title">${escapeHtml(step.title || "Tourstop")}</h2>
            ${location ? `<p class="tour-step-current__location">${escapeHtml(toCompactText(location, 120))}</p>` : ""}
          </header>

          ${media.html ? media.html : ""}
          
          <div class="tour-layout__right">
            ${contentParts.introHtml ? `
              <div class="tour-step__intro">${contentParts.introHtml}</div>
            ` : ""}

            ${contentParts.bodyHtml ? `<div class="tour-step__story"><div class="tour-step__content">${contentParts.bodyHtml}</div></div>` : ""}

            ${storyFlow}
            ${continueBlock}
          </div>
        </article>
      `;
    }

    renderNavigationPanel() {
      const panel = this.root.querySelector(SELECTORS.navigationPanel);
      const copy = this.root.querySelector(SELECTORS.navigationCopy);

      if (!panel || !copy) {
        return;
      }

      const progressState = this.getTourProgressState();
      const step = progressState.currentStep;
      const hasNextStep = Boolean(progressState.nextStep);

      // Navigation panel only visible in navigation mode
      if (this.mode !== 'navigation') {
        panel.hidden = true;
        copy.innerHTML = '';
        return;
      }
      panel.hidden = false;

      copy.innerHTML = `
        <div class="tour-navigation-panel__body">
          ${this.renderNavigationRail(step, progressState)}
          <div class="tour-navigation-panel__route">
            <section class="tour-story-route tour-story-route--compact" aria-label="Routeoverzicht">
              <details class="tour-route-preview" data-tour-route-preview>
                <summary class="tour-route-preview__summary">
                  <span class="tour-route-preview__label">Routeoverzicht</span>
                  <span class="tour-route-preview__summary-copy">${escapeHtml(
                    hasNextStep ? "Alle stops in één lijst" : "Route voltooid"
                  )}</span>
                </summary>
                ${this.renderRoutePreviewList(progressState)}
              </details>
            </section>
          </div>
        </div>
      `;
    }

    renderStoryRail(step, progressState = this.getTourProgressState()) {
      return "";
    }

    renderStoryFlow(step, gamification, progressState = this.getTourProgressState()) {
      const viewModel = this.buildStepViewModel(step, progressState);
      return `
        <section class="tour-story-flow">
          <div class="tour-story-flow__block tour-story-flow__block--mission">
            <p class="tour-story-flow__eyebrow">Opdracht</p>
            <h3 class="tour-story-flow__title">${escapeHtml(viewModel.missionTitle)}</h3>
            ${viewModel.missionBody ? `<p class="tour-story-flow__detail">${escapeHtml(viewModel.missionBody)}</p>` : ""}
            ${viewModel.missionHint ? `<p class="tour-story-flow__detail"><strong>Hint:</strong> ${escapeHtml(viewModel.missionHint)}</p>` : ""}
            ${viewModel.missionReveal ? `<p class="tour-story-flow__detail"><strong>Antwoord:</strong> ${escapeHtml(viewModel.missionReveal)}</p>` : ""}
          </div>
        </section>
      `;
    }

    renderContinueBlock(step, progressState = this.getTourProgressState()) {
      const nextStep = progressState.nextStep;
      const nextTitle = nextStep ? nextStep.title || `Stop ${progressState.currentIndex + 2}` : "Tour afronden";
      const transition = nextStep ? this.getTransitionData(progressState.currentIndex) : null;
      const distance = transition ? formatDistance(Number(transition.distance || 0)) : null;
      const duration = transition ? formatDuration(Number(transition.duration || 0)) : null;
      const routeMeta = duration && distance ? `${duration} · ${distance}` : duration || distance || null;
      const primaryAction = nextStep
        ? `<button type="button" class="tour-story-flow__action" data-tour-start-route>Start route</button>`
        : `<button type="button" class="tour-story-flow__action" data-tour-complete${progressState.completedSet.has(progressState.currentIndex) ? " disabled" : ""}>${
            progressState.completedSet.has(progressState.currentIndex) ? "Tour afgerond" : "Tour afronden"
          }</button>`;

      return `
        <div class="tour-continue">
          ${nextStep ? `
            <div class="tour-continue__route-info">
              <span class="tour-continue__label">Volgende hoofdstuk</span>
              <span class="tour-continue__dest">Hierna: ${escapeHtml(nextTitle)}</span>
              ${routeMeta ? `<span class="tour-continue__meta">${escapeHtml(routeMeta)}</span>` : ""}
            </div>
          ` : `<div class="tour-continue__copy"><h3 class="tour-continue__title">Laatste stop</h3></div>`}
          <div class="tour-continue__actions">
            ${primaryAction}
            ${nextStep ? `<button type="button" class="tour-navigation-info__secondary tour-continue__details" data-tour-open-navigation>Route-details</button>` : ""}
          </div>
        </div>
      `;
    }

    renderMedia(step) {
      const title = escapeHtml(step && step.title ? step.title : "Tourstop");
      const imageUrl = sanitizeMediaUrl(step && step.imageUrl);
      const audioUrl = sanitizeMediaUrl(step && step.audioUrl);
      const videoUrl = sanitizeMediaUrl(step && step.videoUrl);
      const heygenUrl = normalizeHeygenUrl(step && (step.heygenEmbedUrl || step.heygenVideoUrl));

      const hasImage = Boolean(imageUrl);
      const hasAudio = Boolean(audioUrl);
      const hasVideo = Boolean(videoUrl);
      const hasEmbed = Boolean(heygenUrl);

      if (!hasImage && !hasAudio && !hasVideo && !hasEmbed) {
        return { html: "" };
      }

      let mediaHtml = "";

      if (hasEmbed) {
        mediaHtml = `
          <div class="tour-media tour-media--hero tour-media--embed">
            <div class="tour-media__frame-wrap">
              <iframe
                src="${escapeHtml(heygenUrl)}"
                title="${title}"
                loading="lazy"
                allow="autoplay; fullscreen; picture-in-picture"
                allowfullscreen
              ></iframe>
            </div>
          </div>
        `;
      } else if (hasVideo) {
        const videoIsEmbed = /(?:youtube\.com|youtu\.be|vimeo\.com|player\.vimeo\.com|youtube-nocookie\.com)/i.test(videoUrl);
        if (videoIsEmbed) {
          mediaHtml = `
            <div class="tour-media tour-media--hero tour-media--embed">
              <div class="tour-media__frame-wrap">
                <iframe
                  src="${escapeHtml(videoUrl)}"
                  title="${title}"
                  loading="lazy"
                  allow="autoplay; fullscreen; picture-in-picture"
                  allowfullscreen
                ></iframe>
              </div>
            </div>
          `;
        } else {
          mediaHtml = `
            <figure class="tour-media tour-media--hero tour-media--video">
              <video controls playsinline preload="metadata" title="${title}">
                <source src="${escapeHtml(videoUrl)}" />
              </video>
            </figure>
          `;
        }
      } else if (hasImage) {
        mediaHtml = `
          <figure class="tour-media tour-media--hero tour-media--image">
            <img src="${escapeHtml(imageUrl)}" alt="${title}" loading="lazy" decoding="async" />
          </figure>
        `;
      } else if (hasAudio) {
        mediaHtml = `
          <div class="tour-media tour-media--audio">
            <audio controls preload="metadata" aria-label="${title}">
              <source src="${escapeHtml(audioUrl)}" />
            </audio>
          </div>
        `;
      }

      return {
        html: `<div class="tour-step__media-primary">${mediaHtml}</div>`,
      };
    }

    renderStoryRouteContext(step, progressState = this.getTourProgressState(), compact = false) {
      const total = progressState.total;
      const nextStep = progressState.nextStep;
      const currentTitle = step && step.title ? step.title : `Stop ${progressState.currentIndex + 1}`;
      const currentLocation = toStepLocation(step) || currentTitle;
      const nextTitle = nextStep ? nextStep.title || `Stop ${progressState.currentIndex + 2}` : "Laatste halte";
      const nextLocation = nextStep ? toStepLocation(nextStep) || nextTitle : "Geen volgende route meer";
      const currentMeta =
        currentLocation && currentLocation !== currentTitle
          ? toCompactText(currentLocation, 36)
          : `Stop ${progressState.currentIndex + 1} van ${total}`;
      const nextMeta = nextStep
        ? nextLocation && nextLocation !== nextTitle
          ? toCompactText(nextLocation, 36)
          : "De volgende halte in je route"
        : "Je bent bij de laatste stop van deze route";
      const routePreviewSummary = nextStep
        ? `Bekijk ${Math.min(total, 5)} stops in één oogopslag`
        : "Route compleet";

      if (compact) {
        return `
          <section class="tour-story-route tour-story-route--compact" aria-label="Routeoverzicht">
            <div class="tour-story-route__copy">
              <p class="tour-story-route__eyebrow">Routeoverzicht</p>
              <h3 class="tour-story-route__title">Alle stops</h3>
            </div>
            <details class="tour-route-preview" data-tour-route-preview ${nextStep ? "" : "open"}>
              <summary class="tour-route-preview__summary">
                <span class="tour-route-preview__label">Bekijk route</span>
                <span class="tour-route-preview__summary-copy">${escapeHtml(routePreviewSummary)}</span>
              </summary>
              ${this.renderRoutePreviewList(progressState)}
            </details>
          </section>
        `;
      }

      return `
        <section class="tour-story-route" aria-label="Routecontext">
          <div class="tour-story-route__copy">
            <p class="tour-story-route__eyebrow">Routecontext</p>
            <h3 class="tour-story-route__title">Nu en straks</h3>
          </div>
          <div class="tour-route-context">
            <div class="tour-route-context__row">
              <span class="tour-route-context__label">Huidige stop</span>
              <strong class="tour-route-context__title">${escapeHtml(currentTitle)}</strong>
              <span class="tour-route-context__meta">${escapeHtml(currentMeta)}</span>
            </div>
            <div class="tour-route-context__row">
              <span class="tour-route-context__label">Volgende stop</span>
              <strong class="tour-route-context__title">${escapeHtml(nextTitle)}</strong>
              <span class="tour-route-context__meta">${escapeHtml(nextMeta)}</span>
            </div>
          </div>
          <details class="tour-route-preview" data-tour-route-preview ${nextStep ? "" : "open"}>
            <summary class="tour-route-preview__summary">
              <span class="tour-route-preview__label">Routepreview</span>
              <span class="tour-route-preview__summary-copy">${escapeHtml(routePreviewSummary)}</span>
            </summary>
            ${this.renderRoutePreviewList(progressState)}
          </details>
        </section>
      `;
    }

    renderRoutePreviewList(progressState = this.getTourProgressState()) {
      const total = progressState.total;
      const currentIndex = progressState.currentIndex;
      const nextIndex = currentIndex + 1;
      const items = this.steps
        .map((step, index) => {
          const title = step && step.title ? step.title : `Stop ${index + 1}`;
          const location = toStepLocation(step);
          const status = this.getStepStatus(index);
          const statusLabel =
            status === "current"
              ? "Je bent hier"
              : status === "next"
                ? "Volgende stop"
                : status === "completed"
                  ? "Afgerond"
                  : "Nog te bezoeken";

          return {
            index,
            title,
            location,
            status,
            statusLabel,
          };
        })
        .filter(Boolean);

      if (!items.length) {
        return `
          <div class="tour-route-preview__empty">
            <p>Geen routepunten beschikbaar.</p>
          </div>
        `;
      }

      return `
        <ol class="tour-route-preview__list" aria-label="Routepreview">
          ${items
            .map((item) => {
              const isCurrent = item.index === currentIndex;
              const isNext = item.index === nextIndex;
              const isJumpable = item.index !== currentIndex;
              const stateText = item.statusLabel;

              return `
                <li class="tour-route-preview__item">
                  <button
                    type="button"
                    class="tour-route-preview__stop tour-route-preview__stop--${escapeHtml(item.status)}${isCurrent ? " is-current" : ""}${isNext ? " is-next" : ""}"
                    ${isJumpable ? `data-tour-step-jump="${item.index}"` : 'aria-current="step"'}
                    ${isCurrent ? 'disabled aria-disabled="true"' : ""}
                  >
                    <span class="tour-route-preview__number">${item.index + 1}</span>
                    <span class="tour-route-preview__body">
                      <strong class="tour-route-preview__text">${escapeHtml(item.title)}</strong>
                      <span class="tour-route-preview__state">${escapeHtml(stateText)}</span>
                    </span>
                  </button>
                </li>
              `;
            })
            .join("")}
        </ol>
      `;
    }

    renderNavigationRail(step, progressState = this.getTourProgressState()) {
        const nextStep = progressState.nextStep;
        if (!nextStep) {
          // If no next step, we shouldn't really be in navigation mode, 
          // but if we are, show a simple complete state or the route preview.
          return this.renderStoryRouteContext(step, progressState, true);
        }

        const transition = this.getTransitionData(progressState.currentIndex);  
        const currentTitle = step && step.title ? step.title : "Stop " + (progressState.currentIndex + 1);
        const nextTitle = nextStep.title || "Stop " + (progressState.currentIndex + 2);
        const arrived = progressState.arrivalReady;
        const externalUrl = this.buildExternalNavigationUrl(nextStep, step);
        const hasCoordinates = Boolean(this.getStepPoint(nextStep));
        const proximity = this.getDistanceToStep(nextStep);
        const zone = this.getArrivalZone(proximity);
        const displayDistance = proximity !== null
          ? formatDistance(proximity)
          : transition
            ? formatDistance(Number(transition.distance || 0))
            : "Onbekend";
        const displayDuration = proximity !== null
          ? formatDuration(proximity / 1.38)
          : transition
            ? formatDuration(Number(transition.duration || 0))
            : "Onbekend";
        const proximityText = proximity !== null
          ? this.getArrivalZoneLabel(zone, proximity)
          : hasCoordinates
            ? "Sta locatie toe om je afstand tot de volgende stop te zien."
            : "Deze stop mist coordinaten. Gebruik het locatie-label als fallback.";
        
        let primaryCta;
        let ctaAction;

        if (arrived) {
            primaryCta = "Open stop: " + nextTitle;
            ctaAction = "data-tour-start-next-step";
        } else {
            primaryCta = "Open wandelroute in Google Maps";
            ctaAction = `href="${escapeHtml(externalUrl)}" target="_blank" rel="noopener" data-tour-native-navigation`;
        }

        return `
          <section class="tour-navigation-info tour-navigation-info--rail tour-navigation-info--${escapeHtml(zone)} ${arrived ? "tour-navigation-info--arrived" : "tour-navigation-info--active"}">
            <div class="tour-navigation-info__header">
              <p class="tour-navigation-info__eyebrow">${arrived || zone === "arrived" ? "Bestemming bereikt" : zone === "almost" ? "Bijna aangekomen" : "Route naar volgende stop"}</p>
              <h3 class="tour-navigation-info__title">${escapeHtml(nextTitle)}</h3>
              <p class="tour-navigation-info__status" data-tour-nav-status></p>
            </div>
            
            <div class="tour-navigation-info__context" aria-label="Routegegevens">
              <div class="tour-navigation-info__measurements">
                <span class="tour-navigation-info__dist">${escapeHtml(displayDistance)}</span>
                <span class="tour-navigation-info__dur">${escapeHtml(displayDuration)} lopen</span>
              </div>
              <p class="tour-navigation-info__subtext">Vanaf: ${escapeHtml(currentTitle)}</p>
              <p class="tour-navigation-info__gps">${escapeHtml(proximityText)}</p>
            </div>

            <div class="tour-navigation-info__actions">
              ${arrived ? `<button type="button" class="tour-route-cta" ${ctaAction}>${primaryCta}</button>` : `<a class="tour-route-cta" ${ctaAction}>${primaryCta}</a>`}
              ${!arrived ? `<button type="button" class="tour-route-cta tour-route-cta--secondary" data-tour-arrival-confirm>Ik ben aangekomen</button>` : ""}
              ${!arrived ? `<button type="button" class="tour-navigation-info__secondary" data-tour-start-route>${this.currentLocation ? "GPS in tour aan" : "GPS in tour starten"}</button>` : ""}
            </div>
          </section>
        `;
      }

    getEstimatedStopDurationMinutes(step, progressState = this.getTourProgressState()) {
      const totalStops = Math.max(Number(progressState.total || this.steps.length || 1), 1);
      const totalDuration = Number(this.tourDuration || 0);
      const mediaBoost = step && (step.videoUrl || step.audioUrl || step.heygenEmbedUrl || step.heygenVideoUrl) ? 2 : 0;
      if (Number.isFinite(totalDuration) && totalDuration > 0) {
        return Math.max(4, Math.round(totalDuration / totalStops) + mediaBoost);
      }
      return 6 + mediaBoost;
    }

    getTourProgressState() {
      const total = this.steps.length;
      const safeIndex = total > 0 ? clamp(this.currentIndex, 0, total - 1) : 0;
      const completedIndices = Array.from(this.completed.values()).filter(
        (value) => Number.isInteger(value) && value >= 0 && value < total
      );
      const completedSet = new Set(completedIndices);
      const completedCount = completedSet.size;
      const progressPercent = total > 0 ? Math.round((completedCount / total) * 100) : 0;
      const currentStep = this.steps[safeIndex] || null;
      const nextStep = safeIndex < total - 1 ? this.steps[safeIndex + 1] || null : null;

      return {
        total,
        currentIndex: safeIndex,
        currentStep,
        nextStep,
        completedSet,
        completedCount,
        progressPercent,
        navigationActive: this.mode === "navigation",
        arrivalReady: this.arrivedTransitions.has(safeIndex),
        routeStarted: this.isRouteStarted(safeIndex),
        items: this.steps.map((step, index) => {
          const isCurrent = index === safeIndex;
          const isReady = !isCurrent && this.arrivedTransitions.has(safeIndex) && index === safeIndex + 1 && safeIndex < total - 1;
          const isNext = !isCurrent && !isReady && index === safeIndex + 1 && safeIndex < total - 1;
          const isCompleted = completedSet.has(index);
          let visualState = "upcoming";

          if (isCurrent) {
            visualState = "current";
          } else if (isReady) {
            visualState = "ready";
          } else if (isNext) {
            visualState = "next";
          } else if (isCompleted) {
            visualState = "completed";
          }

          return {
            index,
            step,
            isCurrent,
            isReady,
            isNext,
            isCompleted,
            visualState,
          };
        }),
      };
    }

    buildStepViewModel(step, progressState = this.getTourProgressState()) {
      const gamification = toGamification(step);
      const stepType = String(step && step.type ? step.type : "text").toLowerCase();
      const missionSources = [
        gamification.title,
        gamification.label,
        gamification.name,
        gamification.display_value,
      ];
      const missionSource = missionSources.find((value) => typeof value === "string" && value.trim() !== "") || "";
      const missionTitle = normalizeMissionText(missionSource, "");
      const missionByType = {
        video: "Kijk de scène uit en onthoud welk detail Bosch hier verstopt.",
        audio: "Luister tot het einde en let op welk detail je buiten terug moet vinden.",
        vr: "Verken de scène en spot het symbool dat deze stop onthult.",
        game: "Rond de opdracht af en neem het gevonden detail mee naar buiten.",
        text: "Lees deze stop en zoek buiten het detail dat hier wordt aangewezen.",
      };
      const fallbackMission = missionByType[stepType] || missionByType.text;
      const missionBody =
        typeof gamification.body === "string" && gamification.body.trim() !== ""
          ? gamification.body.trim()
          : typeof gamification.challenge === "string" && gamification.challenge.trim() !== ""
            ? normalizeMissionText(gamification.challenge, fallbackMission)
            : fallbackMission;

      return {
        step,
        gamification,
        stepTitle: step && step.title ? step.title : `Stop ${progressState.currentIndex + 1}`,
        locationLabel: toStepLocation(step) || (step && step.title ? step.title : `Stop ${progressState.currentIndex + 1}`),
        typeLabel:
          {
            text: "Tekst & uitleg",
            audio: "Audio",
            video: "Video",
            vr: "VR / AR",
            game: "Opdracht",
          }[stepType] || humanizeDisplayValue(stepType, "Stap"),
        missionTitle: missionTitle || fallbackMission,
        missionBody,
        missionHint: typeof gamification.clue === "string" && gamification.clue.trim() !== "" ? normalizeMissionText(gamification.clue, "") : "",
        missionReveal: typeof gamification.reveal === "string" && gamification.reveal.trim() !== "" ? normalizeMissionText(gamification.reveal, "") : "",
        points: Number.isFinite(Number(step && step.points)) ? Number(step.points) : 0,
        progressLabel: `Stop ${progressState.currentIndex + 1} van ${progressState.total}`,
        progressMeta: `${progressState.completedCount}/${progressState.total} afgerond • ${progressState.progressPercent}%`,
      };
    }

    getRouteTargetStep() {
      return this.getNextStep(this.currentIndex) || this.steps[this.currentIndex] || null;
    }

    getNextStep(index = this.currentIndex) {
      const safeIndex = Number.isFinite(index) ? Math.max(0, Math.floor(index)) : this.currentIndex;
      const nextIndex = safeIndex + 1;
      return nextIndex >= 0 && nextIndex < this.steps.length ? this.steps[nextIndex] || null : null;
    }

    getStepPoint(step) {
      if (!step || typeof step !== "object") {
        return null;
      }

      const latCandidates = [step.lat, step.latitude, step.locationLat, step.spot && step.spot.lat, step.coordinates && step.coordinates.lat];
      const lngCandidates = [step.lng, step.longitude, step.locationLng, step.spot && step.spot.lng, step.coordinates && step.coordinates.lng];

      const lat = latCandidates.map(Number).find((value) => Number.isFinite(value));
      const lng = lngCandidates.map(Number).find((value) => Number.isFinite(value));
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        return { lat, lng };
      }

      const coordinatePairs = [
        step.coordinates,
        step.locationCoordinates,
        step.spot && step.spot.coordinates,
      ];

      for (const candidate of coordinatePairs) {
        if (Array.isArray(candidate) && candidate.length >= 2) {
          const candidateLat = Number(candidate[0]);
          const candidateLng = Number(candidate[1]);
          if (Number.isFinite(candidateLat) && Number.isFinite(candidateLng)) {
            return { lat: candidateLat, lng: candidateLng };
          }
        }
      }

      return null;
    }

    getDistanceToStep(step) {
      const point = this.getStepPoint(step);
      if (!point || !this.currentLocation) {
        return null;
      }

      return haversineMeters(this.currentLocation, point);
    }

    getArrivalZone(distance) {
      if (!Number.isFinite(distance)) {
        return "idle";
      }

      if (distance <= MAP_CONFIG.arrivalThresholdMeters) {
        return "arrived";
      }

      if (distance <= MAP_CONFIG.almostThresholdMeters) {
        return "almost";
      }

      if (distance <= MAP_CONFIG.nearThresholdMeters) {
        return "near";
      }

      return "walking";
    }

    getArrivalZoneLabel(zone, distance) {
      const distanceLabel = Number.isFinite(distance) ? formatDistance(distance) : "";

      if (zone === "arrived") {
        return `Je bent aangekomen. Afstand tot stop: ${distanceLabel}.`;
      }

      if (zone === "almost") {
        return `Je bent bijna bij de stop. Nog ${distanceLabel}.`;
      }

      if (zone === "near") {
        return `Je bent onderweg en komt dichterbij. Nog ${distanceLabel}.`;
      }

      return `Volg de route naar de volgende stop. Nog ${distanceLabel}.`;
    }

    isRouteStarted(index = this.currentIndex) {
      return Number.isFinite(index) && this.routeStartedFor === index;
    }

    getRouteTargetPoint() {
      return this.getStepPoint(this.getRouteTargetStep());
    }

    getTransitionKey(index = this.currentIndex) {
      const currentStep = this.steps[index] || null;
      const nextStep = this.getNextStep(index);
      if (!currentStep || !nextStep) {
        return "";
      }

      return `${index}:${currentStep.id || index}->${nextStep.id || index + 1}`;
    }

    getFallbackTransitionData(index = this.currentIndex) {
      const currentStep = this.steps[index] || null;
      const nextStep = this.getNextStep(index);
      const currentPoint = this.getStepPoint(currentStep);
      const nextPoint = this.getStepPoint(nextStep);

      if (!currentStep || !nextStep || !currentPoint || !nextPoint) {
        return null;
      }

      const distance = haversineMeters(currentPoint, nextPoint);
      return {
        distance,
        duration: distance / 1.38,
        path: [
          [currentPoint.lat, currentPoint.lng],
          [nextPoint.lat, nextPoint.lng],
        ],
        fallback: true,
      };
    }

    getTransitionData(index = this.currentIndex) {
      const key = this.getTransitionKey(index);
      return (key && this.transitionCache.get(key)) || this.getFallbackTransitionData(index);
    }

    ensureTransitionData() {
      const index = this.currentIndex;
      const key = this.getTransitionKey(index);
      const currentStep = this.steps[index] || null;
      const nextStep = this.getNextStep(index);
      const currentPoint = this.getStepPoint(currentStep);
      const nextPoint = this.getStepPoint(nextStep);

      if (!key || !currentPoint || !nextPoint || this.transitionCache.has(key)) {
        return;
      }

      if (!this.routeEndpoint) {
        return;
      }

      if (this.transitionAbortController) {
        this.transitionAbortController.abort();
      }
      this.transitionAbortController = new AbortController();

      const endpoint = new URL(this.routeEndpoint, window.location.origin);
      endpoint.searchParams.set("fromLat", String(currentPoint.lat));
      endpoint.searchParams.set("fromLng", String(currentPoint.lng));
      endpoint.searchParams.set("toLat", String(nextPoint.lat));
      endpoint.searchParams.set("toLng", String(nextPoint.lng));
      endpoint.searchParams.set("profile", this.routeProfile);

      const headers = { Accept: "application/json" };
      if (this.restNonce) {
        headers["X-WP-Nonce"] = this.restNonce;
      }

      fetch(endpoint.toString(), {
        method: "GET",
        credentials: "same-origin",
        headers,
        signal: this.transitionAbortController.signal,
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`Transition route request failed (${response.status})`);
          }

          return response.json();
        })
        .then((payload) => {
          const path = Array.isArray(payload.path) ? payload.path.map(toLatLngPair).filter((point) => Array.isArray(point)) : [];
          if (path.length < 2) {
            throw new Error("Transition route payload has no path");
          }

          this.transitionCache.set(key, {
            distance: Number(payload.distance || 0),
            duration: Number(payload.duration || 0),
            path,
            fallback: Boolean(payload.fallback),
          });

          if (index === this.currentIndex) {
            this.renderStepContent();
            this.bindDynamicEvents();
            this.updateNavigationStatus();
          }
        })
        .catch((error) => {
          if (error && error.name === "AbortError") {
            return;
          }

          const fallback = this.getFallbackTransitionData(index);
          if (fallback) {
            this.transitionCache.set(key, fallback);
          }
        });
    }

    renderCompletionPanel() {
      const ctaUrl = this.root.dataset.completionCtaUrl || "/plan-je-dag/";
      const ctaLabel = this.root.dataset.completionCtaLabel || "Plan je volgende experience";
      const totalXp = this.getTotalXp();

      return `
        <section class="tour-complete" aria-live="polite">
          <p class="tour-complete__eyebrow">Tour voltooid</p>
          <h3 class="tour-complete__title">Je Bosch Experience is afgerond</h3>
          <p class="tour-complete__text">Je hebt ${totalXp} XP verzameld. Klaar voor de volgende stadsbeleving?</p>
          <a class="tour-complete__cta" href="${escapeHtml(ctaUrl)}">${escapeHtml(ctaLabel)}</a>
        </section>
      `;
    }

    getStepStatus(index) {
      if (index === this.currentIndex) {
        return "current";
      }

      if (index === this.currentIndex + 1) {
        return "next";
      }

      if (this.completed.has(index)) {
        return "completed";
      }

      if (index < this.currentIndex) {
        return "visited";
      }

      return "upcoming";
    }

    renderRouteOverview() {
      const points = this.steps
        .map((step, index) => {
          const point = this.getStepPoint(step);
          if (!point) {
            return null;
          }

          return {
            index,
            lat: point.lat,
            lng: point.lng,
            status: this.getStepStatus(index),
          };
        })
        .filter(Boolean);

      const duplicateGroups = new Map();
      points.forEach((point) => {
        const key = `${point.lat.toFixed(5)}:${point.lng.toFixed(5)}`;
        const group = duplicateGroups.get(key) || [];
        group.push(point);
        duplicateGroups.set(key, group);
      });

      duplicateGroups.forEach((group) => {
        group.forEach((point, duplicateIndex) => {
          point.duplicateIndex = duplicateIndex;
          point.duplicateTotal = group.length;
        });
      });

      if (!points.length) {
        return `
          <div class="tour-route-rail__map tour-route-rail__map--empty">
            <p>Voeg locatievelden toe om de totale route zichtbaar te maken.</p>
          </div>
        `;
      }

      const latitudes = points.map((point) => point.lat);
      const longitudes = points.map((point) => point.lng);
      const minLat = Math.min(...latitudes);
      const maxLat = Math.max(...latitudes);
      const minLng = Math.min(...longitudes);
      const maxLng = Math.max(...longitudes);
      const latSpan = Math.max(maxLat - minLat, 0.00045);
      const lngSpan = Math.max(maxLng - minLng, 0.00045);
      const padding = 10;
      const width = 100;
      const height = 76;

      const project = (point) => {
        let x = padding + ((point.lng - minLng) / lngSpan) * (width - padding * 2);
        let y = padding + (1 - (point.lat - minLat) / latSpan) * (height - padding * 2);
        const duplicateTotal = Number(point.duplicateTotal || 0);
        if (duplicateTotal > 1) {
          const duplicateIndex = Number(point.duplicateIndex || 0);
          const angle = (Math.PI * 2 * duplicateIndex) / duplicateTotal;
          const radius = Math.min(5.5, 2 + duplicateTotal);
          x += Math.cos(angle) * radius;
          y += Math.sin(angle) * radius;
        }

        x = clamp(x, padding * 0.7, width - padding * 0.7);
        y = clamp(y, padding * 0.7, height - padding * 0.7);

        return {
          x,
          y,
          left: `${x}%`,
          top: `${(y / height) * 100}%`,
        };
      };

      const linePath = points
        .map((point, index) => {
          const projected = project(point);
          return `${index === 0 ? "M" : "L"} ${projected.x.toFixed(2)} ${projected.y.toFixed(2)}`;
        })
        .join(" ");

      const completedPath = points
        .filter((point) => point.index <= this.currentIndex)
        .map((point, index) => {
          const projected = project(point);
          return `${index === 0 ? "M" : "L"} ${projected.x.toFixed(2)} ${projected.y.toFixed(2)}`;
        })
        .join(" ");

      return `
        <div class="tour-route-rail__map">
          <div class="tour-route-rail__map-grid" aria-hidden="true"></div>
          <svg class="tour-route-rail__map-svg" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" aria-hidden="true">
            <path class="tour-route-rail__map-path" d="${linePath}"></path>
            ${completedPath ? `<path class="tour-route-rail__map-path tour-route-rail__map-path--done" d="${completedPath}"></path>` : ""}
          </svg>
          ${points
            .map((point) => {
              const projected = project(point);
              const status = point.status;
              return `
                <span class="tour-route-marker tour-route-marker--${status}" style="left:${projected.left};top:${projected.top};">
                  <span class="tour-route-marker__pin">${point.index + 1}</span>
                </span>
              `;
            })
            .join("")}
        </div>
      `;
    }

    renderStepList() {
      const stepList = this.root.querySelector(SELECTORS.stepList);
      if (!stepList) {
        return;
      }

      const progressState = this.getTourProgressState();
      const total = progressState.total;
      const currentStep = progressState.currentStep;
      const nextStep = progressState.nextStep;
      const currentTitle = currentStep ? currentStep.title || `Stop ${progressState.currentIndex + 1}` : "";
      const nextTitle = nextStep ? nextStep.title || `Stop ${progressState.currentIndex + 2}` : "";

      stepList.innerHTML = `
        <div class="tour-route-rail__overview tour-route-rail__overview--guided">
          <div class="tour-route-rail__heading">
            <p class="tour-route-rail__eyebrow">Route</p>
            <h2 class="tour-route-rail__title">${escapeHtml(this.tourTitle || "Privetour")}</h2>
          </div>
          <div class="tour-route-rail__focus">
            <div class="tour-route-rail__focus-item">
              <span class="tour-route-rail__focus-label">Nu</span>
              <strong class="tour-route-rail__focus-value">${escapeHtml(currentTitle || `Stop ${progressState.currentIndex + 1}`)}</strong>
              <span class="tour-route-rail__focus-meta">Stop ${progressState.currentIndex + 1} van ${total}</span>
            </div>
            ${nextTitle ? `
            <div class="tour-route-rail__focus-item">
              <span class="tour-route-rail__focus-label">Hierna</span>
              <strong class="tour-route-rail__focus-value">${escapeHtml(nextTitle)}</strong>
              <span class="tour-route-rail__focus-meta">Stop ${progressState.currentIndex + 2} van ${total}</span>
            </div>` : ""}
          </div>
          <div class="tour-route-rail__stops">
            ${progressState.items
              .map((item) => {
                const step = item.step || {};
                const title = step.title || `Stop ${item.index + 1}`;
                const stateText =
                  item.visualState === "current"
                    ? "Nu"
                    : item.visualState === "ready"
                    ? "Klaar"
                    : item.visualState === "next"
                    ? "Hierna"
                    : item.visualState === "completed"
                    ? "Afgerond"
                    : "Later";

                return `
                  <button
                    type="button"
                    class="tour-stop-card tour-stop-card--${escapeHtml(item.visualState)}"
                    ${item.isCurrent ? 'disabled aria-disabled="true"' : `data-tour-step-jump="${item.index}"`}
                  >
                    <span class="tour-stop-card__number">${item.index + 1}</span>
                    <span class="tour-stop-card__body">
                      <strong class="tour-stop-card__title">${escapeHtml(title)}</strong>
                      <span class="tour-stop-card__state">${escapeHtml(stateText)}</span>
                    </span>
                  </button>
                `;
              })
              .join("")}
          </div>
        </div>
      `;
    }

    renderProgress() {
      const mapMeta = this.root.querySelector(SELECTORS.mapMeta);
      const progressState = this.getTourProgressState();
      const completedCount = progressState.completedCount;
      const total = progressState.total;
      const ratio = progressState.progressPercent;
      const step = progressState.currentStep;
      const location = step ? toStepLocation(step) || step.title || "" : "";
      const nextStep = progressState.nextStep;
      const nextLabel = nextStep ? nextStep.title || `Stop ${progressState.currentIndex + 2}` : "Laatste halte";

      if (mapMeta) {
        mapMeta.innerHTML = `
          <div class="tour-map-meta__chips">
            <span class="tour-chip tour-chip--progress">Stop ${progressState.currentIndex + 1} van ${total}</span>
            <span class="tour-chip ${this.mode === "navigation" ? "tour-chip--route-ready" : ""}">${escapeHtml(nextLabel)}</span>
          </div>
          <p class="tour-map-meta__progress">${ratio}% voltooid &bull; ${completedCount}/${total} stops</p>
        `;
      }
    }

    renderControls() {
      const atStart = this.currentIndex <= 0;
      const atEnd = this.currentIndex >= this.steps.length - 1;
      const completedCurrent = this.completed.has(this.currentIndex);
      const arrivedCurrent = this.arrivedTransitions.has(this.currentIndex);
      const prevStep = !atStart ? this.steps[this.currentIndex - 1] : null;
      const nextStep = !atEnd ? this.steps[this.currentIndex + 1] : null;

      this.root.querySelectorAll(SELECTORS.navPrev).forEach((button) => {
        button.disabled = atStart;
        button.innerHTML = `
          <span class="tour-chapter-nav__eyebrow">${atStart ? "Start van de tour" : "Vorige stop"}</span>
          <span class="tour-chapter-nav__title">${escapeHtml(
            atStart ? "Geen vorige stop" : prevStep && prevStep.title ? prevStep.title : "Ga terug"
          )}</span>
        `;
      });

      this.root.querySelectorAll(SELECTORS.navNext).forEach((button) => {
        const isLocked = !atEnd && (!completedCurrent || !arrivedCurrent);
        button.disabled = atEnd;
        button.innerHTML = `
          <span class="tour-chapter-nav__eyebrow">${
            atEnd ? "Laatste stop" : !completedCurrent ? "Open eerst navigatie" : !arrivedCurrent ? "Bevestig je aankomst" : "Volgende stop"
          }</span>
          <span class="tour-chapter-nav__title">${escapeHtml(
            atEnd
              ? "Einde tour"
              : !completedCurrent
              ? "Missie afronden"
              : !arrivedCurrent
              ? nextStep && nextStep.title
                ? `Ga naar ${nextStep.title}`
                : "Ga naar de volgende stop"
              : nextStep && nextStep.title
              ? nextStep.title
              : "Verder naar de volgende stop"
          )}</span>
        `;
        button.dataset.intent = isLocked ? "navigation" : "next";
      });
    }

    renderMobileNav() {
      let bar = this.root.querySelector('[data-tour-mobile-nav]');
      if (!bar) {
        bar = document.createElement('div');
        bar.className = 'tour-mobile-nav';
        bar.setAttribute('data-tour-mobile-nav', '');
        this.root.appendChild(bar);
      }

      const progressState = this.getTourProgressState();
      const nextStep = progressState.nextStep;
      const atStart = progressState.currentIndex <= 0;
      const atEnd = progressState.currentIndex >= progressState.total - 1;
      const currentStep = progressState.currentStep;
      const completedCurrent = progressState.completedSet.has(progressState.currentIndex);
      const arrivedCurrent = this.arrivedTransitions.has(progressState.currentIndex);

      const transition = this.getTransitionData(progressState.currentIndex);
      const duration = transition ? formatDuration(Number(transition.duration || 0)) : null;
      const distance = transition ? formatDistance(Number(transition.distance || 0)) : null;
      const meta = duration && distance ? `${duration} · ${distance}` : duration || distance || '';
      const nextTitle = nextStep ? nextStep.title || `Stop ${progressState.currentIndex + 2}` : "Laatste stop";
      const currentTitle = currentStep ? currentStep.title || `Stop ${progressState.currentIndex + 1}` : "Tourstop";
      const statusText = nextStep
        ? arrivedCurrent
          ? "Je bent er bijna. Open het volgende hoofdstuk."
          : this.isRouteStarted(progressState.currentIndex)
          ? this.getNavigationStatusText()
          : `Hierna: ${nextTitle}`
        : completedCurrent
        ? "Tour afgerond"
        : "Laatste hoofdstuk";
      const nextLabel = atEnd ? (completedCurrent ? "Klaar" : "Afronden") : arrivedCurrent ? "Open" : "Volgende";
      const routeLabel = this.mode === "navigation" ? "Verhaal" : this.currentLocation ? "GPS" : "Route";

      bar.hidden = false;
      bar.innerHTML = `
        <div class="tour-mobile-nav__status">
          <span class="tour-mobile-nav__label">Stop ${progressState.currentIndex + 1}/${progressState.total}</span>
          <strong class="tour-mobile-nav__title">${escapeHtml(currentTitle)}</strong>
          <span class="tour-mobile-nav__hint">${escapeHtml(statusText)}</span>
        </div>
        ${meta && nextStep ? `<span class="tour-mobile-nav__meta">${escapeHtml(meta)}</span>` : ''}
        <div class="tour-mobile-nav__actions">
          <button type="button" class="tour-mobile-nav__button" data-tour-mobile-prev ${atStart ? "disabled" : ""}>
            <span aria-hidden="true">‹</span>
            <span>Vorige</span>
          </button>
          <button type="button" class="tour-mobile-nav__button tour-mobile-nav__button--route" data-tour-mobile-route>
            <span aria-hidden="true">${this.mode === "navigation" ? "✕" : "⌖"}</span>
            <span>${escapeHtml(routeLabel)}</span>
          </button>
          <button type="button" class="tour-mobile-nav__button tour-mobile-nav__button--primary" data-tour-mobile-next ${atEnd && completedCurrent ? "disabled" : ""}>
            <span>${escapeHtml(nextLabel)}</span>
            <span aria-hidden="true">›</span>
          </button>
        </div>
      `;
    }

    getApproximateTotalDistance() {
      let totalDistance = 0;
      for (let index = 0; index < this.steps.length - 1; index += 1) {
        const transition = this.getTransitionData(index);
        totalDistance += Number(transition && transition.distance ? transition.distance : 0);
      }

      if (totalDistance <= 0) {
        return "Route op locatie";
      }

      return formatDistance(totalDistance);
    }

    getApproximateTotalDuration() {
      let totalDuration = 0;
      for (let index = 0; index < this.steps.length - 1; index += 1) {
        const transition = this.getTransitionData(index);
        totalDuration += Number(transition && transition.duration ? transition.duration : 0);
      }

      return totalDuration;
    }

    renderMapState() {
      if (!this.map) {
        this.renderStaticRouteMap();
        return;
      }

      if (!this.map || !this.markers.length) {
        return;
      }

      const PIN_STATES = ['tour-map-pin--current', 'tour-map-pin--next', 'tour-map-pin--completed', 'tour-map-pin--upcoming', 'tour-map-pin--ready'];

      this.markers.forEach((item) => {
        const isCurrent = item.index === this.currentIndex;
        const isNext = item.index === this.currentIndex + 1;
        const isDone = this.completed.has(item.index);
        const isReady = this.arrivedTransitions.has(this.currentIndex) && isNext;
        const state = isCurrent ? 'current' : isReady ? 'ready' : isNext ? 'next' : isDone ? 'completed' : 'upcoming';

        const el = item.marker.getElement();
        if (el) {
          PIN_STATES.forEach((cls) => el.classList.remove(cls));
          el.classList.add(`tour-map-pin--${state}`);
        }
      });

      const markerEntry = this.markers.find((item) => item.index === this.currentIndex);
      const nextMarkerEntry = this.markers.find((item) => item.index === this.currentIndex + 1);
      if (!markerEntry) {
        return;
      }

      // In story mode: focus map on current + next stop pair when step changes
      if (this.mode === 'story') {
        if (this.lastStoryFittedStep !== this.currentIndex) {
          if (nextMarkerEntry) {
            const bounds = window.L.latLngBounds([
              markerEntry.marker.getLatLng(),
              nextMarkerEntry.marker.getLatLng(),
            ]);
            this.map.fitBounds(bounds.pad(0.35), { animate: true, duration: 0.6 });
          } else {
            this.map.flyTo(markerEntry.marker.getLatLng(), MAP_CONFIG.zoom, { duration: 0.6 });
          }
          this.lastStoryFittedStep = this.currentIndex;
        }
        return;
      }

      if (this.mode !== "navigation") {
        return;
      }

      if (!this.currentLocation) {
        if (nextMarkerEntry) {
          const bounds = window.L.latLngBounds([
            markerEntry.marker.getLatLng(),
            nextMarkerEntry.marker.getLatLng(),
          ]);
          this.map.fitBounds(bounds.pad(0.35));
        } else {
          this.map.flyTo(markerEntry.marker.getLatLng(), MAP_CONFIG.zoom, { duration: 0.65 });
        }
      } else if (this.lastFittedStep !== this.currentIndex) {
        const stepLatLng = (nextMarkerEntry || markerEntry).marker.getLatLng();
        const bounds = window.L.latLngBounds([
          [stepLatLng.lat, stepLatLng.lng],
          [this.currentLocation.lat, this.currentLocation.lng],
        ]);
        this.map.fitBounds(bounds.pad(0.2));
        this.lastFittedStep = this.currentIndex;
      }

      if (this.isRouteStarted(this.currentIndex)) {
        this.updateTargetZoneLayer();
        this.refreshLiveRoute(true);
      } else {
        this.updateTargetZoneLayer();
        this.clearLiveRoute();
      }
    }

    renderStaticRouteMap() {
      const mapElement = this.root.querySelector(SELECTORS.map);
      if (!mapElement) {
        return;
      }

      const points = this.steps
        .map((step, index) => ({
          index,
          lat: Number(step.lat),
          lng: Number(step.lng),
          title: step.title || `Stop ${index + 1}`,
        }))
        .filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng));

      if (!points.length) {
        mapElement.classList.add("tour-map--static");
        mapElement.innerHTML = '<p class="tour-map__placeholder">Geen locaties beschikbaar.</p>';
        this.updateMapStatus("Geen geolocaties gevonden in deze tour.");
        return;
      }

      const lats = points.map((point) => point.lat);
      const lngs = points.map((point) => point.lng);
      const minLat = Math.min(...lats);
      const maxLat = Math.max(...lats);
      const minLng = Math.min(...lngs);
      const maxLng = Math.max(...lngs);
      const latRange = Math.max(maxLat - minLat, 0.00008);
      const lngRange = Math.max(maxLng - minLng, 0.00008);
      const padding = 12;
      const scale = 100 - padding * 2;
      const project = (point) => ({
        x: padding + ((point.lng - minLng) / lngRange) * scale,
        y: padding + ((maxLat - point.lat) / latRange) * scale,
      });
      const plotted = points.map((point) => ({ ...point, ...project(point) }));
      const currentPoint = plotted.find((point) => point.index === this.currentIndex) || plotted[0];
      const targetStep = this.getRouteTargetStep();
      const targetPoint = targetStep
        ? plotted.find((point) => point.index === this.steps.indexOf(targetStep))
        : null;
      const distance = targetStep ? this.getDistanceToStep(targetStep) : null;
      const zone = this.getArrivalZone(distance);
      const zoneLabel = targetStep && Number.isFinite(distance)
        ? this.getArrivalZoneLabel(zone, distance)
        : this.isRouteStarted(this.currentIndex)
          ? "Locatie wordt opgehaald. Houd de tour open."
          : "Start route om live afstand op de kaart te zien.";
      const path = plotted.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(" ");
      const targetRadius = zone === "arrived" ? 5.5 : zone === "almost" ? 8 : zone === "near" ? 10 : 12;

      const markers = plotted.map((point) => {
        const isCurrent = point.index === this.currentIndex;
        const isNext = point.index === this.currentIndex + 1;
        const isDone = this.completed.has(point.index);
        const state = isCurrent ? "current" : isNext ? "next" : isDone ? "completed" : "upcoming";
        return `
          <span class="tour-static-map__marker tour-static-map__marker--${state}" style="left:${point.x.toFixed(2)}%;top:${point.y.toFixed(2)}%;" aria-label="${escapeHtml(point.title)}">
            ${point.index + 1}
          </span>
        `;
      }).join("");

      mapElement.classList.add("tour-map--static");
      mapElement.innerHTML = `
        <div class="tour-static-map" aria-label="Routekaart">
          <svg class="tour-static-map__svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <polyline class="tour-static-map__path" points="${escapeHtml(path)}"></polyline>
            ${targetPoint ? `<circle class="tour-static-map__zone tour-static-map__zone--${escapeHtml(zone)}" cx="${targetPoint.x.toFixed(2)}" cy="${targetPoint.y.toFixed(2)}" r="${targetRadius}"></circle>` : ""}
            ${this.currentLocation && currentPoint ? `<line class="tour-static-map__bearing" x1="${currentPoint.x.toFixed(2)}" y1="${currentPoint.y.toFixed(2)}" x2="${targetPoint ? targetPoint.x.toFixed(2) : currentPoint.x.toFixed(2)}" y2="${targetPoint ? targetPoint.y.toFixed(2) : currentPoint.y.toFixed(2)}"></line>` : ""}
          </svg>
          ${markers}
          <div class="tour-static-map__status tour-static-map__status--${escapeHtml(zone)}">
            <strong>${zone === "arrived" ? "Bestemming bereikt" : zone === "almost" ? "Bijna bij de stop" : zone === "near" ? "Dichtbij" : "Route"}</strong>
            <span>${escapeHtml(zoneLabel)}</span>
          </div>
        </div>
      `;
    }

    bindDynamicEvents() {
      this.root.querySelectorAll('[data-tour-native-navigation]').forEach((link) => {
        if (link.dataset.boundClick === "1") {
          return;
        }

        link.dataset.boundClick = "1";
        link.addEventListener("click", () => {
          if (this.getNextStep(this.currentIndex) && !this.completed.has(this.currentIndex)) {
            this.completed.add(this.currentIndex);
          }

          this.routeStartedFor = this.currentIndex;
          this.mode = "navigation";
          this.updateNavigationStatus("Maps geopend. Volg de route en bevestig je aankomst in de tour.");
          this.persistState();
        });
      });

      this.root.querySelectorAll(SELECTORS.complete).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => {
          if (this.completed.has(this.currentIndex)) {
            if (this.arrivedTransitions.has(this.currentIndex) && this.getNextStep(this.currentIndex)) {
              this.goTo(this.currentIndex + 1);
              return;
            }

            if (this.getNextStep(this.currentIndex)) {
              this.openNavigationMode(true);
            }
            return;
          }

          this.toggleComplete(this.currentIndex, true);
        });
      });

      this.root.querySelectorAll(SELECTORS.locate).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => this.enableLiveLocation(true));
      });

      this.root.querySelectorAll(SELECTORS.routeStart).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => {
          this.routeStartedFor = this.currentIndex;
          this.openNavigationMode(false);

          if (!window.isSecureContext || !navigator.geolocation) {
            this.openRouteSheet();
            this.updateNavigationStatus("Live navigatie is niet beschikbaar in deze browsercontext. Routeoverzicht geopend.");
            return;
          }

          this.enableLiveLocation(true);
          this.updateNavigationStatus();
        });
      });

      this.root.querySelectorAll(SELECTORS.arrivalConfirm).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => {
          if (!this.completed.has(this.currentIndex)) {
            const storyFlow = this.root.querySelector(".tour-story-flow");
            if (storyFlow) {
              storyFlow.scrollIntoView({ behavior: "smooth", block: "center" });
            }
            return;
          }

          this.arrivedTransitions.add(this.currentIndex);
          this.routeStartedFor = null;
          this.clearLiveRoute();
          this.mode = "navigation";
          this.updateMapStatus(`Je bent aangekomen bij ${this.getNextStep(this.currentIndex)?.title || "de volgende stop"}.`);
          this.render();
        });
      });

      this.root.querySelectorAll(SELECTORS.nextChapterStart).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => {
          if (!this.completed.has(this.currentIndex)) {
            const storyFlow = this.root.querySelector(".tour-story-flow");
            if (storyFlow) {
              storyFlow.scrollIntoView({ behavior: "smooth", block: "center" });
            }
            return;
          }

          this.goTo(this.currentIndex + 1);
        });
      });

      this.root.querySelectorAll(SELECTORS.openNavigation).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => this.openNavigationMode(false));
      });

      this.root.querySelectorAll(SELECTORS.openMap).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => {
          this.openNavigationMode(false);
          const mapPanel = this.root.querySelector(SELECTORS.mapPanel);
          if (mapPanel && typeof mapPanel.scrollIntoView === "function") {
            window.requestAnimationFrame(() => {
              mapPanel.scrollIntoView({ behavior: "smooth", block: "start" });
            });
          }
        });
      });

      this.root.querySelectorAll(SELECTORS.closeNavigation).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => this.closeNavigationMode());
      });

      this.root.querySelectorAll(SELECTORS.modeToggle).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => {
          const mode = String(button.getAttribute("data-tour-mode") || "story");
          if (mode === "navigation") {
            this.openNavigationMode(true);
            return;
          }

          this.closeNavigationMode();
        });
      });

      this.root.querySelectorAll(SELECTORS.stepJump).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => {
          const index = Number.parseInt(String(button.getAttribute("data-tour-step-jump") || ""), 10);
          if (!Number.isFinite(index)) {
            return;
          }

          this.goTo(index);
        });
      });

      this.root.querySelectorAll(SELECTORS.routeSheetOpen).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => this.openRouteSheet());
      });

      this.root.querySelectorAll(`${SELECTORS.routeSheetClose}, ${SELECTORS.routeSheetBackdrop}`).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => this.closeRouteSheet());
      });

      this.root.querySelectorAll(SELECTORS.mobilePrev).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => this.goTo(this.currentIndex - 1));
      });

      this.root.querySelectorAll(SELECTORS.mobileNext).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => this.handleNext());
      });

      this.root.querySelectorAll(SELECTORS.mobileRoute).forEach((button) => {
        if (button.dataset.boundClick === "1") {
          return;
        }

        button.dataset.boundClick = "1";
        button.addEventListener("click", () => {
          if (this.mode === "navigation") {
            this.closeNavigationMode();
            return;
          }

          this.openNavigationMode(true);
        });
      });

    }

    enableLiveLocation(forcePrompt) {
      if (!navigator.geolocation) {
        this.updateMapStatus("Live locatie niet ondersteund door deze browser.");
        this.updateNavigationStatus("Live locatie niet beschikbaar op dit apparaat.");
        return;
      }

      if (!window.isSecureContext) {
        const message = "Live locatie werkt alleen op HTTPS of localhost. Deze tour draait nu op een onbeveiligde URL, dus gebruik routeoverzicht of markeer aankomst handmatig.";
        this.updateMapStatus(message);
        this.updateNavigationStatus(message);
        return;
      }

      if (this.watchId !== null) {
        if (forcePrompt) {
          this.updateMapStatus("Live locatie is al actief.");
        }
        this.refreshLiveRoute(true);
        return;
      }

      this.updateMapStatus("Live locatie starten...");
      this.updateNavigationStatus("Live locatie wordt opgehaald...");

      this.watchId = navigator.geolocation.watchPosition(
        (position) => {
          this.currentLocation = {
            lat: Number(position.coords.latitude),
            lng: Number(position.coords.longitude),
            accuracy: Number(position.coords.accuracy || 0),
            timestamp: Number(position.timestamp || Date.now()),
          };

          this.updateUserLocationLayer();
          this.updateTargetZoneLayer();
          if (!this.map) {
            this.renderStaticRouteMap();
          }
          if (this.isRouteStarted(this.currentIndex)) {
            this.updateArrivalState();
            this.refreshLiveRoute(false);
          }
          this.updateNavigationStatus();
        },
        (error) => {
          let message = "Live locatie kon niet worden gestart.";

          if (error && error.code === 1) {
            message = "Locatietoegang geweigerd. Sta locatie toe om live navigatie te activeren.";
          } else if (error && error.code === 2) {
            message = "Locatie niet beschikbaar. Probeer buiten of ververs de pagina.";
          } else if (error && error.code === 3) {
            message = "Locatie ophalen duurt te lang. Probeer opnieuw.";
          }

          this.updateMapStatus(message);
          this.updateNavigationStatus(message);
        },
        {
          enableHighAccuracy: true,
          maximumAge: 10000,
          timeout: 12000,
        }
      );
    }

    updateUserLocationLayer() {
      if (!this.map || !this.currentLocation) {
        if (this.currentLocation) {
          this.renderStaticRouteMap();
        }
        return;
      }

      const latLng = [this.currentLocation.lat, this.currentLocation.lng];

      if (!this.userMarker) {
        this.userMarker = window.L.circleMarker(latLng, {
          radius: 7,
          color: "#ffffff",
          weight: 2,
          fillColor: "#2f80ed",
          fillOpacity: 0.95,
        }).addTo(this.map);
      } else {
        this.userMarker.setLatLng(latLng);
      }

      const accuracy = Number.isFinite(this.currentLocation.accuracy) ? this.currentLocation.accuracy : 0;
      if (accuracy > 2) {
        if (!this.userAccuracy) {
          this.userAccuracy = window.L.circle(latLng, {
            radius: accuracy,
            color: "rgba(47,128,237,0.35)",
            weight: 1,
            fillColor: "rgba(47,128,237,0.18)",
            fillOpacity: 0.24,
          }).addTo(this.map);
        } else {
          this.userAccuracy.setLatLng(latLng);
          this.userAccuracy.setRadius(accuracy);
        }
      }
    }

    refreshLiveRoute(force) {
      const target = this.getRouteTargetPoint();
      if (!target) {
        this.clearLiveRoute();
        return;
      }

      if (!this.currentLocation) {
        this.updateMapStatus("Sta locatie toe voor live route van punt tot punt.");
        this.updateNavigationStatus("Sta locatie toe om de live route te zien.");
        return;
      }

      const from = { lat: this.currentLocation.lat, lng: this.currentLocation.lng };
      const targetKey = `${this.currentIndex}:${target.lat.toFixed(5)},${target.lng.toFixed(5)}`;
      const now = Date.now();

      if (!force && this.lastRouteFetch) {
        const elapsed = now - this.lastRouteFetch.time;
        const movedMeters = haversineMeters(from, this.lastRouteFetch.from);
        if (elapsed < MAP_CONFIG.refreshIntervalMs && movedMeters < MAP_CONFIG.refreshDistanceMeters && this.lastRouteFetch.targetKey === targetKey) {
          return;
        }
      }

      if (!this.routeEndpoint) {
        this.drawFallbackRoute(from, target);
        return;
      }

      if (this.routeAbortController) {
        this.routeAbortController.abort();
      }
      this.routeAbortController = new AbortController();

      const endpoint = new URL(this.routeEndpoint, window.location.origin);
      endpoint.searchParams.set("fromLat", String(from.lat));
      endpoint.searchParams.set("fromLng", String(from.lng));
      endpoint.searchParams.set("toLat", String(target.lat));
      endpoint.searchParams.set("toLng", String(target.lng));
      endpoint.searchParams.set("profile", this.routeProfile);

      const headers = { Accept: "application/json" };
      if (this.restNonce) {
        headers["X-WP-Nonce"] = this.restNonce;
      }

      fetch(endpoint.toString(), {
        method: "GET",
        credentials: "same-origin",
        headers,
        signal: this.routeAbortController.signal,
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`Route request failed (${response.status})`);
          }
          return response.json();
        })
        .then((payload) => {
          const points = Array.isArray(payload.path) ? payload.path.map(toLatLngPair).filter((point) => Array.isArray(point)) : [];
          if (points.length < 2) {
            throw new Error("Route payload has no path");
          }

          this.drawLiveRoute(points, Boolean(payload.fallback));
          this.lastRouteFetch = { from, targetKey, time: now };

          const distance = Number(payload.distance || 0);
          const duration = Number(payload.duration || 0);
          const fallback = Boolean(payload.fallback);
          const status = fallback
            ? `Fallback route • ${formatDistance(distance)} • ${formatDuration(duration)}`
            : `Nog ${formatDistance(distance)} • ${formatDuration(duration)} lopen`;
          this.updateMapStatus(status);
          this.updateNavigationStatus(status);
        })
        .catch((error) => {
          if (error && error.name === "AbortError") {
            return;
          }

          this.drawFallbackRoute(from, target);
          this.updateMapStatus("Routering tijdelijk niet bereikbaar. Rechte fallback-route wordt getoond.");
          this.updateNavigationStatus("Live API tijdelijk niet bereikbaar. Fallback-route actief.");
        });
    }

    drawLiveRoute(points, isFallback) {
      if (!this.map) {
        return;
      }

      const options = {
        color: isFallback ? "#8a8f99" : "#d7863f",
        weight: isFallback ? 3 : 4,
        opacity: isFallback ? 0.68 : 0.9,
        dashArray: isFallback ? "8, 6" : undefined,
      };

      if (!this.liveRoute) {
        this.liveRoute = window.L.polyline(points, options).addTo(this.map);
      } else {
        this.liveRoute.setLatLngs(points);
        this.liveRoute.setStyle(options);
      }
    }

    drawFallbackRoute(from, target) {
      this.drawLiveRoute(
        [
          [from.lat, from.lng],
          [target.lat, target.lng],
        ],
        true
      );

      this.lastRouteFetch = {
        from,
        targetKey: `${this.currentIndex}:${target.lat.toFixed(5)},${target.lng.toFixed(5)}`,
        time: Date.now(),
      };

      const distance = haversineMeters(from, target);
      const duration = distance / 1.38;
      const status = `Fallback route • ${formatDistance(distance)} • ${formatDuration(duration)}`;
      this.updateMapStatus(status);
      this.updateNavigationStatus(status);
    }

    clearLiveRoute() {
      if (this.liveRoute && this.map) {
        this.map.removeLayer(this.liveRoute);
      }
      this.liveRoute = null;
      this.lastRouteFetch = null;
    }

    buildGoogleEmbedUrl(destinationStep, originStep) {
      const destination = this.getStepNavigationQuery(destinationStep);
      if (!this.googleMapsEmbedApiKey || !destination) {
        return "";
      }

      const origin = this.getStepNavigationQuery(originStep) || destination;
      const url = new URL("https://www.google.com/maps/embed/v1/directions");
      url.searchParams.set("key", this.googleMapsEmbedApiKey);
      url.searchParams.set("origin", origin);
      url.searchParams.set("destination", destination);
      url.searchParams.set("mode", "walking");
      url.searchParams.set("units", this.googleMapsEmbedUnits);

      if (this.googleMapsEmbedLanguage) {
        url.searchParams.set("language", this.googleMapsEmbedLanguage);
      }

      if (this.googleMapsEmbedRegion) {
        url.searchParams.set("region", this.googleMapsEmbedRegion);
      }

      return url.toString();
    }

    buildExternalNavigationUrl(destinationStep, originStep) {
      const destination = this.getStepNavigationQuery(destinationStep);
      if (!destination) {
        return "#";
      }

      const url = new URL("https://www.google.com/maps/dir/");
      url.searchParams.set("api", "1");
      url.searchParams.set("destination", destination);
      url.searchParams.set("travelmode", "walking");
      url.searchParams.set("dir_action", "navigate");

      if (this.currentLocation) {
        url.searchParams.set("origin", `${this.currentLocation.lat},${this.currentLocation.lng}`);
      } else {
        const origin = this.getStepNavigationQuery(originStep);
        if (origin) {
          url.searchParams.set("origin", origin);
        }
      }

      return url.toString();
    }

    getStepNavigationQuery(step) {
      if (!step || typeof step !== "object") {
        return "";
      }

      const point = this.getStepPoint(step);
      if (point) {
        return `${point.lat},${point.lng}`;
      }

      return toStepLocation(step) || String(step.title || "").trim();
    }

    syncEmbeddedRouteUrls() {
      const currentStep = this.steps[this.currentIndex] || null;
      const targetStep = this.getRouteTargetStep();
      if (!currentStep || !targetStep) {
        return;
      }

      const embedUrl = this.buildGoogleEmbedUrl(targetStep, currentStep);
      if (embedUrl) {
        this.root.querySelectorAll(`${SELECTORS.routeEmbedFrame}, ${SELECTORS.routeSheetFrame}`).forEach((frame) => {
          if (frame.getAttribute("src") !== embedUrl) {
            frame.setAttribute("src", embedUrl);
          }
        });
      }
    }

    runEmbedDiagnostics() {
      if (!this.embedDiagnosticsEndpoint || !this.googleMapsEmbedApiKey) {
        return;
      }

      const currentStep = this.steps[this.currentIndex] || null;
      const targetStep = this.getRouteTargetStep();
      const origin = this.getStepNavigationQuery(currentStep);
      const destination = this.getStepNavigationQuery(targetStep);
      if (!origin || !destination) {
        return;
      }

      const cacheKey = `${this.currentIndex}:${origin}>${destination}`;
      if (this.embedDiagnostics.has(cacheKey)) {
        return;
      }

      if (this.embedDiagnosticController) {
        this.embedDiagnosticController.abort();
      }

      this.embedDiagnosticController = new AbortController();
      const endpoint = new URL(this.embedDiagnosticsEndpoint, window.location.origin);
      endpoint.searchParams.set("origin", origin);
      endpoint.searchParams.set("destination", destination);
      endpoint.searchParams.set("mode", "walking");
      endpoint.searchParams.set("language", this.googleMapsEmbedLanguage || "nl");
      if (this.googleMapsEmbedRegion) {
        endpoint.searchParams.set("region", this.googleMapsEmbedRegion);
      }
      endpoint.searchParams.set("units", this.googleMapsEmbedUnits || "metric");

      fetch(endpoint.toString(), {
        method: "GET",
        credentials: "same-origin",
        headers: { Accept: "application/json", ...(this.restNonce ? { "X-WP-Nonce": this.restNonce } : {}) },
        signal: this.embedDiagnosticController.signal,
      })
        .then((response) => response.json().catch(() => ({})).then((payload) => ({ ok: response.ok, status: response.status, payload })))
        .then(({ ok, status, payload }) => {
          this.embedDiagnostics.set(cacheKey, { ok, status, payload, checkedAt: Date.now() });
          this.serverEmbedUrls.set(cacheKey, this.buildGoogleEmbedUrl(targetStep, currentStep));
        })
        .catch((error) => {
          if (error && error.name === "AbortError") {
            return;
          }

          this.embedDiagnostics.set(cacheKey, {
            ok: false,
            status: 0,
            reason: "request_error",
            message: error && error.message ? error.message : "Embed diagnostics failed",
            checkedAt: Date.now(),
          });
        });
    }

    openRouteSheet() {
      const preview = this.root.querySelector("[data-tour-route-preview]");
      if (!preview) {
        this.updateNavigationStatus("Routeoverzicht niet beschikbaar.");
        return;
      }

      if (typeof preview.open !== "boolean") {
        preview.setAttribute("open", "");
      }
      preview.open = true;
      preview.classList.add("is-open");
      if (typeof preview.scrollIntoView === "function") {
        window.requestAnimationFrame(() => {
          preview.scrollIntoView({ behavior: "smooth", block: "center" });
        });
      }
      this.updateNavigationStatus("Routeoverzicht geopend.");
    }

    closeRouteSheet(silent = false) {
      const preview = this.root.querySelector("[data-tour-route-preview]");
      if (preview) {
        preview.classList.remove("is-open");
        preview.open = false;
      }
      if (!silent) {
        this.updateNavigationStatus("Routeoverzicht gesloten.");
      }
    }

    updateTargetZoneLayer() {
      if (!this.map || !window.L) {
        return;
      }

      const targetStep = this.getRouteTargetStep();
      const target = this.getStepPoint(targetStep);
      if (!target) {
        if (this.targetZone) {
          this.map.removeLayer(this.targetZone);
          this.targetZone = null;
        }
        return;
      }

      const distance = this.getDistanceToStep(targetStep);
      const zone = this.getArrivalZone(distance);
      const styleByZone = {
        idle: { color: "#d6a461", fillColor: "rgba(214,164,97,0.14)", fillOpacity: 0.18 },
        walking: { color: "#72aee6", fillColor: "rgba(114,174,230,0.14)", fillOpacity: 0.18 },
        near: { color: "#d6a461", fillColor: "rgba(214,164,97,0.20)", fillOpacity: 0.24 },
        almost: { color: "#f0b15b", fillColor: "rgba(240,177,91,0.30)", fillOpacity: 0.32 },
        arrived: { color: "#68de7c", fillColor: "rgba(104,222,124,0.28)", fillOpacity: 0.34 },
      };
      const style = styleByZone[zone] || styleByZone.idle;
      const latLng = [target.lat, target.lng];
      const radius = zone === "arrived" ? MAP_CONFIG.arrivalThresholdMeters : MAP_CONFIG.almostThresholdMeters;

      if (!this.targetZone) {
        this.targetZone = window.L.circle(latLng, {
          radius,
          color: style.color,
          weight: 2,
          fillColor: style.fillColor,
          fillOpacity: style.fillOpacity,
          dashArray: zone === "arrived" ? null : "6, 5",
        }).addTo(this.map);
      } else {
        this.targetZone.setLatLng(latLng);
        this.targetZone.setRadius(radius);
        this.targetZone.setStyle({
          color: style.color,
          fillColor: style.fillColor,
          fillOpacity: style.fillOpacity,
          dashArray: zone === "arrived" ? null : "6, 5",
        });
      }
    }

    getNavigationStatusText() {
      const nextStep = this.getNextStep(this.currentIndex);
      const currentStep = this.steps[this.currentIndex] || null;
      const currentLocationLabel = currentStep ? toStepLocation(currentStep) || currentStep.title || `Stop ${this.currentIndex + 1}` : "deze stop";
      const nextLocationLabel = nextStep ? toStepLocation(nextStep) || nextStep.title || `Stop ${this.currentIndex + 2}` : "";

      if (nextStep && this.arrivedTransitions.has(this.currentIndex)) {
        return `Je bent aangekomen bij ${nextLocationLabel}. Open nu het volgende hoofdstuk.`;
      }

      if (nextStep && this.currentLocation) {
        const distance = this.getDistanceToStep(nextStep);
        const zone = this.getArrivalZone(distance);
        return this.getArrivalZoneLabel(zone, distance);
      }

      if (nextStep && this.isRouteStarted(this.currentIndex) && this.mapStatus) {
        return this.mapStatus;
      }

      if (nextStep) {
        const transition = this.getTransitionData(this.currentIndex);
        if (transition) {
          return `Nog ${formatDistance(Number(transition.distance || 0))} • ${formatDuration(Number(transition.duration || 0))} lopen van ${currentLocationLabel} naar ${nextLocationLabel}.`;
        }
      }

      if (this.mapStatus) {
        return this.mapStatus;
      }

      return this.isRouteStarted(this.currentIndex)
        ? "Live navigatie actief."
        : "Start live navigatie of open het routeoverzicht.";
    }

    updateArrivalState() {
      const nextStep = this.getNextStep(this.currentIndex);
      const target = this.getStepPoint(nextStep);
      if (
        !nextStep ||
        !target ||
        !this.currentLocation ||
        this.arrivedTransitions.has(this.currentIndex) ||
        !this.completed.has(this.currentIndex)
      ) {
        return;
      }

      const distance = haversineMeters(this.currentLocation, target);
      if (distance > MAP_CONFIG.arrivalThresholdMeters) {
        return;
      }

      this.arrivedTransitions.add(this.currentIndex);
      this.routeStartedFor = null;
      this.clearLiveRoute();
      this.updateMapStatus(`Je bent aangekomen bij ${nextStep.title || `stop ${this.currentIndex + 2}`}.`);

      if (this.autoOpenOnArrival) {
        this.goTo(this.currentIndex + 1);
        return;
      }

      this.mode = "navigation";
      this.render();
    }

    updateMapStatus(message) {
      this.mapStatus = String(message || "").trim();
      const status = this.root.querySelector(SELECTORS.mapStatus);
      if (status) {
        status.textContent = this.mapStatus;
      }
    }

    updateNavigationStatus(message) {
      if (typeof message === "string" && message.trim() !== "") {
        this.mapStatus = message.trim();
      }

      const status = this.getNavigationStatusText();
      this.root.querySelectorAll(SELECTORS.navStatus).forEach((node) => {
        node.textContent = status;
      });

      this.root.querySelectorAll(".tour-navigation-info [data-tour-start-route]").forEach((button) => {
        button.textContent = this.currentLocation ? "GPS in tour aan" : "GPS in tour starten";
      });

      const currentStep = this.steps[this.currentIndex] || null;
      const targetStep = this.getRouteTargetStep();
      if (targetStep) {
        this.root.querySelectorAll(SELECTORS.routeLink).forEach((link) => {
          link.setAttribute("href", this.buildExternalNavigationUrl(targetStep, currentStep));
        });
      }
    }

    handleNext() {
      if (!this.completed.has(this.currentIndex)) {
        if (this.getNextStep(this.currentIndex)) {
          this.openNavigationMode(true);
          return;
        }

        const storyFlow = this.root.querySelector(".tour-story-flow");
        if (storyFlow) {
          storyFlow.scrollIntoView({ behavior: "smooth", block: "center" });
        }
        return;
      }

      if (!this.arrivedTransitions.has(this.currentIndex) && this.getNextStep(this.currentIndex)) {
        this.openNavigationMode(true);
        return;
      }

      this.goTo(this.currentIndex + 1);
    }

    goTo(index) {
      if (!Number.isFinite(index)) {
        return;
      }

      const nextIndex = clamp(index, 0, this.steps.length - 1);
      if (nextIndex === this.currentIndex) {
        return;
      }

      this.currentIndex = nextIndex;
      this.lastFittedStep = null;
      this.routeStartedFor = null;
      this.clearLiveRoute();
      this.mode = "story";
      document.body.classList.remove("tour-route-sheet-open", "tour-navigation-mode-open");
      window.location.hash = `step-${nextIndex + 1}`;
      this.render();
    }

    toggleComplete(index, completed) {
      if (!Number.isFinite(index) || index < 0 || index >= this.steps.length) {
        return;
      }

      if (completed) {
        this.completed.add(index);
        if (index === this.currentIndex && this.getNextStep(index)) {
          this.mode = "navigation";
        }
      } else {
        this.completed.delete(index);
        this.arrivedTransitions.delete(index);
      }

      this.render();
    }

    getTotalXp() {
      return this.steps.reduce((total, step) => total + Number(step.points || 0), 0);
    }

    getEarnedXp() {
      let xp = 0;
      this.completed.forEach((index) => {
        const step = this.steps[index];
        if (step) {
          xp += Number(step.points || 0);
        }
      });
      return xp;
    }

    isTourCompleted() {
      const progressState = this.getTourProgressState();
      return progressState.total > 0 && progressState.completedCount >= progressState.total;
    }

    storageStepKey() {
      return `sbdp_tour_step_${this.tourId}`;
    }

    storageProgressKey() {
      return `sbdp_tour_progress_${this.tourId}`;
    }

    storageArrivalKey() {
      return `sbdp_tour_arrival_${this.tourId}`;
    }

    storageModeKey() {
      return `sbdp_tour_mode_${this.tourId}`;
    }

    persistState() {
      localStorage.setItem(this.storageStepKey(), String(this.currentIndex));
      localStorage.setItem(this.storageProgressKey(), JSON.stringify(Array.from(this.completed.values())));
      localStorage.setItem(this.storageArrivalKey(), JSON.stringify(Array.from(this.arrivedTransitions.values())));
      localStorage.setItem(this.storageModeKey(), this.mode);
    }

    openNavigationMode(startLive = false) {
      if (this.getNextStep(this.currentIndex) && !this.completed.has(this.currentIndex)) {
        this.completed.add(this.currentIndex);
      }

      if (this.getNextStep(this.currentIndex) && !this.arrivedTransitions.has(this.currentIndex)) {
        this.routeStartedFor = this.currentIndex;
      }

      if (this.mode === "navigation") {
        this.syncModeState();
        this.scrollNavigationIntoView();
        if (startLive) {
          this.enableLiveLocation(true);
        }
        return;
      }

      this.mode = "navigation";
      this.render();
      this.refreshMapLayout();
      this.scrollNavigationIntoView();
      this.updateNavigationStatus("Route geopend. Volg de kaart en bevestig je aankomst in de tour.");

      if (startLive) {
        if (!window.isSecureContext || !navigator.geolocation) {
          this.openRouteSheet();
          this.updateNavigationStatus("Live navigatie is niet beschikbaar in deze browsercontext. Routeoverzicht geopend.");
          return;
        }

        this.enableLiveLocation(true);
      }
    }

    scrollNavigationIntoView() {
      const navigationPanel = this.root.querySelector(SELECTORS.navigationPanel);
      const target = this.root.querySelector(SELECTORS.mapPanel) || navigationPanel;
      if (!target || typeof target.scrollIntoView !== "function") {
        return;
      }

      window.requestAnimationFrame(() => {
        target.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    }

    closeNavigationMode() {
      this.closeRouteSheet();

      if (this.mode === "story") {
        this.syncModeState();
        return;
      }

      this.mode = "story";
      this.render();
      this.refreshMapLayout();
    }

    syncModeState() {
      const storyPanel = this.root.querySelector(SELECTORS.storyPanel);
      const navigationPanel = this.root.querySelector(SELECTORS.navigationPanel);

      if (storyPanel) {
        // In the new grid, the story panel is the main column content
        // and should only be hidden if navigation is strictly the ONLY thing to show.
        // We actually want both visible or swapped via grid-area if needed.
        // For now, let's keep it visible unless we really want to swap it out.
        storyPanel.hidden = this.mode === "navigation";
      }

      if (navigationPanel) {
        navigationPanel.hidden = this.mode !== "navigation";
      }

      this.root.classList.toggle("is-navigation-mode", this.mode === "navigation");
      document.body.classList.toggle("tour-navigation-mode-open", this.mode === "navigation");
      this.refreshMapLayout();
    }

    refreshMapLayout() {
      if (!this.map || this.mode !== "navigation" || typeof this.map.invalidateSize !== "function") {
        return;
      }

      window.requestAnimationFrame(() => {
        if (!this.map || this.mode !== "navigation" || typeof this.map.invalidateSize !== "function") {
          return;
        }

        this.map.invalidateSize({ animate: false });
        this.renderMapState();
      });
    }
  }

  function bootstrap() {
    document.querySelectorAll(SELECTORS.root).forEach((root) => {
      const instance = new TourExperience(root);
      instance.init();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootstrap);
  } else {
    bootstrap();
  }
})();

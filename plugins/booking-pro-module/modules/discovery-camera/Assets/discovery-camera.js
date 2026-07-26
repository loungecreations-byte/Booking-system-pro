(() => {
  "use strict";

  const config = window.ddbDiscoveryCamera || {};
  if (!config.featureEnabled) return;
  const roots = new WeakMap();
  const queueDatabase = "ddb-discovery-camera";

  const openQueue = () => new Promise((resolve, reject) => {
    if (!window.indexedDB) {
      reject(new Error("Offline opslag niet beschikbaar."));
      return;
    }
    const request = indexedDB.open(queueDatabase, 1);
    request.onupgradeneeded = () => {
      const database = request.result;
      if (!database.objectStoreNames.contains("uploads")) {
        database.createObjectStore("uploads", { keyPath: "id" });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });

  const queueUpload = async (root, step, file) => {
    const database = await openQueue();
    const record = {
      id: crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
      tourId: Number(root.dataset.tourId || 0),
      stepId: Number(step.id || 0),
      file,
      createdAt: Date.now(),
    };
    await new Promise((resolve, reject) => {
      const transaction = database.transaction("uploads", "readwrite");
      transaction.objectStore("uploads").put(record);
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
    });
    database.close();
  };

  const queuedUploads = async () => {
    const database = await openQueue();
    const records = await new Promise((resolve, reject) => {
      const request = database.transaction("uploads", "readonly").objectStore("uploads").getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
    });
    database.close();
    return records;
  };

  const removeQueuedUpload = async (id) => {
    const database = await openQueue();
    await new Promise((resolve, reject) => {
      const transaction = database.transaction("uploads", "readwrite");
      transaction.objectStore("uploads").delete(id);
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
    });
    database.close();
  };

  const parseSteps = (root) => {
    try {
      const steps = JSON.parse(root.dataset.tourSteps || "[]");
      return Array.isArray(steps) ? steps : [];
    } catch {
      return [];
    }
  };

  const currentIndex = (root, steps) => {
    const hash = Number.parseInt(location.hash.replace("#step-", ""), 10);
    if (Number.isFinite(hash) && hash >= 0 && hash < steps.length) return hash;
    const saved = Number.parseInt(localStorage.getItem(`sbdp_tour_step_${root.dataset.tourId || "0"}`) || "0", 10);
    return Number.isFinite(saved) && saved >= 0 && saved < steps.length ? saved : 0;
  };

  const request = async (path, options = {}) => {
    const response = await fetch(`${String(config.restBase || "").replace(/\/$/, "")}${path}`, {
      credentials: "same-origin",
      ...options,
      headers: { "X-WP-Nonce": String(config.nonce || ""), ...(options.headers || {}) },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(payload.message || "De cameraverbinding is tijdelijk niet beschikbaar.");
      error.status = response.status;
      throw error;
    }
    return payload;
  };

  const button = (label, variant = "") => {
    const element = document.createElement("button");
    element.type = "button";
    element.className = `ddb-camera__button ${variant}`.trim();
    element.textContent = label;
    return element;
  };

  const stopCamera = (state) => {
    state?.stream?.getTracks().forEach((track) => track.stop());
    if (state) state.stream = null;
  };

  const lockNavigation = (root, locked, message = "") => {
    root.querySelectorAll("[data-tour-start-route], [data-tour-complete], [data-tour-mobile-next]").forEach((control) => {
      control.disabled = locked;
      control.setAttribute("aria-disabled", locked ? "true" : "false");
      control.title = locked ? message : "";
    });
  };

  const feedback = (mount, title, message, variant = "info", tip = "") => {
    const target = mount.querySelector("[data-camera-feedback]");
    if (!target) return;
    target.dataset.variant = variant;
    target.replaceChildren();
    const heading = document.createElement("strong");
    heading.textContent = title;
    const copy = document.createElement("span");
    copy.textContent = message;
    target.append(heading, copy);
    if (tip) {
      const coach = document.createElement("small");
      coach.textContent = tip;
      target.append(coach);
    }
  };

  const setBusy = (mount, busy) => {
    mount.dataset.busy = busy ? "1" : "0";
    mount.querySelectorAll("button, input").forEach((control) => { control.disabled = busy; });
    mount.querySelector("[data-camera-progress]")?.toggleAttribute("hidden", !busy);
  };

  const renderScores = (mount, result) => {
    const target = mount.querySelector("[data-camera-result]");
    if (!target) return;
    target.replaceChildren();
    if (!Number.isFinite(Number(result.total_score))) {
      target.hidden = true;
      return;
    }
    target.hidden = false;
    const score = document.createElement("strong");
    score.className = "ddb-camera__total";
    score.textContent = `${Number(result.total_score)} / 100`;
    const list = document.createElement("dl");
    const labels = {
      object: "Object", historical: "Historie", composition: "Compositie", creativity: "Creativiteit",
      perspective: "Perspectief", lighting: "Belichting", symmetry: "Symmetrie", detail: "Details",
    };
    Object.entries(result.scores || {}).forEach(([key, value]) => {
      const term = document.createElement("dt");
      term.textContent = labels[key] || key;
      const description = document.createElement("dd");
      description.textContent = `${Number(value)}%`;
      list.append(term, description);
    });
    target.append(score, list);
  };

  const showPreview = (state, mount, file) => {
    if (!file) return;
    if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
    state.pendingFile = file;
    state.previewUrl = URL.createObjectURL(file);
    const preview = mount.querySelector("[data-camera-preview]");
    preview.src = state.previewUrl;
    preview.hidden = false;
    mount.querySelector("video").hidden = true;
    mount.querySelector("[data-camera-confirm]").hidden = false;
    mount.querySelector("[data-camera-retake]").hidden = false;
    mount.querySelector("[data-camera-shutter]").hidden = true;
    feedback(mount, "Foto klaar", "Controleer de foto voordat je hem laat beoordelen.", "info");
  };

  const resetPreview = (state, mount) => {
    if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
    state.previewUrl = "";
    state.pendingFile = null;
    const preview = mount.querySelector("[data-camera-preview]");
    preview.removeAttribute("src");
    preview.hidden = true;
    mount.querySelector("video").hidden = false;
    mount.querySelector("[data-camera-confirm]").hidden = true;
    mount.querySelector("[data-camera-retake]").hidden = true;
    mount.querySelector("[data-camera-shutter]").hidden = false;
    startCamera(state, mount);
  };

  const upload = async (root, state, mount, step, file, queuedId = "") => {
    if (!file || file.size > Number(config.maxUploadBytes || 8388608)) {
      feedback(mount, "Foto te groot", "Gebruik een foto van maximaal 8 MB.", "error");
      return;
    }
    if (!mount.querySelector("[data-camera-consent]")?.checked) {
      feedback(mount, "Toestemming nodig", "Bevestig dat je deze foto voor de opdracht wilt laten analyseren.", "error");
      return;
    }
    setBusy(mount, true);
    feedback(mount, "AI kijkt mee…", "Je foto wordt beveiligd, gecomprimeerd en beoordeeld.", "loading");
    try {
      const attempt = await request("/photo-attempts", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
        },
        body: JSON.stringify({ tour_id: Number(root.dataset.tourId || 0), step_id: Number(step.id || 0), consent: true }),
      });
      const data = new FormData();
      data.append("photo", file, file.name || "discovery-photo.jpg");
      const result = await request(`/photo-attempts/${encodeURIComponent(attempt.attempt_uuid)}/complete-upload`, {
        method: "POST",
        body: data,
      });
      mount.dataset.attemptStatus = String(result.status || "review");
      renderScores(mount, result);
      feedback(
        mount,
        result.feedback?.title || "Foto beoordeeld",
        result.feedback?.message || "Je foto is veilig ontvangen.",
        result.status === "passed" ? "success" : result.status === "failed" ? "error" : "review",
        result.feedback?.coach_tip || ""
      );
      if (result.status === "passed") {
        lockNavigation(root, false);
        mount.dataset.completed = "1";
        root.dispatchEvent(new CustomEvent("ddb:photo-challenge-passed", { detail: result }));
        if (state.challenge.community_allowed) {
          const community = mount.querySelector("[data-camera-community-submit]");
          community.hidden = false;
          community.dataset.attemptUuid = String(result.attempt_uuid || "");
        }
      } else if (result.status === "review") {
        lockNavigation(root, false);
      } else {
        lockNavigation(root, true, "Maak een nieuwe foto om dit hoofdstuk te voltooien.");
      }
      mount.querySelector("[data-camera-retry]").hidden = result.status === "passed";
      if (queuedId) await removeQueuedUpload(queuedId);
      return true;
    } catch (error) {
      if (!queuedId && (!navigator.onLine || error instanceof TypeError)) {
        try {
          await queueUpload(root, step, file);
          feedback(mount, "Foto veilig in wachtrij", "Zodra je weer online bent, hervatten we de upload automatisch.", "review");
          lockNavigation(root, false);
          return false;
        } catch {
          // Fall through to the normal network error.
        }
      }
      feedback(
        mount,
        error.status === 401 || error.status === 403 ? "Tourtoegang nodig" : "Upload niet gelukt",
        error.message || "Probeer het opnieuw zodra je verbinding hebt.",
        "error"
      );
      mount.querySelector("[data-camera-retry]").hidden = false;
      return false;
    } finally {
      setBusy(mount, false);
    }
  };

  const submitCommunity = async (mount) => {
    const panel = mount.querySelector("[data-camera-community-submit]");
    const uuid = panel?.dataset.attemptUuid || "";
    if (!uuid) return;
    const caption = panel.querySelector("input")?.value || "";
    const action = panel.querySelector("button");
    action.disabled = true;
    try {
      await request(`/photo-attempts/${encodeURIComponent(uuid)}/community`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ caption }),
      });
      panel.replaceChildren();
      const message = document.createElement("p");
      message.textContent = "Ingestuurd voor communitymoderatie.";
      panel.append(message);
    } catch (error) {
      feedback(mount, "Community-inzending mislukt", error.message, "error");
      action.disabled = false;
    }
  };

  const renderCommunityFeed = async (root, mount) => {
    const target = mount.querySelector("[data-camera-community-feed]");
    if (!target) return;
    try {
      const result = await request(`/photo-community?tour_id=${encodeURIComponent(root.dataset.tourId || "0")}&limit=6`);
      const photos = Array.isArray(result.photos) ? result.photos : [];
      if (!photos.length) return;
      target.hidden = false;
      const heading = document.createElement("h4");
      heading.textContent = "Topfoto’s van ontdekkers";
      const grid = document.createElement("div");
      grid.className = "ddb-camera__community-grid";
      photos.forEach((photo) => {
        const card = document.createElement("article");
        const image = document.createElement("img");
        image.src = photo.image_url;
        image.alt = photo.caption || "Communityfoto";
        image.loading = "lazy";
        const copy = document.createElement("p");
        copy.textContent = photo.caption || "";
        const actions = document.createElement("div");
        actions.className = "ddb-camera__community-actions";
        const like = button(`♥ ${photo.likes_count || 0}`, "ddb-camera__button--ghost");
        like.addEventListener("click", async () => {
          await request(`/photo-community/${photo.id}/reaction`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ type: "like" }),
          });
          like.textContent = `♥ ${Number(photo.likes_count || 0) + 1}`;
        });
        const share = button("Delen", "ddb-camera__button--ghost");
        share.addEventListener("click", () => {
          if (navigator.share) navigator.share({ title: "DagjeDenBosch ontdekking", text: photo.caption || "", url: photo.image_url });
          else navigator.clipboard?.writeText(photo.image_url);
        });
        actions.append(like, share);
        card.append(image, copy, actions);
        grid.append(card);
      });
      target.append(heading, grid);
    } catch {
      target.hidden = true;
    }
  };

  const flushQueue = async () => {
    if (!navigator.onLine) return;
    let records = [];
    try {
      records = await queuedUploads();
    } catch {
      return;
    }
    for (const record of records) {
      const root = Array.from(document.querySelectorAll("[data-tour-navigation]"))
        .find((candidate) => Number(candidate.dataset.tourId || 0) === Number(record.tourId));
      const state = root ? roots.get(root) : null;
      const step = state?.steps.find((item) => Number(item.id) === Number(record.stepId));
      const mount = root?.querySelector(`[data-photo-challenge="${record.stepId}"]`);
      if (!root || !state || !step || !mount) continue;
      await upload(root, state, mount, step, record.file, record.id);
    }
  };

  const startCamera = async (state, mount) => {
    const video = mount.querySelector("video");
    if (!video || !navigator.mediaDevices?.getUserMedia) {
      feedback(mount, "Camera niet beschikbaar", "Gebruik ‘Kies foto’ als toegankelijke fallback.", "error");
      return;
    }
    stopCamera(state);
    try {
      state.stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: "environment" }, width: { ideal: 1600 }, height: { ideal: 1200 } },
        audio: false,
      });
      video.srcObject = state.stream;
      await video.play();
      mount.dataset.cameraActive = "1";
      mount.dataset.fullscreen = "1";
      feedback(mount, "Camera gereed", state.challenge.voice_intro?.transcript || "Breng het gevraagde object rustig in beeld.", "success");
    } catch {
      feedback(mount, "Geen cameratoegang", "Geef cameratoestemming of kies een bestaande foto.", "error");
    }
  };

  const capture = async (state, mount) => {
    const video = mount.querySelector("video");
    if (!video?.videoWidth) {
      feedback(mount, "Camera nog niet gereed", "Open eerst de camera.", "error");
      return;
    }
    const scale = Math.min(1, 1600 / video.videoWidth);
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext("2d", { alpha: false }).drawImage(video, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.84));
    if (!blob) {
      feedback(mount, "Foto mislukt", "Maak de foto opnieuw.", "error");
      return;
    }
    stopCamera(state);
    mount.dataset.cameraActive = "0";
    showPreview(state, mount, new File([blob], "discovery-photo.jpg", { type: "image/jpeg" }));
  };

  const createChallenge = (root, step, challenge, state) => {
    state.challenge = challenge;
    const mount = document.createElement("section");
    mount.className = "ddb-camera";
    mount.dataset.photoChallenge = String(step.id);
    mount.setAttribute("aria-labelledby", `ddb-camera-title-${step.id}`);
    mount.innerHTML = `
      <header class="ddb-camera__head">
        <span class="ddb-camera__eyebrow"></span>
        <h3 id="ddb-camera-title-${step.id}"></h3>
        <p data-camera-mission></p>
      </header>
      <div class="ddb-camera__historical" data-camera-historical hidden></div>
      <figure class="ddb-camera__reference" data-camera-reference hidden>
        <img alt="Historisch referentiebeeld">
        <figcaption>Richt je camera en maak dezelfde compositie.</figcaption>
      </figure>
      <ul class="ddb-camera__boss-targets" data-camera-boss-targets hidden></ul>
      <div class="ddb-camera__viewport">
        <video playsinline muted aria-label="Live camera"></video>
        <img data-camera-preview alt="Voorbeeld van je gemaakte foto" hidden>
        <div class="ddb-camera__frame" aria-hidden="true"></div>
        <div class="ddb-camera__progress" data-camera-progress role="progressbar" aria-label="Foto wordt beoordeeld" hidden></div>
      </div>
      <details class="ddb-camera__hints"><summary>Hint gebruiken</summary><ol></ol></details>
      <label class="ddb-camera__consent">
        <input type="checkbox" data-camera-consent>
        <span>Ik geef toestemming om deze foto privé te analyseren. De foto wordt automatisch verwijderd na de bewaartermijn.</span>
      </label>
      <div class="ddb-camera__controls"></div>
      <div class="ddb-camera__feedback" data-camera-feedback role="status" aria-live="polite"></div>
      <div class="ddb-camera__result" data-camera-result hidden></div>
      <div class="ddb-camera__community-submit" data-camera-community-submit hidden>
        <label>Bijschrift <input type="text" maxlength="280" placeholder="Wat heb je ontdekt?"></label>
        <button type="button" class="ddb-camera__button ddb-camera__button--secondary">Deel met de community</button>
        <small>Publicatie gebeurt pas na moderatie.</small>
      </div>
      <section class="ddb-camera__community" data-camera-community-feed hidden></section>`;

    mount.querySelector(".ddb-camera__eyebrow").textContent =
      `${challenge.interaction_type === "then_now" ? `Toen & Nu ${challenge.historical_year || ""}` : "Photo Challenge"} · ${challenge.difficulty || "medium"}`;
    mount.querySelector("h3").textContent = challenge.title || step.title || "Ontdek met je camera";
    mount.querySelector("[data-camera-mission]").textContent = challenge.mission || "";
    const historical = mount.querySelector("[data-camera-historical]");
    if (challenge.historical_context) {
      historical.textContent = challenge.historical_context.replace(/<[^>]+>/g, " ");
      historical.hidden = false;
    }
    if (challenge.interaction_type === "then_now" && challenge.reference_image_url) {
      const reference = mount.querySelector("[data-camera-reference]");
      reference.querySelector("img").src = challenge.reference_image_url;
      reference.hidden = false;
    }
    if (challenge.interaction_type === "boss" && Array.isArray(challenge.boss_targets) && challenge.boss_targets.length) {
      const targets = mount.querySelector("[data-camera-boss-targets]");
      challenge.boss_targets.forEach((target) => {
        const item = document.createElement("li");
        item.textContent = `${target.count || 1}× ${target.label || ""}`;
        targets.append(item);
      });
      targets.hidden = false;
    }
    const hintList = mount.querySelector(".ddb-camera__hints ol");
    (challenge.hints || []).filter(Boolean).forEach((hint) => {
      const item = document.createElement("li");
      item.textContent = hint;
      hintList.append(item);
    });
    if (!hintList.children.length) mount.querySelector(".ddb-camera__hints").hidden = true;
    const personaNames = { bosch: "Jeroen Bosch", frederik_hendrik: "Frederik Hendrik", chef: "Chef", guide: "DagjeDenBosch gids" };
    if (challenge.persona && challenge.persona !== "guide") {
      const persona = document.createElement("p");
      persona.className = "ddb-camera__persona";
      persona.textContent = `${personaNames[challenge.persona] || "Gids"} begeleidt deze opdracht.`;
      mount.querySelector(".ddb-camera__head").append(persona);
    }

    const controls = mount.querySelector(".ddb-camera__controls");
    const open = button("Open camera", "ddb-camera__button--secondary");
    const shutter = button("Maak foto", "ddb-camera__button--primary");
    shutter.dataset.cameraShutter = "";
    const confirm = button("Gebruik deze foto", "ddb-camera__button--primary");
    confirm.dataset.cameraConfirm = "";
    confirm.hidden = true;
    const retake = button("Opnieuw", "ddb-camera__button--secondary");
    retake.dataset.cameraRetake = "";
    retake.hidden = true;
    const retry = button("Nieuwe poging", "ddb-camera__button--secondary");
    retry.dataset.cameraRetry = "";
    retry.hidden = true;
    const close = button("Camera sluiten", "ddb-camera__button--ghost");
    const uploadLabel = document.createElement("label");
    uploadLabel.className = "ddb-camera__button ddb-camera__button--secondary";
    uploadLabel.textContent = "Kies foto";
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/jpeg,image/png,image/webp";
    input.setAttribute("capture", "environment");
    uploadLabel.append(input);
    controls.append(open, shutter, confirm, retake, retry, uploadLabel, close);

    open.addEventListener("click", () => startCamera(state, mount));
    shutter.addEventListener("click", () => capture(state, mount));
    confirm.addEventListener("click", () => upload(root, state, mount, step, state.pendingFile));
    retake.addEventListener("click", () => resetPreview(state, mount));
    retry.addEventListener("click", () => resetPreview(state, mount));
    close.addEventListener("click", () => {
      stopCamera(state);
      mount.dataset.fullscreen = "0";
      document.body.classList.remove("ddb-camera-is-open");
    });
    input.addEventListener("change", () => {
      const file = input.files?.[0];
      if (file) showPreview(state, mount, file);
    });
    open.addEventListener("click", () => document.body.classList.add("ddb-camera-is-open"));
    mount.querySelector("[data-camera-community-submit] button").addEventListener("click", () => submitCommunity(mount));
    renderCommunityFeed(root, mount);
    return mount;
  };

  const sync = async (root) => {
    const state = roots.get(root);
    const step = state.steps[currentIndex(root, state.steps)];
    if (!step || step.type !== "photo_challenge") {
      stopCamera(state);
      root.querySelector("[data-photo-challenge]")?.remove();
      lockNavigation(root, false);
      state.stepId = null;
      return;
    }
    if (state.stepId === String(step.id) && root.querySelector(`[data-photo-challenge="${step.id}"]`)) return;
    state.stepId = String(step.id);
    stopCamera(state);
    root.querySelector("[data-photo-challenge]")?.remove();
    const flow = root.querySelector(".tour-story-flow");
    if (!flow) return;
    try {
      const result = await request(`/tours/${encodeURIComponent(root.dataset.tourId || "")}/chapters/${encodeURIComponent(step.id)}/photo-challenge`);
      flow.prepend(createChallenge(root, step, result.challenge || {}, state));
      lockNavigation(root, true, "Voltooi eerst de Photo Challenge.");
    } catch (error) {
      const fallback = document.createElement("div");
      fallback.className = "ddb-camera__feedback";
      fallback.dataset.variant = "error";
      fallback.setAttribute("role", "alert");
      fallback.textContent = error.message || "Photo Challenge niet beschikbaar.";
      flow.prepend(fallback);
      lockNavigation(root, false);
    }
  };

  const mountRoot = (root) => {
    if (roots.has(root)) return;
    const state = { steps: parseSteps(root), stream: null, stepId: null, syncing: false, pendingFile: null, previewUrl: "", challenge: {} };
    roots.set(root, state);
    new MutationObserver(() => {
      if (state.syncing) return;
      state.syncing = true;
      queueMicrotask(() => {
        state.syncing = false;
        sync(root);
      });
    }).observe(root, { childList: true, subtree: true });
    sync(root);
  };

  const init = () => document.querySelectorAll("[data-tour-navigation]").forEach(mountRoot);
  window.addEventListener("online", flushQueue);
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) return;
    document.querySelectorAll("[data-photo-challenge]").forEach((mount) => {
      const root = mount.closest("[data-tour-navigation]");
      if (root) stopCamera(roots.get(root));
    });
  });
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init, { once: true });
  else init();
  window.setTimeout(flushQueue, 500);
})();

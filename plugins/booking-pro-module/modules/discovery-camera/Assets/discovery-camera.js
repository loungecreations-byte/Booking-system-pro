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

  const ticketSession = () => String(document.querySelector("[data-tour-navigation][data-ticket-session]")?.dataset.ticketSession || "");

  const request = async (path, options = {}) => {
    const session = ticketSession();
    const response = await fetch(`${String(config.restBase || "").replace(/\/$/, "")}${path}`, {
      credentials: "same-origin",
      ...options,
      headers: {
        "X-WP-Nonce": String(config.nonce || ""),
        ...(session ? { "X-DDB-Tour-Session": session } : {}),
        ...(options.headers || {}),
      },
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

  const setStatus = (mount, label, status = "available") => {
    mount.dataset.experienceStatus = status;
    const target = mount.querySelector("[data-camera-status]");
    if (target) target.textContent = label;
  };

  const setSyncMessage = (mount, message, status = "saved") => {
    const target = mount.querySelector("[data-camera-sync]");
    if (!target) return;
    target.dataset.status = status;
    target.textContent = message;
  };

  const clearAnalysisStages = (mount) => {
    if (mount.analysisTimer) window.clearInterval(mount.analysisTimer);
    mount.analysisTimer = null;
  };

  const setBusy = (mount, busy) => {
    clearAnalysisStages(mount);
    mount.dataset.busy = busy ? "1" : "0";
    mount.querySelectorAll("button, input").forEach((control) => { control.disabled = busy; });
    mount.querySelector("[data-camera-progress]")?.toggleAttribute("hidden", !busy);
    const stage = mount.querySelector("[data-camera-analysis-stage]");
    if (!busy) {
      if (stage) stage.hidden = true;
      return;
    }
    const stages = ["Foto veilig verwerken…", "Object zoeken…", "Historische details controleren…", "Compositie beoordelen…", "Ontdekkingen verzamelen…"];
    let index = 0;
    if (stage) {
      stage.hidden = false;
      stage.textContent = stages[index];
    }
    mount.analysisTimer = window.setInterval(() => {
      index = Math.min(index + 1, stages.length - 1);
      if (stage) stage.textContent = stages[index];
    }, 1400);
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

  const renderBossProgress = (mount, progress) => {
    if (!Array.isArray(progress?.targets)) return;
    const list = mount.querySelector("[data-camera-boss-targets]");
    if (!list) return;
    list.replaceChildren();
    progress.targets.forEach((target) => {
      const item = document.createElement("li");
      item.textContent = `${target.found || 0}/${target.required || 1} ${target.label || ""}`;
      item.dataset.completed = target.completed ? "1" : "0";
      list.append(item);
    });
    const found = progress.targets.reduce((total, target) => total + Math.min(Number(target.found || 0), Number(target.required || 1)), 0);
    const required = progress.targets.reduce((total, target) => total + Number(target.required || 1), 0);
    const summary = mount.querySelector("[data-camera-boss-summary]");
    if (summary) summary.textContent = progress.completed
      ? `Meesterproef voltooid · ${required}/${required} ontdekkingen`
      : `${found}/${required} ontdekkingen verzameld`;
    mount.dataset.bossCompleted = progress.completed ? "1" : "0";
    list.hidden = false;
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
    mount.querySelector("[data-camera-open]").hidden = true;
    mount.querySelector("[data-camera-file]").hidden = true;
    setStatus(mount, "Foto controleren", "preview");
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
    mount.querySelector("[data-camera-shutter]").hidden = true;
    mount.querySelector("[data-camera-open]").hidden = false;
    mount.querySelector("[data-camera-file]").hidden = false;
    setStatus(mount, "Beschikbaar", "available");
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
    setStatus(mount, "Foto wordt beoordeeld", "analysing");
    setSyncMessage(mount, "Foto uploaden…", "saving");
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
      localStorage.setItem(`ddb_photo_attempt_${root.dataset.tourId || "0"}_${step.id}`, String(result.attempt_uuid || attempt.attempt_uuid));
      mount.dataset.attemptStatus = String(result.status || "review");
      setSyncMessage(mount, "Foto en voortgang opgeslagen", "saved");
      renderScores(mount, result);
      renderBossProgress(mount, result.boss_progress);
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
        setStatus(mount, "Voltooid", "completed");
        root.dispatchEvent(new CustomEvent("ddb:photo-challenge-passed", { detail: result }));
        const complete = root.querySelector("[data-tour-complete]");
        if (complete && !complete.disabled) complete.click();
        mount.querySelector("[data-camera-next]").hidden = false;
        if (state.challenge.community_allowed) {
          const community = mount.querySelector("[data-camera-community-submit]");
          community.hidden = false;
          community.dataset.attemptUuid = String(result.attempt_uuid || "");
        }
      } else if (result.status === "review") {
        lockNavigation(root, false);
        setStatus(mount, "Controle loopt", "review");
      } else {
        lockNavigation(root, true, "Maak een nieuwe foto om dit hoofdstuk te voltooien.");
        setStatus(mount, "Opnieuw proberen", "retry");
      }
      mount.querySelector("[data-camera-retry]").hidden = result.status === "passed";
      if (queuedId) await removeQueuedUpload(queuedId);
      return true;
    } catch (error) {
      if (!queuedId && (!navigator.onLine || error instanceof TypeError)) {
        try {
          await queueUpload(root, step, file);
          setStatus(mount, "Offline opgeslagen", "offline");
          setSyncMessage(mount, "Wacht op internetverbinding", "offline");
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
      setStatus(mount, "Upload opnieuw proberen", "retry");
      setSyncMessage(mount, "Nog niet opgeslagen", "error");
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

  const restoreAttempt = async (root, step, state, mount) => {
    const uuid = localStorage.getItem(`ddb_photo_attempt_${root.dataset.tourId || "0"}_${step.id}`) || "";
    if (!uuid) return;
    try {
      const result = await request(`/photo-attempts/${encodeURIComponent(uuid)}`);
      mount.dataset.attemptStatus = String(result.status || "");
      renderScores(mount, result);
      renderBossProgress(mount, result.boss_progress);
      if (result.feedback?.message) {
        feedback(
          mount,
          result.feedback.title || "Eerdere poging",
          result.feedback.message,
          result.status === "passed" ? "success" : result.status === "failed" ? "error" : "review",
          result.feedback.coach_tip || ""
        );
      }
      if (result.status === "passed") {
        lockNavigation(root, false);
        mount.dataset.completed = "1";
        setStatus(mount, "Voltooid", "completed");
        setSyncMessage(mount, "Voortgang hersteld", "saved");
        mount.querySelector("[data-camera-next]").hidden = false;
        if (state.challenge.community_allowed) {
          const community = mount.querySelector("[data-camera-community-submit]");
          community.hidden = false;
          community.dataset.attemptUuid = uuid;
        }
      }
    } catch {
      localStorage.removeItem(`ddb_photo_attempt_${root.dataset.tourId || "0"}_${step.id}`);
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
      setSyncMessage(mount, "Offline foto hervatten…", "saving");
      await upload(root, state, mount, step, record.file, record.id);
    }
  };

  const updateNetworkStatus = () => {
    document.querySelectorAll("[data-photo-challenge]").forEach((mount) => {
      if (navigator.onLine) {
        if (mount.dataset.experienceStatus === "offline") setStatus(mount, "Upload hervatten", "available");
        setSyncMessage(mount, "Online · voortgang wordt automatisch opgeslagen", "saved");
      } else {
        setStatus(mount, "Offline beschikbaar", "offline");
        setSyncMessage(mount, "Offline · foto’s blijven veilig op dit apparaat", "offline");
      }
    });
  };

  const startCamera = async (state, mount) => {
    const video = mount.querySelector("video");
    if (!video || !navigator.mediaDevices?.getUserMedia) {
      feedback(mount, "Camera niet beschikbaar", "Gebruik ‘Kies foto’ als toegankelijke fallback.", "error");
      mount.querySelector("[data-camera-permission-help]").hidden = false;
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
      mount.querySelector("[data-camera-open]").hidden = true;
      mount.querySelector("[data-camera-shutter]").hidden = false;
      mount.querySelector("[data-camera-close]").hidden = false;
      setStatus(mount, "Camera actief", "camera");
      feedback(mount, "Camera gereed", state.challenge.voice_intro?.transcript || "Breng het gevraagde object rustig in beeld.", "success");
    } catch (error) {
      feedback(mount, "Geen cameratoegang", "Geef cameratoestemming of kies een bestaande foto.", "error");
      setStatus(mount, "Cameratoegang nodig", "permission");
      mount.querySelector("[data-camera-permission-help]").hidden = false;
      if (error?.name === "NotAllowedError") {
        setSyncMessage(mount, "Camera is geblokkeerd in je browser", "error");
      }
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

  const createChallenge = (root, step, challenge, state, bossProgress = {}) => {
    state.challenge = challenge;
    const mount = document.createElement("section");
    mount.className = "ddb-camera";
    mount.dataset.photoChallenge = String(step.id);
    mount.setAttribute("aria-labelledby", `ddb-camera-title-${step.id}`);
    mount.innerHTML = `
      <header class="ddb-camera__head">
        <div class="ddb-camera__meta">
          <span class="ddb-camera__eyebrow"></span>
          <span class="ddb-camera__status" data-camera-status>Beschikbaar</span>
        </div>
        <h3 id="ddb-camera-title-${step.id}"></h3>
        <p class="ddb-camera__subtitle" data-camera-subtitle></p>
      </header>
      <section class="ddb-camera__mission" aria-label="Jouw opdracht">
        <strong>Jouw opdracht</strong>
        <p data-camera-mission></p>
        <div class="ddb-camera__reward"><span data-camera-difficulty></span><span data-camera-xp></span></div>
      </section>
      <div class="ddb-camera__historical" data-camera-historical hidden></div>
      <figure class="ddb-camera__reference" data-camera-reference hidden>
        <img alt="Historisch referentiebeeld">
        <figcaption>Richt je camera en maak dezelfde compositie.</figcaption>
      </figure>
      <section class="ddb-camera__boss" data-camera-boss hidden>
        <strong data-camera-boss-summary>Meesterproef starten</strong>
        <ul class="ddb-camera__boss-targets" data-camera-boss-targets></ul>
      </section>
      <div class="ddb-camera__viewport">
        <video playsinline muted aria-label="Live camera"></video>
        <img data-camera-preview alt="Voorbeeld van je gemaakte foto" hidden>
        <img class="ddb-camera__then-now-overlay" data-camera-overlay alt="" hidden>
        <div class="ddb-camera__frame" aria-hidden="true"></div>
        <div class="ddb-camera__progress" data-camera-progress role="progressbar" aria-label="Foto wordt beoordeeld" hidden></div>
        <p class="ddb-camera__analysis-stage" data-camera-analysis-stage aria-live="polite" hidden></p>
      </div>
      <label class="ddb-camera__overlay-control" data-camera-overlay-control hidden>
        <span>Historische overlay</span>
        <input type="range" min="25" max="75" step="25" value="50">
      </label>
      <section class="ddb-camera__hints" data-camera-hints hidden>
        <button type="button" class="ddb-camera__hint-button">Toon een hint</button>
        <p data-camera-hint-output aria-live="polite"></p>
      </section>
      <aside class="ddb-camera__permission-help" data-camera-permission-help hidden>
        <strong>Camera geblokkeerd?</strong>
        <p>Open de website-instellingen van je browser, sta Camera toe en probeer opnieuw. Je kunt ook veilig een bestaande foto kiezen.</p>
      </aside>
      <label class="ddb-camera__consent">
        <input type="checkbox" data-camera-consent>
        <span>Ik geef toestemming om deze foto privé te analyseren. De foto wordt automatisch verwijderd na de bewaartermijn.</span>
      </label>
      <div class="ddb-camera__controls"></div>
      <p class="ddb-camera__sync" data-camera-sync data-status="saved" role="status">Klaar om te starten</p>
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
    mount.querySelector("[data-camera-subtitle]").textContent = challenge.subtitle || "Kijk goed, maak je foto en ontgrendel het verhaal.";
    mount.querySelector("[data-camera-mission]").textContent = challenge.mission || "";
    mount.querySelector("[data-camera-difficulty]").textContent = `Niveau: ${challenge.difficulty || "medium"}`;
    mount.querySelector("[data-camera-xp]").textContent = `+${Number(challenge.xp_reward || 0)} XP`;
    const historical = mount.querySelector("[data-camera-historical]");
    if (challenge.historical_context) {
      historical.textContent = challenge.historical_context.replace(/<[^>]+>/g, " ");
      historical.hidden = false;
    }
    if (challenge.interaction_type === "then_now" && challenge.reference_image_url) {
      const reference = mount.querySelector("[data-camera-reference]");
      reference.querySelector("img").src = challenge.reference_image_url;
      reference.hidden = false;
      const overlay = mount.querySelector("[data-camera-overlay]");
      const overlayControl = mount.querySelector("[data-camera-overlay-control]");
      overlay.src = challenge.reference_image_url;
      overlay.hidden = false;
      overlayControl.hidden = false;
      mount.dataset.overlayLevel = "50";
      overlayControl.querySelector("input").addEventListener("input", (event) => {
        mount.dataset.overlayLevel = String(event.target.value);
      });
    }
    if (challenge.interaction_type === "boss" && Array.isArray(challenge.boss_targets) && challenge.boss_targets.length) {
      const targets = mount.querySelector("[data-camera-boss-targets]");
      mount.querySelector("[data-camera-boss]").hidden = false;
      challenge.boss_targets.forEach((target) => {
        const item = document.createElement("li");
        item.textContent = `${target.count || 1}× ${target.label || ""}`;
        targets.append(item);
      });
      targets.hidden = false;
      renderBossProgress(mount, bossProgress);
    }
    const hints = (challenge.hints || []).filter(Boolean);
    if (hints.length) {
      const hintsPanel = mount.querySelector("[data-camera-hints]");
      const hintOutput = mount.querySelector("[data-camera-hint-output]");
      const hintButton = mount.querySelector(".ddb-camera__hint-button");
      let hintIndex = 0;
      hintsPanel.hidden = false;
      hintButton.addEventListener("click", () => {
        hintOutput.textContent = `Hint ${hintIndex + 1}: ${hints[hintIndex]}`;
        hintIndex = Math.min(hintIndex + 1, hints.length - 1);
        hintButton.textContent = hintIndex >= hints.length - 1 ? "Toon laatste hint" : "Volgende hint";
      });
    }
    const personaNames = { bosch: "Jeroen Bosch", frederik_hendrik: "Frederik Hendrik", chef: "Chef", guide: "DagjeDenBosch gids" };
    if (challenge.persona && challenge.persona !== "guide") {
      const persona = document.createElement("p");
      persona.className = "ddb-camera__persona";
      persona.textContent = `${personaNames[challenge.persona] || "Gids"} begeleidt deze opdracht.`;
      mount.querySelector(".ddb-camera__head").append(persona);
    }

    const controls = mount.querySelector(".ddb-camera__controls");
    const open = button("Open camera", "ddb-camera__button--primary");
    open.dataset.cameraOpen = "";
    const shutter = button("Maak foto", "ddb-camera__button--primary");
    shutter.dataset.cameraShutter = "";
    shutter.hidden = true;
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
    close.dataset.cameraClose = "";
    close.hidden = true;
    const next = button("Verder naar volgende stop", "ddb-camera__button--primary");
    next.dataset.cameraNext = "";
    next.hidden = true;
    const uploadLabel = document.createElement("label");
    uploadLabel.className = "ddb-camera__button ddb-camera__button--secondary";
    uploadLabel.dataset.cameraFile = "";
    uploadLabel.textContent = "Kies foto";
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/jpeg,image/png,image/webp";
    input.setAttribute("capture", "environment");
    uploadLabel.append(input);
    controls.append(open, shutter, confirm, retake, retry, uploadLabel, close, next);

    open.addEventListener("click", () => startCamera(state, mount));
    shutter.addEventListener("click", () => capture(state, mount));
    confirm.addEventListener("click", () => upload(root, state, mount, step, state.pendingFile));
    retake.addEventListener("click", () => resetPreview(state, mount));
    retry.addEventListener("click", () => resetPreview(state, mount));
    close.addEventListener("click", () => {
      stopCamera(state);
      mount.dataset.fullscreen = "0";
      mount.dataset.cameraActive = "0";
      shutter.hidden = true;
      close.hidden = true;
      open.hidden = false;
      uploadLabel.hidden = false;
      setStatus(mount, "Beschikbaar", "available");
      document.body.classList.remove("ddb-camera-is-open");
    });
    next.addEventListener("click", () => {
      const nextControl = root.querySelector("[data-tour-mobile-next], [data-tour-next]");
      if (nextControl && !nextControl.disabled) nextControl.click();
    });
    input.addEventListener("change", () => {
      const file = input.files?.[0];
      if (file) showPreview(state, mount, file);
    });
    open.addEventListener("click", () => document.body.classList.add("ddb-camera-is-open"));
    mount.querySelector("[data-camera-community-submit] button").addEventListener("click", () => submitCommunity(mount));
    const onboardingKey = `ddb_camera_onboarding_${root.dataset.tourId || "0"}`;
    if (!localStorage.getItem(onboardingKey)) {
      const onboarding = document.createElement("aside");
      onboarding.className = "ddb-camera__onboarding";
      onboarding.setAttribute("role", "dialog");
      onboarding.setAttribute("aria-modal", "true");
      onboarding.setAttribute("aria-label", "Zo werkt de Discovery Camera");
      onboarding.innerHTML = `
        <div>
          <span class="ddb-camera__eyebrow">Welkom ontdekker</span>
          <h3>Zo werkt de Discovery Camera</h3>
          <ol>
            <li>Lees wat je moet vinden.</li>
            <li>Open de camera of kies een foto.</li>
            <li>Ontgrendel het verhaal en je beloning.</li>
          </ol>
          <p>Foto’s blijven privé, tenzij je ze later zelf met de community deelt.</p>
          <button type="button" class="ddb-camera__button ddb-camera__button--primary">Start opdracht</button>
        </div>`;
      onboarding.querySelector("button").addEventListener("click", () => {
        localStorage.setItem(onboardingKey, "1");
        onboarding.remove();
        open.focus();
      });
      mount.append(onboarding);
    }
    renderCommunityFeed(root, mount);
    restoreAttempt(root, step, state, mount);
    if (navigator.onLine) {
      setSyncMessage(mount, "Online · voortgang wordt automatisch opgeslagen", "saved");
    } else {
      setStatus(mount, "Offline beschikbaar", "offline");
      setSyncMessage(mount, "Offline · foto’s blijven veilig op dit apparaat", "offline");
    }
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
      flow.prepend(createChallenge(root, step, result.challenge || {}, state, result.boss_progress || {}));
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
  window.addEventListener("online", () => {
    updateNetworkStatus();
    flushQueue();
  });
  window.addEventListener("offline", updateNetworkStatus);
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) return;
    document.querySelectorAll("[data-photo-challenge]").forEach((mount) => {
      const root = mount.closest("[data-tour-navigation]");
      if (root) stopCamera(roots.get(root));
    });
  });
  const observeTourRoots = () => {
    init();
    if (!document.body) return;
    new MutationObserver(init).observe(document.body, { childList: true, subtree: true });
  };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", observeTourRoots, { once: true });
  else observeTourRoots();
  window.setTimeout(flushQueue, 500);
})();

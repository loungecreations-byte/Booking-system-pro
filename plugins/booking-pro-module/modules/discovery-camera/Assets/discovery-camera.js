(() => {
  "use strict";

  const config = window.ddbDiscoveryCamera || {};
  if (!config.featureEnabled) return;

  const stateByRoot = new WeakMap();

  const parseSteps = (root) => {
    try {
      const value = JSON.parse(root.dataset.tourSteps || "[]");
      return Array.isArray(value) ? value : [];
    } catch {
      return [];
    }
  };

  const currentIndex = (root, steps) => {
    const hashValue = Number.parseInt(location.hash.replace("#step-", ""), 10);
    if (Number.isFinite(hashValue) && hashValue >= 0 && hashValue < steps.length) return hashValue;
    const saved = Number.parseInt(localStorage.getItem(`sbdp_tour_step_${root.dataset.tourId || "0"}`) || "0", 10);
    return Number.isFinite(saved) && saved >= 0 && saved < steps.length ? saved : 0;
  };

  const request = async (path, options = {}) => {
    const response = await fetch(`${String(config.restBase || "").replace(/\/$/, "")}${path}`, {
      credentials: "same-origin",
      ...options,
      headers: {
        "X-WP-Nonce": String(config.nonce || ""),
        ...(options.headers || {}),
      },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.message || "De cameraverbinding is tijdelijk niet beschikbaar.");
    return payload;
  };

  const stopCamera = (state) => {
    if (state?.stream) state.stream.getTracks().forEach((track) => track.stop());
    if (state) state.stream = null;
  };

  const button = (label, className = "") => {
    const element = document.createElement("button");
    element.type = "button";
    element.className = `ddb-camera__button ${className}`.trim();
    element.textContent = label;
    return element;
  };

  const renderFeedback = (mount, title, message, variant = "info") => {
    const feedback = mount.querySelector("[data-camera-feedback]");
    if (!feedback) return;
    feedback.dataset.variant = variant;
    feedback.replaceChildren();
    const heading = document.createElement("strong");
    heading.textContent = title;
    const copy = document.createElement("span");
    copy.textContent = message;
    feedback.append(heading, copy);
  };

  const upload = async (root, mount, step, file) => {
    if (!file || file.size > Number(config.maxUploadBytes || 8388608)) {
      renderFeedback(mount, "Foto te groot", "Gebruik een foto van maximaal 8 MB.", "error");
      return;
    }

    const controls = mount.querySelectorAll("button, input");
    controls.forEach((control) => { control.disabled = true; });
    renderFeedback(mount, "Foto wordt beveiligd", "Uploaden en voorbereiden voor staging-review…", "loading");

    try {
      const attempt = await request("/photo-attempts", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
        },
        body: JSON.stringify({
          tour_id: Number(root.dataset.tourId || 0),
          step_id: Number(step.id || 0),
        }),
      });
      const data = new FormData();
      data.append("photo", file, file.name || "discovery-photo.jpg");
      const result = await request(`/photo-attempts/${encodeURIComponent(attempt.attempt_uuid)}/complete-upload`, {
        method: "POST",
        body: data,
      });
      renderFeedback(
        mount,
        result.feedback?.title || "Foto ontvangen",
        result.feedback?.message || "Deze foto wacht op menselijke review.",
        "review"
      );
      mount.dataset.attemptStatus = String(result.status || "review");
    } catch (error) {
      renderFeedback(mount, "Upload niet gelukt", error.message || "Probeer het opnieuw.", "error");
    } finally {
      controls.forEach((control) => { control.disabled = false; });
    }
  };

  const startCamera = async (state, mount) => {
    const video = mount.querySelector("video");
    if (!video || !navigator.mediaDevices?.getUserMedia) {
      renderFeedback(mount, "Camera niet beschikbaar", "Gebruik ‘Kies foto’ als toegankelijke fallback.", "error");
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
      renderFeedback(mount, "Camera gereed", "Breng het gevraagde object rustig in beeld.", "success");
    } catch {
      renderFeedback(mount, "Geen cameratoegang", "Geef cameratoestemming of kies een bestaande foto.", "error");
    }
  };

  const capture = async (root, state, mount, step) => {
    const video = mount.querySelector("video");
    if (!video || !video.videoWidth) {
      renderFeedback(mount, "Camera nog niet gereed", "Open eerst de camera.", "error");
      return;
    }
    const scale = Math.min(1, 1600 / video.videoWidth);
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext("2d", { alpha: false }).drawImage(video, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.84));
    if (!blob) {
      renderFeedback(mount, "Foto mislukt", "Maak de foto opnieuw.", "error");
      return;
    }
    stopCamera(state);
    mount.dataset.cameraActive = "0";
    await upload(root, mount, step, new File([blob], "discovery-photo.jpg", { type: "image/jpeg" }));
  };

  const createChallenge = (root, step, challenge, state) => {
    const mount = document.createElement("section");
    mount.className = "ddb-camera";
    mount.dataset.photoChallenge = String(step.id);
    mount.setAttribute("aria-labelledby", `ddb-camera-title-${step.id}`);

    const head = document.createElement("header");
    head.className = "ddb-camera__head";
    const eyebrow = document.createElement("span");
    eyebrow.className = "ddb-camera__eyebrow";
    eyebrow.textContent = `Photo Challenge · ${challenge.difficulty || "medium"}`;
    const title = document.createElement("h3");
    title.id = `ddb-camera-title-${step.id}`;
    title.textContent = challenge.title || step.title || "Ontdek met je camera";
    const mission = document.createElement("p");
    mission.textContent = challenge.mission || "";
    head.append(eyebrow, title, mission);

    const viewport = document.createElement("div");
    viewport.className = "ddb-camera__viewport";
    const video = document.createElement("video");
    video.playsInline = true;
    video.muted = true;
    video.setAttribute("aria-label", "Live camera");
    const frame = document.createElement("div");
    frame.className = "ddb-camera__frame";
    frame.setAttribute("aria-hidden", "true");
    viewport.append(video, frame);

    const hints = document.createElement("details");
    hints.className = "ddb-camera__hints";
    const summary = document.createElement("summary");
    summary.textContent = "Hints bekijken";
    const hintList = document.createElement("ol");
    (challenge.hints || []).filter(Boolean).forEach((hint) => {
      const item = document.createElement("li");
      item.textContent = hint;
      hintList.append(item);
    });
    hints.append(summary, hintList);

    const controls = document.createElement("div");
    controls.className = "ddb-camera__controls";
    const open = button("Open camera", "ddb-camera__button--secondary");
    const shutter = button("Maak foto", "ddb-camera__button--primary");
    const uploadLabel = document.createElement("label");
    uploadLabel.className = "ddb-camera__button ddb-camera__button--secondary";
    uploadLabel.textContent = "Kies foto";
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/jpeg,image/png,image/webp";
    input.setAttribute("capture", "environment");
    uploadLabel.append(input);
    controls.append(open, shutter, uploadLabel);

    const feedback = document.createElement("div");
    feedback.className = "ddb-camera__feedback";
    feedback.dataset.cameraFeedback = "";
    feedback.setAttribute("role", "status");
    feedback.setAttribute("aria-live", "polite");

    open.addEventListener("click", () => startCamera(state, mount));
    shutter.addEventListener("click", () => capture(root, state, mount, step));
    input.addEventListener("change", () => upload(root, mount, step, input.files?.[0]));

    mount.append(head, viewport, hints, controls, feedback);
    return mount;
  };

  const sync = async (root) => {
    const state = stateByRoot.get(root);
    const steps = state.steps;
    const index = currentIndex(root, steps);
    const step = steps[index];
    if (!step || step.type !== "photo_challenge") {
      stopCamera(state);
      root.querySelector("[data-photo-challenge]")?.remove();
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
      const challenge = createChallenge(root, step, result.challenge || {}, state);
      flow.prepend(challenge);
      root.querySelectorAll("[data-tour-start-route], [data-tour-complete], [data-tour-mobile-next]").forEach((control) => {
        control.disabled = true;
        control.setAttribute("aria-disabled", "true");
        control.title = "Deze stagingchallenge wacht op menselijke review.";
      });
    } catch (error) {
      const fallback = document.createElement("div");
      fallback.className = "ddb-camera__feedback";
      fallback.dataset.variant = "error";
      fallback.setAttribute("role", "alert");
      fallback.textContent = error.message || "Photo Challenge niet beschikbaar.";
      flow.prepend(fallback);
    }
  };

  const mountRoot = (root) => {
    if (stateByRoot.has(root)) return;
    const state = { steps: parseSteps(root), stream: null, stepId: null, syncing: false };
    stateByRoot.set(root, state);
    const observer = new MutationObserver(() => {
      if (state.syncing) return;
      state.syncing = true;
      queueMicrotask(() => {
        state.syncing = false;
        sync(root);
      });
    });
    observer.observe(root, { childList: true, subtree: true });
    sync(root);
  };

  const init = () => document.querySelectorAll("[data-tour-navigation]").forEach(mountRoot);
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init, { once: true });
  else init();
})();

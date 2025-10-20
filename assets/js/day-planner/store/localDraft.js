const STORAGE_PREFIX = "sbdp_day_planner_plan";

export function createDraftKey(planId) {
  if (planId && Number.isInteger(planId)) {
    return `${STORAGE_PREFIX}_${planId}`;
  }

  return `${STORAGE_PREFIX}_draft`;
}

export function loadDraft(key) {
  if (!hasStorage()) {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) {
      return null;
    }

    return JSON.parse(raw);
  } catch (error) {
    console.warn("Failed to load planner draft", error);
    return null;
  }
}

export function saveDraft(key, payload) {
  if (!hasStorage()) {
    return;
  }

  try {
    const serialised = JSON.stringify({
      ...payload,
      updatedAt: Date.now(),
    });

    window.localStorage.setItem(key, serialised);
  } catch (error) {
    console.warn("Failed to persist planner draft", error);
  }
}

export function clearDraft(key) {
  if (!hasStorage()) {
    return;
  }

  try {
    window.localStorage.removeItem(key);
  } catch (error) {
    console.warn("Failed to clear planner draft", error);
  }
}

function hasStorage() {
  return typeof window !== "undefined" && typeof window.localStorage !== "undefined";
}

export default {
  createDraftKey,
  loadDraft,
  saveDraft,
  clearDraft,
};

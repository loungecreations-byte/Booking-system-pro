import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useReducer,
  useRef,
} from "react";
import PropTypes from "prop-types";

import { createPlannerApi } from "../api/client";
import { clearDraft, createDraftKey, loadDraft, saveDraft } from "./localDraft";

const PlannerStoreContext = createContext(null);

const ACTIONS = {
  LOAD_PLAN_START: "LOAD_PLAN_START",
  LOAD_PLAN_SUCCESS: "LOAD_PLAN_SUCCESS",
  LOAD_PLAN_FAILURE: "LOAD_PLAN_FAILURE",
  UPDATE_PLAN_LOCAL: "UPDATE_PLAN_LOCAL",
  PLAN_SAVE_START: "PLAN_SAVE_START",
  PLAN_SAVE_SUCCESS: "PLAN_SAVE_SUCCESS",
  PLAN_SAVE_FAILURE: "PLAN_SAVE_FAILURE",
  SET_FILTERS: "SET_FILTERS",
  LOAD_ACTIVITIES_START: "LOAD_ACTIVITIES_START",
  LOAD_ACTIVITIES_SUCCESS: "LOAD_ACTIVITIES_SUCCESS",
  LOAD_ACTIVITIES_FAILURE: "LOAD_ACTIVITIES_FAILURE",
  SET_SHARE_DATA: "SET_SHARE_DATA",
  SET_AI_SUGGESTIONS: "SET_AI_SUGGESTIONS",
  SET_PLAN: "SET_PLAN",
  SET_CONFLICTS: "SET_CONFLICTS",
  SET_NOTICE: "SET_NOTICE",
  SET_ERROR: "SET_ERROR",
};

const initialStatus = {
  loadingPlan: false,
  savingPlan: false,
  loadingActivities: false,
  sharing: false,
  aiBusy: false,
};

function createInitialState({ settings, draft }) {
  const plan = (draft && draft.plan) || createEmptyPlan(settings);
  const filters = (draft && draft.filters) || {};

  return {
    plan,
    filters,
    activities: [],
    activitiesMeta: {},
    autosaveEnabled: settings.autosave !== false,
    status: { ...initialStatus },
    error: null,
    lastSavedAt: draft && draft.updatedAt ? draft.updatedAt : null,
    unsavedChanges: !plan.id,
    share: {
      url: "",
      key: null,
      permissions: {},
    },
    aiSuggestions: null,
    notice: null,
  };
}

function plannerReducer(state, action) {
  switch (action.type) {
    case ACTIONS.LOAD_PLAN_START:
      return {
        ...state,
        status: {
          ...state.status,
          loadingPlan: true,
        },
        error: null,
      };
    case ACTIONS.LOAD_PLAN_SUCCESS:
      return {
        ...state,
        plan: action.payload.plan,
        status: {
          ...state.status,
          loadingPlan: false,
        },
        error: null,
        unsavedChanges: false,
        lastSavedAt: Date.now(),
      };
    case ACTIONS.LOAD_PLAN_FAILURE:
      return {
        ...state,
        status: {
          ...state.status,
          loadingPlan: false,
        },
        error: action.payload.error,
      };
    case ACTIONS.UPDATE_PLAN_LOCAL:
      return {
        ...state,
        plan: action.payload.plan,
        unsavedChanges: true,
      };
    case ACTIONS.SET_PLAN:
      return {
        ...state,
        plan: action.payload.plan,
        unsavedChanges:
          action.payload.unsavedChanges !== undefined
            ? action.payload.unsavedChanges
            : state.unsavedChanges,
      };
    case ACTIONS.PLAN_SAVE_START:
      return {
        ...state,
        status: {
          ...state.status,
          savingPlan: true,
        },
        error: null,
      };
    case ACTIONS.PLAN_SAVE_SUCCESS:
      return {
        ...state,
        plan: action.payload.plan,
        status: {
          ...state.status,
          savingPlan: false,
        },
        unsavedChanges: false,
        lastSavedAt: action.payload.savedAt,
      };
    case ACTIONS.PLAN_SAVE_FAILURE:
      return {
        ...state,
        status: {
          ...state.status,
          savingPlan: false,
        },
        error: action.payload.error,
      };
    case ACTIONS.SET_FILTERS:
      return {
        ...state,
        filters: action.payload.filters,
      };
    case ACTIONS.LOAD_ACTIVITIES_START:
      return {
        ...state,
        status: {
          ...state.status,
          loadingActivities: true,
        },
        error: null,
      };
    case ACTIONS.LOAD_ACTIVITIES_SUCCESS:
      return {
        ...state,
        activities: action.payload.activities,
        activitiesMeta: action.payload.meta || {},
        status: {
          ...state.status,
          loadingActivities: false,
        },
      };
    case ACTIONS.LOAD_ACTIVITIES_FAILURE:
      return {
        ...state,
        status: {
          ...state.status,
          loadingActivities: false,
        },
        error: action.payload.error,
      };
    case ACTIONS.SET_SHARE_DATA:
      return {
        ...state,
        share: {
          url: action.payload.url,
          key: action.payload.key,
          permissions: action.payload.permissions || {},
        },
      };
    case ACTIONS.SET_AI_SUGGESTIONS:
      return {
        ...state,
        aiSuggestions: action.payload.suggestions,
        status: {
          ...state.status,
          aiBusy: action.payload.aiBusy || false,
        },
      };
    case ACTIONS.SET_CONFLICTS:
      return {
        ...state,
        plan: {
          ...state.plan,
          conflicts: action.payload.conflicts,
        },
      };
    case ACTIONS.SET_ERROR:
      return {
        ...state,
        error: action.payload.error,
      };
    case ACTIONS.SET_NOTICE:
      return {
        ...state,
        notice: action.payload.notice ?? null,
      };
    default:
      return state;
  }
}

function createEmptyPlan(settings) {
  const dayCount = Math.max(1, parseInt(settings.default_day_count, 10) || 1);
  const today = new Date();

  const days = Array.from({ length: dayCount }, (_, index) => {
    const dayDate = new Date(today);
    dayDate.setDate(today.getDate() + index);

    return {
      date: dayDate.toISOString().slice(0, 10),
      slots: [],
    };
  });

  return {
    id: null,
    title: "Nieuwe dagplanning",
    notes: "",
    days,
    participants: [],
    totals: {},
    conflicts: [],
  };
}

export function PlannerProvider({ bootConfig, children }) {
  const restBase = bootConfig.restBase || "";
  const nonce = bootConfig.nonce || "";
  const settings = bootConfig.config || {};
  const initialDraftKey = createDraftKey(bootConfig.planId);
  const draft = loadDraft(initialDraftKey);

  const [state, dispatch] = useReducer(
    plannerReducer,
    createInitialState({ settings, draft })
  );

  const api = useMemo(
    () => createPlannerApi({ restBase, nonce }),
    [restBase, nonce]
  );

  const draftKeyRef = useRef(initialDraftKey);
  const settingsRef = useRef(settings);
  const stateRef = useRef(state);
  const autosaveTimer = useRef(null);
  const conflictTimer = useRef(null);

  useEffect(() => {
    stateRef.current = state;
  }, [state]);

  useEffect(() => {
    settingsRef.current = settings;
  }, [settings]);

  useEffect(() => {
    if (bootConfig.planId) {
      draftKeyRef.current = createDraftKey(bootConfig.planId);
    }
  }, [bootConfig.planId]);

  const persistDraft = useCallback(
    (nextPlan, nextFilters) => {
      saveDraft(draftKeyRef.current, {
        plan: nextPlan,
        filters: nextFilters !== undefined ? nextFilters : stateRef.current.filters,
      });
    },
    []
  );

  const loadPlanFromApi = useCallback(
    async (planId) => {
      if (!planId) {
        return;
      }

      dispatch({ type: ACTIONS.LOAD_PLAN_START });
      try {
        const payload = await api.getPlan(planId);
        if (payload && payload.plan) {
          const { plan } = payload;
          dispatch({
            type: ACTIONS.LOAD_PLAN_SUCCESS,
            payload: { plan },
          });
          draftKeyRef.current = createDraftKey(plan.id);
          persistDraft(plan);
        } else {
          throw new Error("Plan response malformed.");
        }
      } catch (error) {
        dispatch({
          type: ACTIONS.LOAD_PLAN_FAILURE,
          payload: { error: normaliseError(error) },
        });
      }
    },
    [api, persistDraft]
  );

  useEffect(() => {
    if (bootConfig.planId) {
      loadPlanFromApi(bootConfig.planId);
    }
  }, [bootConfig.planId, loadPlanFromApi]);

  const loadActivities = useCallback(
    async (filtersOverride) => {
      const filters = filtersOverride || stateRef.current.filters || {};
      dispatch({ type: ACTIONS.LOAD_ACTIVITIES_START });
      try {
        const payload = await api.listActivities(filters);
        const activities = payload && payload.items ? payload.items : [];
        dispatch({
          type: ACTIONS.LOAD_ACTIVITIES_SUCCESS,
          payload: {
            activities,
            meta: payload && payload.meta ? payload.meta : {},
          },
        });
      } catch (error) {
        dispatch({
          type: ACTIONS.LOAD_ACTIVITIES_FAILURE,
          payload: { error: normaliseError(error) },
        });
      }
    },
    [api]
  );

  useEffect(() => {
    loadActivities(stateRef.current.filters);
  }, [loadActivities]);

  const updatePlanLocal = useCallback(
    (updater) => {
      const currentPlan = stateRef.current.plan || createEmptyPlan(settingsRef.current || {});
      const nextPlan =
        typeof updater === "function" ? updater(currentPlan) : { ...currentPlan, ...updater };

      dispatch({
        type: ACTIONS.UPDATE_PLAN_LOCAL,
        payload: { plan: nextPlan },
      });

      persistDraft(nextPlan);
    },
    [persistDraft, settings]
  );

  const addParticipant = useCallback(
    (participant) => {
      if (!participant) {
        return;
      }

      const safe = {
        name: (participant.name ?? "").toString().trim(),
        email: (participant.email ?? "").toString().trim(),
        role: (participant.role ?? "guest").toString().trim() || "guest",
      };

      if (safe.name === "" && safe.email === "") {
        return;
      }

      updatePlanLocal((prev) => {
        const participants = Array.isArray(prev.participants) ? prev.participants.slice() : [];
        participants.push(safe);

        return {
          ...prev,
          participants,
        };
      });
    },
    [updatePlanLocal]
  );

  const updateParticipant = useCallback(
    (index, changes) => {
      if (typeof index !== "number" || index < 0 || !changes) {
        return;
      }

      updatePlanLocal((prev) => {
        const participants = Array.isArray(prev.participants) ? prev.participants.slice() : [];
        if (!participants[index]) {
          return prev;
        }

        const next = {
          ...participants[index],
          ...changes,
        };

        next.name = (next.name ?? "").toString().trim();
        next.email = (next.email ?? "").toString().trim();
        next.role = (next.role ?? "guest").toString().trim() || "guest";

        if (next.name === "" && next.email === "") {
          participants.splice(index, 1);
        } else {
          participants[index] = next;
        }

        return {
          ...prev,
          participants,
        };
      });
    },
    [updatePlanLocal]
  );

  const removeParticipant = useCallback(
    (index) => {
      if (typeof index !== "number" || index < 0) {
        return;
      }

      updatePlanLocal((prev) => {
        const participants = Array.isArray(prev.participants) ? prev.participants.slice() : [];

        if (!participants[index]) {
          return prev;
        }

        participants.splice(index, 1);

        return {
          ...prev,
          participants,
        };
      });
    },
    [updatePlanLocal]
  );

  const moveSlot = useCallback(
    ({ fromDayIndex, slotIndex, toDayIndex, insertIndex, newStart, durationMinutes }) => {
      if (
        typeof fromDayIndex !== "number" ||
        typeof slotIndex !== "number" ||
        typeof toDayIndex !== "number"
      ) {
        return;
      }

      updatePlanLocal((prev) => {
        const days = Array.isArray(prev.days) ? prev.days.map((day) => ({ ...day })) : [];
        const fromDay = days[fromDayIndex];
        const toDay = days[toDayIndex];

        if (!fromDay || !toDay || !Array.isArray(fromDay.slots)) {
          return prev;
        }

        const sourceSlots = fromDay.slots.slice();
        if (slotIndex < 0 || slotIndex >= sourceSlots.length) {
          return prev;
        }

        const [movingSlot] = sourceSlots.splice(slotIndex, 1);

        if (!movingSlot) {
          return prev;
        }

        const targetSlots = Array.isArray(toDay.slots) ? toDay.slots.slice() : [];
        let targetIndex =
          typeof insertIndex === "number" && insertIndex >= 0 && insertIndex <= targetSlots.length
            ? insertIndex
            : targetSlots.length;

        if (fromDayIndex === toDayIndex && targetIndex > slotIndex) {
          targetIndex -= 1;
        }

        if (targetIndex < 0) {
          targetIndex = 0;
        }
        if (targetIndex > targetSlots.length) {
          targetIndex = targetSlots.length;
        }

        const shouldOverrideStart = typeof newStart !== "undefined";
        const durationOverride =
          typeof durationMinutes === "number" && durationMinutes > 0 ? durationMinutes : null;

        const updatedSlot = {
          ...movingSlot,
        };

        if (shouldOverrideStart) {
          const normalisedStart =
            newStart === null || newStart === ""
              ? ""
              : normaliseTimeValue(newStart) || "";
          const slotDuration =
            durationOverride || calculateSlotDurationMinutes(movingSlot, 60);

          updatedSlot.start = normalisedStart;
          updatedSlot.end = normalisedStart ? incrementTime(normalisedStart, slotDuration) : "";
          updatedSlot.duration_minutes = slotDuration;
        } else if (durationOverride) {
          const effectiveStart = normaliseTimeValue(movingSlot.start);
          updatedSlot.end = effectiveStart ? incrementTime(effectiveStart, durationOverride) : "";
          updatedSlot.duration_minutes = durationOverride;
        }

        targetSlots.splice(targetIndex, 0, updatedSlot);

        const updatedSourceSlots = sortSlotsByStart(sourceSlots);
        const updatedTargetSlots = sortSlotsByStart(targetSlots);

        days[fromDayIndex] = {
          ...fromDay,
          slots: updatedSourceSlots,
        };

        days[toDayIndex] = {
          ...toDay,
          slots: updatedTargetSlots,
        };

        return {
          ...prev,
          days,
        };
      });
    },
    [updatePlanLocal]
  );

  const addActivityToDay = useCallback(
    (dayIndex, activity, insertIndex, slotOptions = {}) => {
      if (
        typeof dayIndex !== "number" ||
        dayIndex < 0 ||
        !activity
      ) {
        return;
      }

      updatePlanLocal((prev) => {
        const days = Array.isArray(prev.days) ? prev.days.map((day) => ({ ...day })) : [];
        const day = days[dayIndex];
        if (!day) {
          return prev;
        }

        const slots = Array.isArray(day.slots) ? day.slots.slice() : [];
        const targetIndex =
          typeof insertIndex === "number" && insertIndex >= 0 && insertIndex <= slots.length
            ? insertIndex
            : slots.length;
        const previousSlot =
          targetIndex > 0 && targetIndex - 1 < slots.length ? slots[targetIndex - 1] : slots[slots.length - 1];

        const hasCustomStart = Object.prototype.hasOwnProperty.call(slotOptions, "start");
        const activityDuration = resolveActivityDuration(activity, 60);
        const customDuration =
          typeof slotOptions.durationMinutes === "number" && slotOptions.durationMinutes > 0
            ? slotOptions.durationMinutes
            : null;

        const previousEnd = previousSlot ? normaliseTimeValue(previousSlot.end) : "";
        const defaultStartCandidate = normaliseTimeValue(activity.default_start_time);
        const productId = Number.parseInt(activity.product_id ?? activity.id, 10) || Number.parseInt(activity.id, 10) || 0;
        const resourceId = Number.parseInt(activity.resource_id ?? 0, 10) || 0;
        const defaultStart = defaultStartCandidate || previousEnd || "09:00";
        const slotStartValue = hasCustomStart
          ? slotOptions.start === null || slotOptions.start === ""
            ? ""
            : normaliseTimeValue(slotOptions.start) || ""
          : defaultStart;
        const effectiveDuration = customDuration || activityDuration;
        const slotEndValue = slotStartValue ? incrementTime(slotStartValue, effectiveDuration) : "";

        const participantsSeed = [activity.people, activity.default_people].reduce((current, candidate) => {
          if (current > 0) {
            return current;
          }

          const parsed = Number.parseInt(candidate, 10);
          return !Number.isNaN(parsed) && parsed > 0 ? parsed : current;
        }, 0);

        const initialParticipants = participantsSeed > 0 ? participantsSeed : 1;

        slots.splice(targetIndex, 0, {
          id: `slot-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
          product_id: productId,
          activity_id: activity.id,
          title: activity.title,
          start: slotStartValue,
          end: slotEndValue,
          people: initialParticipants,
          source: "manual",
          duration_minutes: effectiveDuration,
          resource_id: resourceId,
          price_pp: typeof activity.price_pp === "number" ? activity.price_pp : 0,
          currency: activity.currency || "EUR",
          resources: Array.isArray(activity.resources) ? activity.resources : [],
        });

        day.slots = sortSlotsByStart(slots);

        return {
          ...prev,
          days,
        };
      });
    },
    [updatePlanLocal]
  );

  const updateSlot = useCallback(
    (dayIndex, slotIndex, changes) => {
      if (
        typeof dayIndex !== "number" ||
        typeof slotIndex !== "number" ||
        !changes ||
        typeof changes !== "object"
      ) {
        return;
      }

      updatePlanLocal((prev) => {
        const days = Array.isArray(prev.days) ? prev.days.map((day) => ({ ...day })) : [];
        const day = days[dayIndex];
        if (!day || !Array.isArray(day.slots) || !day.slots[slotIndex]) {
          return prev;
        }

        const slots = day.slots.slice();
        const originalSlot = slots[slotIndex];
        const updatedSlot = {
          ...originalSlot,
          ...changes,
        };

        if (Object.prototype.hasOwnProperty.call(changes, "start")) {
          updatedSlot.start = normaliseTimeValue(updatedSlot.start);
        }

        if (Object.prototype.hasOwnProperty.call(changes, "end")) {
          updatedSlot.end = normaliseTimeValue(updatedSlot.end);
        }

        if (Object.prototype.hasOwnProperty.call(changes, "duration_minutes")) {
          const parsedDuration = Number.parseInt(changes.duration_minutes, 10);
          if (!Number.isNaN(parsedDuration) && parsedDuration > 0) {
            updatedSlot.duration_minutes = parsedDuration;
            if (updatedSlot.start) {
              updatedSlot.end = incrementTime(updatedSlot.start, parsedDuration);
            }
          }
        }

        if (
          (Object.prototype.hasOwnProperty.call(changes, "start") ||
            Object.prototype.hasOwnProperty.call(changes, "end")) &&
          updatedSlot.start &&
          updatedSlot.end
        ) {
          const recalculated = calculateSlotDurationMinutes(updatedSlot, updatedSlot.duration_minutes || 60);
          if (recalculated > 0) {
            updatedSlot.duration_minutes = recalculated;
          }
        } else if (updatedSlot.start && updatedSlot.duration_minutes) {
          updatedSlot.end = incrementTime(updatedSlot.start, updatedSlot.duration_minutes);
        }

        slots[slotIndex] = updatedSlot;

        days[dayIndex] = {
          ...day,
          slots: sortSlotsByStart(slots),
        };

        return {
          ...prev,
          days,
        };
      });
    },
    [updatePlanLocal]
  );

  const removeSlot = useCallback(
    (dayIndex, slotIndex) => {
      if (typeof dayIndex !== "number" || typeof slotIndex !== "number") {
        return;
      }

      updatePlanLocal((prev) => {
        const days = Array.isArray(prev.days) ? prev.days.map((day) => ({ ...day })) : [];
        const day = days[dayIndex];
        if (!day || !Array.isArray(day.slots) || !day.slots[slotIndex]) {
          return prev;
        }

        const slots = day.slots.slice();
        slots.splice(slotIndex, 1);

        days[dayIndex] = {
          ...day,
          slots,
        };

        return {
          ...prev,
          days,
        };
      });
    },
    [updatePlanLocal]
  );

  const detectConflicts = useCallback(
    async () => {
      const currentPlan = stateRef.current.plan;
      if (!currentPlan) {
        return;
      }

      try {
        const payload = await api.detectConflicts(currentPlan);
        if (payload && Array.isArray(payload.conflicts)) {
          dispatch({
            type: ACTIONS.SET_CONFLICTS,
            payload: { conflicts: payload.conflicts },
          });
        }
      } catch (error) {
        dispatch({
          type: ACTIONS.SET_ERROR,
          payload: { error: normaliseError(error) },
        });
      }
    },
    [api]
  );

  const setFilters = useCallback(
    (filters) => {
      dispatch({
        type: ACTIONS.SET_FILTERS,
        payload: { filters },
      });
      persistDraft(stateRef.current.plan, filters);
      loadActivities(filters);
    },
    [loadActivities, persistDraft]
  );

  const savePlan = useCallback(
    async (options = {}) => {
      const current = stateRef.current;
      const { plan } = current;
      const { immediate = false } = options;

      if (!plan) {
        return;
      }

      if (!immediate && !current.unsavedChanges) {
        return;
      }

      dispatch({ type: ACTIONS.PLAN_SAVE_START });

      try {
        const payload = plan.id
          ? await api.updatePlan(plan.id, plan)
          : await api.createPlan(plan);

        if (!payload || !payload.plan) {
          throw new Error("Plan save failed.");
        }

        const savedPlan = payload.plan;
        const savedAt = Date.now();

        if (plan.id === null && savedPlan.id) {
          const nextKey = createDraftKey(savedPlan.id);
          clearDraft(draftKeyRef.current);
          draftKeyRef.current = nextKey;
        }

        dispatch({
          type: ACTIONS.PLAN_SAVE_SUCCESS,
          payload: { plan: savedPlan, savedAt },
        });
        persistDraft(savedPlan);
      } catch (error) {
        dispatch({
          type: ACTIONS.PLAN_SAVE_FAILURE,
          payload: { error: normaliseError(error) },
        });
      }
    },
    [api, persistDraft]
  );

  useEffect(() => {
    if (!state.autosaveEnabled || !state.unsavedChanges) {
      return;
    }

    if (autosaveTimer.current) {
      clearTimeout(autosaveTimer.current);
    }

    autosaveTimer.current = setTimeout(() => {
      savePlan();
    }, 1500);

    return () => {
      if (autosaveTimer.current) {
        clearTimeout(autosaveTimer.current);
      }
    };
  }, [state.autosaveEnabled, state.unsavedChanges, state.plan, savePlan]);

  useEffect(() => {
    if (conflictTimer.current) {
      clearTimeout(conflictTimer.current);
      conflictTimer.current = null;
    }

    if (
      state.status.loadingPlan ||
      !state.plan ||
      !Array.isArray(state.plan.days) ||
      state.plan.days.length === 0
    ) {
      return undefined;
    }

    conflictTimer.current = setTimeout(() => {
      detectConflicts();
    }, 1200);

    return () => {
      if (conflictTimer.current) {
        clearTimeout(conflictTimer.current);
        conflictTimer.current = null;
      }
    };
  }, [state.status.loadingPlan, state.plan?.days, detectConflicts]);




  const sharePlan = useCallback(
    async () => {
      const currentPlan = stateRef.current.plan;
      if (!currentPlan || !currentPlan.id) {
        return null;
      }

      dispatch({
        type: ACTIONS.SET_ERROR,
        payload: { error: null },
      });
      dispatch({
        type: ACTIONS.SET_NOTICE,
        payload: { notice: null },
      });

      try {
        const payload = await api.sharePlan(currentPlan.id);
        const shareData = {
          url: payload.share_url || "",
          key: payload.shared_key || null,
          permissions: payload.permissions || {},
        };
        if (payload.plan) {
          dispatch({
            type: ACTIONS.SET_PLAN,
            payload: { plan: payload.plan, unsavedChanges: false },
          });
          persistDraft(payload.plan);
        }
        dispatch({
          type: ACTIONS.SET_SHARE_DATA,
          payload: shareData,
        });
        dispatch({
          type: ACTIONS.SET_NOTICE,
          payload: {
            notice: {
              type: "success",
              message: shareData.url
                ? "Deelbare link voor de dagplanner is aangemaakt."
                : "Dagplanner deeltaken zijn afgerond.",
            },
          },
        });
        return shareData;
      } catch (error) {
        dispatch({
          type: ACTIONS.SET_ERROR,
          payload: { error: normaliseError(error) },
        });
        return null;
      }
    },
    [api, persistDraft]
  );

  const requestAiSuggestions = useCallback(
    async (preferences) => {
      dispatch({
        type: ACTIONS.SET_AI_SUGGESTIONS,
        payload: { suggestions: stateRef.current.aiSuggestions, aiBusy: true },
      });

      try {
        const payload = await api.suggestActivities(preferences);
        dispatch({
          type: ACTIONS.SET_AI_SUGGESTIONS,
          payload: { suggestions: payload, aiBusy: false },
        });
      } catch (error) {
        dispatch({
          type: ACTIONS.SET_AI_SUGGESTIONS,
          payload: { suggestions: null, aiBusy: false },
        });
        dispatch({
          type: ACTIONS.SET_ERROR,
          payload: { error: normaliseError(error) },
        });
      }
    },
    [api]
  );

  const queueBooking = useCallback(
    async () => {
      const currentPlan = stateRef.current.plan;
      if (!currentPlan || !currentPlan.id) {
        return null;
      }

      dispatch({
        type: ACTIONS.SET_ERROR,
        payload: { error: null },
      });
      dispatch({
        type: ACTIONS.SET_NOTICE,
        payload: { notice: null },
      });

      try {
        const response = await api.queueBooking(currentPlan.id);

        const message =
          (response && response.message) || "Batchboeking is aangemeld en wordt verwerkt.";

        dispatch({
          type: ACTIONS.SET_NOTICE,
          payload: {
            notice: {
              type: "success",
              message,
            },
          },
        });

        return response;
      } catch (error) {
        dispatch({
          type: ACTIONS.SET_ERROR,
          payload: { error: normaliseError(error) },
        });
        return null;
      }
    },
    [api]
  );

  const exportPlanAction = useCallback(
    async (type) => {
      const currentPlan = stateRef.current.plan;
      if (!currentPlan || !currentPlan.id) {
        return null;
      }

      dispatch({
        type: ACTIONS.SET_ERROR,
        payload: { error: null },
      });
      dispatch({
        type: ACTIONS.SET_NOTICE,
        payload: { notice: null },
      });

      try {
        const response = await api.exportPlan(currentPlan.id, type);
        const message =
          (response && response.message) ||
          (type === "ics"
            ? "Agenda-export wordt voorbereid."
            : "PDF-export wordt voorbereid.");

        dispatch({
          type: ACTIONS.SET_NOTICE,
          payload: {
            notice: {
              type: "success",
              message,
            },
          },
        });

        return response;
      } catch (error) {
        dispatch({
          type: ACTIONS.SET_ERROR,
          payload: { error: normaliseError(error) },
        });
        return null;
      }
    },
    [api]
  );

  const clearAiSuggestions = useCallback(() => {
    dispatch({
      type: ACTIONS.SET_AI_SUGGESTIONS,
      payload: { suggestions: null, aiBusy: false },
    });
  }, []);

  const clearNotice = useCallback(() => {
    dispatch({
      type: ACTIONS.SET_NOTICE,
      payload: { notice: null },
    });
  }, []);

  const actions = useMemo(
    () => ({
      updatePlan: updatePlanLocal,
      savePlan: () => savePlan({ immediate: true }),
      setFilters,
      reloadActivities: loadActivities,
      sharePlan,
      requestAiSuggestions,
      detectConflicts,
      queueBooking,
      exportPlan: exportPlanAction,
      clearAiSuggestions,
      addActivityToDay,
      moveSlot,
      updateSlot,
      removeSlot,
      addParticipant,
      updateParticipant,
      removeParticipant,
      clearNotice,
    }),
    [
      updatePlanLocal,
      savePlan,
      setFilters,
      loadActivities,
      sharePlan,
      requestAiSuggestions,
      detectConflicts,
      queueBooking,
      exportPlanAction,
      clearAiSuggestions,
      addActivityToDay,
      moveSlot,
      updateSlot,
      removeSlot,
      addParticipant,
      updateParticipant,
      removeParticipant,
      clearNotice,
    ]
  );

  const value = useMemo(
    () => ({
      state: {
        ...state,
        busy: state.status.loadingPlan || state.status.savingPlan,
      },
      actions,
    }),
    [actions, state]
  );

  return (
    <PlannerStoreContext.Provider value={value}>
      {children}
    </PlannerStoreContext.Provider>
  );
}

PlannerProvider.propTypes = {
  bootConfig: PropTypes.shape({
    restBase: PropTypes.string,
    nonce: PropTypes.string,
    config: PropTypes.object,
    planId: PropTypes.oneOfType([PropTypes.number, PropTypes.string]),
  }).isRequired,
  children: PropTypes.node.isRequired,
};

export function usePlannerStore() {
  const context = useContext(PlannerStoreContext);
  if (!context) {
    throw new Error("usePlannerStore must be used inside a PlannerProvider");
  }

  return context;
}

export function usePlannerState() {
  return usePlannerStore().state;
}

export function usePlannerActions() {
  return usePlannerStore().actions;
}

function normaliseError(error) {
  if (!error) {
    return null;
  }

  if (error instanceof Error) {
    return error.message;
  }

  if (typeof error === "string") {
    return error;
  }

  if (typeof error.message === "string") {
    return error.message;
  }

  return "Er is een onbekende fout opgetreden.";
}

function normaliseTimeValue(value) {
  if (!value) {
    return "";
  }

  if (typeof value === "string") {
    const match = value.match(/(\d{2}):(\d{2})/);
    if (match) {
      return `${match[1]}:${match[2]}`;
    }
  }

  const date = new Date(value);
  if (!Number.isNaN(date.getTime())) {
    const hours = Math.max(0, Math.min(23, date.getHours()))
      .toString()
      .padStart(2, "0");
    const minutes = Math.max(0, Math.min(59, date.getMinutes()))
      .toString()
      .padStart(2, "0");
    return `${hours}:${minutes}`;
  }

  return "";
}

function timeStringToMinutes(value) {
  const normalised = normaliseTimeValue(value);
  if (!normalised) {
    return null;
  }

  const [hours, minutes] = normalised.split(":").map((part) => Number.parseInt(part, 10));
  if (Number.isNaN(hours) || Number.isNaN(minutes)) {
    return null;
  }

  return hours * 60 + minutes;
}

function calculateSlotDurationMinutes(slot, fallback = 60) {
  if (!slot) {
    return fallback;
  }

  if (typeof slot.duration_minutes === "number" && slot.duration_minutes > 0) {
    return slot.duration_minutes;
  }

  const start = timeStringToMinutes(slot.start);
  const end = timeStringToMinutes(slot.end);
  if (start === null || end === null || end <= start) {
    return fallback;
  }

  return end - start;
}

function resolveActivityDuration(activity, fallback = 60) {
  if (!activity) {
    return fallback;
  }

  const rawDuration = activity.duration_minutes || activity.duration;
  const parsed = Number.parseInt(rawDuration, 10);
  if (!Number.isNaN(parsed) && parsed > 0) {
    return parsed;
  }

  return fallback;
}

function compareSlotsByStart(a, b) {
  const aValue = timeStringToMinutes(a && a.start);
  const bValue = timeStringToMinutes(b && b.start);

  const normalisedA = aValue === null ? Number.POSITIVE_INFINITY : aValue;
  const normalisedB = bValue === null ? Number.POSITIVE_INFINITY : bValue;

  if (!Number.isFinite(normalisedA) && !Number.isFinite(normalisedB)) {
    return 0;
  }

  if (!Number.isFinite(normalisedA)) {
    return 1;
  }

  if (!Number.isFinite(normalisedB)) {
    return -1;
  }

  return normalisedA - normalisedB;
}

function sortSlotsByStart(slots) {
  if (!Array.isArray(slots)) {
    return [];
  }

  return slots.slice().sort(compareSlotsByStart);
}

function incrementTime(time, minutes) {
  const [hour = 0, minute = 0] = (time || "00:00")
    .split(":")
    .map((value) => Number.parseInt(value, 10) || 0);

  const total = hour * 60 + minute + minutes;
  const normalised = ((total % (24 * 60)) + 24 * 60) % (24 * 60);
  const nextHour = Math.floor(normalised / 60)
    .toString()
    .padStart(2, "0");
  const nextMinute = (normalised % 60).toString().padStart(2, "0");

  return `${nextHour}:${nextMinute}`;
}

export default PlannerProvider;











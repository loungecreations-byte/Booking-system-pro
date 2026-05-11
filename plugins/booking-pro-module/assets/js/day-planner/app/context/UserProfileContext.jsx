import React, { createContext, useCallback, useContext, useMemo, useReducer } from "react";
import PropTypes from "prop-types";

const STORAGE_KEY = "sbdp_user_profile_v1";

const UserProfileContext = createContext(null);

function sanitizeActivity(activity) {
  if (!activity || typeof activity !== "object") {
    return null;
  }

  const id = Number.parseInt(activity.id ?? activity.productId ?? activity.product_id, 10);
  if (!Number.isFinite(id) || id <= 0) {
    return null;
  }

  return {
    id,
    title: String(activity.title || activity.name || "Activiteit"),
    location: String(activity.location || "Den Bosch"),
    image: String(activity.image || ""),
    category: String(activity.category || ""),
    hiddenGem: Boolean(activity.hiddenGem),
    priceLabel: String(activity.priceLabel || ""),
  };
}

function readInitialState() {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return { favorites: [], plannedItems: [], bookings: [] };
  }

  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return { favorites: [], plannedItems: [], bookings: [] };
    }
    const parsed = JSON.parse(raw);
    return {
      favorites: Array.isArray(parsed?.favorites) ? parsed.favorites.map(sanitizeActivity).filter(Boolean) : [],
      plannedItems: Array.isArray(parsed?.plannedItems) ? parsed.plannedItems : [],
      bookings: Array.isArray(parsed?.bookings) ? parsed.bookings : [],
    };
  } catch (error) {
    return { favorites: [], plannedItems: [], bookings: [] };
  }
}

function persistState(state) {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return;
  }
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch (error) {
    // noop
  }
}

function reducer(state, action) {
  switch (action.type) {
    case "TOGGLE_FAVORITE": {
      const nextActivity = sanitizeActivity(action.payload?.activity);
      if (!nextActivity) {
        return state;
      }
      const exists = state.favorites.some((item) => item.id === nextActivity.id);
      const favorites = exists
        ? state.favorites.filter((item) => item.id !== nextActivity.id)
        : [nextActivity, ...state.favorites];
      const nextState = { ...state, favorites };
      persistState(nextState);
      return nextState;
    }
    case "SET_PLANNED_ITEMS": {
      const plannedItems = Array.isArray(action.payload?.plannedItems) ? action.payload.plannedItems : [];
      const nextState = { ...state, plannedItems };
      persistState(nextState);
      return nextState;
    }
    case "ADD_BOOKING": {
      const nextActivity = sanitizeActivity(action.payload?.activity);
      if (!nextActivity) {
        return state;
      }
      const booking = {
        id: `booking-${nextActivity.id}-${Date.now()}`,
        activityId: nextActivity.id,
        title: nextActivity.title,
        location: nextActivity.location,
        image: nextActivity.image,
        reservedAt: new Date().toISOString(),
        status: "Actief",
      };
      const bookings = [booking, ...state.bookings];
      const nextState = { ...state, bookings };
      persistState(nextState);
      return nextState;
    }
    case "REMOVE_BOOKING": {
      const id = String(action.payload?.id || "");
      const bookings = state.bookings.filter((booking) => booking.id !== id);
      const nextState = { ...state, bookings };
      persistState(nextState);
      return nextState;
    }
    default:
      return state;
  }
}

export function UserProfileProvider({ children }) {
  const [state, dispatch] = useReducer(reducer, undefined, readInitialState);

  const toggleFavorite = useCallback((activity) => {
    dispatch({ type: "TOGGLE_FAVORITE", payload: { activity } });
  }, []);

  const setPlannedItems = useCallback((plannedItems) => {
    dispatch({ type: "SET_PLANNED_ITEMS", payload: { plannedItems } });
  }, []);

  const addBooking = useCallback((activity) => {
    dispatch({ type: "ADD_BOOKING", payload: { activity } });
  }, []);

  const removeBooking = useCallback((id) => {
    dispatch({ type: "REMOVE_BOOKING", payload: { id } });
  }, []);

  const isFavorite = useCallback(
    (activityId) => state.favorites.some((item) => item.id === Number.parseInt(activityId, 10)),
    [state.favorites]
  );

  const value = useMemo(
    () => ({
      state,
      actions: {
        toggleFavorite,
        setPlannedItems,
        addBooking,
        removeBooking,
        isFavorite,
      },
    }),
    [state, toggleFavorite, setPlannedItems, addBooking, removeBooking, isFavorite]
  );

  return <UserProfileContext.Provider value={value}>{children}</UserProfileContext.Provider>;
}

UserProfileProvider.propTypes = {
  children: PropTypes.node.isRequired,
};

export function useUserProfile() {
  const context = useContext(UserProfileContext);
  if (!context) {
    throw new Error("useUserProfile moet binnen UserProfileProvider gebruikt worden.");
  }
  return context;
}

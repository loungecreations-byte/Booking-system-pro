import React, { useCallback, useEffect, useMemo, useState } from "react";
import { __, sprintf } from "@wordpress/i18n";

import BookingList from "./BookingList";
import BookingStatsBar from "./BookingStatsBar";
import BookingDetailsPanel from "./BookingDetailsPanel";
import QuickActions from "./QuickActions";
import AddBookingModal from "./AddBookingModal";
import CalendarView from "./CalendarView";
import RescheduleModal from "./RescheduleModal";
import PresetQuickSwitch from "./PresetQuickSwitch";
import HealthIndicator from "./HealthIndicator";
import { BookingsBoardApi } from "../../../bookingsboard";

const DEFAULT_FILTERS = {
  search: "",
  status: [],
  date_from: "",
  date_to: "",
};

const DEFAULT_STATUS_COLORS = {
  paid: "#16a34a",
  pending: "#eab308",
  cancelled: "#ef4444",
  completed: "#3b82f6",
};

function readViewPreference(storageKey) {
  if (typeof window === "undefined") {
    return { mode: "list", calendarView: "day" };
  }

  try {
    const stored = window.localStorage.getItem(storageKey);
    if (!stored) {
      return { mode: "list", calendarView: "day" };
    }

    if (stored.startsWith("calendar:")) {
      const [, calendarMode] = stored.split(":");
      return {
        mode: "calendar",
        calendarView: calendarMode === "week" ? "week" : "day",
      };
    }

    if (stored === "calendar") {
      return { mode: "calendar", calendarView: "day" };
    }

    return { mode: "list", calendarView: "day" };
  } catch (error) {
    return { mode: "list", calendarView: "day" };
  }
}

function normalizeDate(date) {
  const value = new Date(date);
  value.setHours(0, 0, 0, 0);
  return value;
}

function formatSlotLabel(date, time) {
  if (!date) {
    return "";
  }

  return `${date} ${time || ""}`.trim();
}

function BookingBoardApp({ config }) {
  const restBase = config.restBase || "";
  const bookingApiBase = config.bookingApiBase || "";
  const nonce = config.nonce || "";
  const storageKey = config.storage_key || "sbdp_booking_board_view_mode";
  const defaultFilters = useMemo(() => {
    const base = { ...DEFAULT_FILTERS };
    if (!config || typeof config !== "object") {
      return base;
    }

    const overrides = config.defaultFilters || config.default_filters;
    if (overrides && typeof overrides === "object") {
      if (typeof overrides.search === "string") {
        base.search = overrides.search;
      }

      if (Array.isArray(overrides.status)) {
        base.status = overrides.status.map((value) => String(value).toLowerCase()).filter(Boolean);
      }

      if (typeof overrides.date_from === "string") {
        base.date_from = overrides.date_from;
      }

      if (typeof overrides.date_to === "string") {
        base.date_to = overrides.date_to;
      }
    }

    return base;
  }, [config]);
  const rawStatusLabels = useMemo(() => {
    const normalized = {};

    if (config && typeof config === "object") {
      const source = config.statusLabels || config.status_labels;
      if (source && typeof source === "object") {
        Object.entries(source).forEach(([status, label]) => {
          if (status) {
            normalized[String(status)] = String(label || status);
          }
        });
      }
    }

    return normalized;
  }, [config]);

  const initialViewPreference = useMemo(() => readViewPreference(storageKey), [storageKey]);
  const [filters, setFilters] = useState(defaultFilters);
  const [bookings, setBookings] = useState([]);
  const [stats, setStats] = useState(null);
  const [metrics, setMetrics] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [viewMode, setViewMode] = useState(initialViewPreference.mode);
  const [calendarView, setCalendarView] = useState(initialViewPreference.calendarView);
  const [calendarDate, setCalendarDate] = useState(() => normalizeDate(new Date()));
  const [selectedBooking, setSelectedBooking] = useState(null);
  const [isModalOpen, setModalOpen] = useState(false);
  const [invoicingBookingId, setInvoicingBookingId] = useState(null);
  const [downloadingBookingId, setDownloadingBookingId] = useState(null);
  const [rescheduleState, setRescheduleState] = useState({
    open: false,
    booking: null,
    slot: null,
  });
  const [undoState, setUndoState] = useState(null);
  const [personalPresets, setPersonalPresets] = useState([]);
  const [sharedPresets, setSharedPresets] = useState([]);
  const [canManageSharedPresets, setCanManageSharedPresets] = useState(false);
  const [presetsLoading, setPresetsLoading] = useState(false);
  const [presetSaving, setPresetSaving] = useState(false);
  const [presetDeletingId, setPresetDeletingId] = useState("");
  const [defaultSharedPresetId, setDefaultSharedPresetId] = useState(null);
  const [hasAppliedDefaultPreset, setHasAppliedDefaultPreset] = useState(false);

  const bookingApi = useMemo(
    () => new BookingsBoardApi({ baseUrl: bookingApiBase, boardBase: restBase, nonce }),
    [bookingApiBase, restBase, nonce]
  );

  const [serviceStatus, setServiceStatus] = useState({ status: "ok", details: [] });
  const healthEndpoints = useMemo(() => {
    if (!restBase) {
      return [];
    }

    const endpoints = [
      {
        key: "list",
        label: __("Boekingsoverzicht", "sbdp"),
        url: `${restBase}/bookings`,
      },
      {
        key: "stats",
        label: __("Statistieken", "sbdp"),
        url: `${restBase}/stats`,
      },
    ];

    if (restBase.includes("booking-board")) {
      const calendarUrl = restBase.replace("booking-board", "bookings/calendar");
      endpoints.push({
        key: "calendar",
        label: __("Kalender", "sbdp"),
        url: calendarUrl,
      });
    }

    return endpoints;
  }, [restBase]);

  const statusOptions = useMemo(
    () => bookingApi.getStatusOptions(rawStatusLabels),
    [bookingApi, rawStatusLabels]
  );

  const statusColors = useMemo(() => {
    const map = { ...DEFAULT_STATUS_COLORS };
    statusOptions.forEach((option) => {
      if (!map[option.value]) {
        map[option.value] = map[option.value] || "";
      }
    });

    return map;
  }, [statusOptions]);

  const columns = useMemo(
    () => [
      { id: "booking_id", label: __("Booking #", "sbdp") },
      { id: "product", label: __("Product", "sbdp") },
      { id: "customer", label: __("Customer", "sbdp") },
      { id: "from", label: __("From", "sbdp") },
      { id: "to", label: __("To", "sbdp") },
      { id: "duration", label: __("Duration", "sbdp") },
      { id: "people", label: __("People", "sbdp") },
      { id: "status", label: __("Status", "sbdp") },
      { id: "price", label: __("Price", "sbdp") },
    ],
    []
  );

  const loadBookings = useCallback(() => {
    setLoading(true);
    setError("");

    return bookingApi
      .listBookings(filters)
      .then((data) => {
        setBookings(data.items || []);
        setStats(data.stats || null);
        setMetrics(data.metrics || null);
      })
      .catch((requestError) => {
        setError(requestError.message);
      })
      .finally(() => setLoading(false));
  }, [bookingApi, filters]);

  const loadPresets = useCallback(() => {
    if (!restBase) {
      setPersonalPresets([]);
      setSharedPresets([]);
      setCanManageSharedPresets(false);
      setDefaultSharedPresetId(null);
      setHasAppliedDefaultPreset(false);
      return Promise.resolve([]);
    }

    setPresetsLoading(true);

    return bookingApi
      .getPresets()
      .then((data) => {
        const personal = Array.isArray(data.presets)
          ? data.presets
          : Array.isArray(data.personal_presets)
            ? data.personal_presets
            : [];
        const shared = Array.isArray(data.shared_presets) ? data.shared_presets : [];
        const defaultId =
          typeof data.default_shared_preset_id === "string" && data.default_shared_preset_id !== ""
            ? data.default_shared_preset_id
            : null;

        setPersonalPresets(personal);
        setSharedPresets(shared);
        setCanManageSharedPresets(Boolean(data.can_manage_shared));
        setDefaultSharedPresetId((previous) => {
          if (defaultId === null && previous !== null) {
            setHasAppliedDefaultPreset(false);
          } else if (defaultId !== null && defaultId !== previous) {
            setHasAppliedDefaultPreset(false);
          }

          return defaultId;
        });

        return data;
      })
      .catch((requestError) => {
        setError(requestError.message);
        throw requestError;
      })
      .finally(() => setPresetsLoading(false));
  }, [bookingApi, restBase]);

  const lookupCustomers = useCallback(
    (term) => bookingApi.searchCustomers(term).catch(() => []),
    [bookingApi]
  );

  useEffect(() => {
    loadBookings();
  }, [loadBookings]);

  useEffect(() => {
    loadPresets().catch(() => {});
  }, [loadPresets]);

  useEffect(() => {
    if (healthEndpoints.length === 0) {
      return undefined;
    }

    let cancelled = false;

    const headers = {};
    if (nonce) {
      headers["X-WP-Nonce"] = nonce;
    }

    const runHealthCheck = () => {
      Promise.all(
        healthEndpoints.map(async (endpoint) => {
          try {
            const response = await fetch(endpoint.url, {
              method: "GET",
              credentials: "same-origin",
              headers,
            });

            if (!response.ok) {
              return {
                ...endpoint,
                ok: false,
                status: response.status,
                message: response.statusText,
              };
            }

            return {
              ...endpoint,
              ok: true,
              status: response.status,
            };
          } catch (error) {
            return {
              ...endpoint,
              ok: false,
              status: 0,
              message: error?.message || "Network error",
            };
          }
        })
      ).then((results) => {
        if (cancelled) {
          return;
        }

        const failing = results.filter((item) => !item.ok);

        let status = "ok";
        if (failing.length > 0 && failing.length < results.length) {
          status = "degraded";
        } else if (failing.length === results.length) {
          status = "down";
        }

        setServiceStatus({
          status,
          details: failing.map((item) => ({
            label: item.label,
            status: item.status,
            message: item.message,
          })),
        });
      });
    };

    runHealthCheck();
    const interval = window.setInterval(runHealthCheck, 60000);

    return () => {
      cancelled = true;
      window.clearInterval(interval);
    };
  }, [healthEndpoints, nonce]);

  useEffect(() => {
    if (hasAppliedDefaultPreset) {
      return;
    }

    if (!defaultSharedPresetId) {
      return;
    }

    const preset = sharedPresets.find((item) => item.id === defaultSharedPresetId);
    if (!preset || !preset.filters) {
      return;
    }

    const normalized = { ...defaultFilters, ...preset.filters };
    setFilters(normalized);
    setHasAppliedDefaultPreset(true);
  }, [defaultSharedPresetId, sharedPresets, defaultFilters, hasAppliedDefaultPreset]);

  const handleFiltersChange = (next) => {
    setFilters(next);
    setHasAppliedDefaultPreset(true);
  };

  const handleViewModeChange = (mode) => {
    setViewMode(mode);
  };

  const handleCalendarViewChange = (mode) => {
    setCalendarView(mode);
  };

  const handlePresetApply = (preset) => {
    if (!preset || !preset.filters) {
      return;
    }

    const normalized = { ...defaultFilters, ...preset.filters };
    setFilters(normalized);
    setHasAppliedDefaultPreset(true);
    setError("");
    const scopeLabel =
      preset.scope === "shared" ? __("team", "sbdp") : __("personal", "sbdp");
    setNotice(sprintf(__('Preset "%1$s" (%2$s) applied.', "sbdp"), preset.name, scopeLabel));
  };

  const handlePresetSave = (presetName, options = {}) => {
    setPresetSaving(true);
    setError("");

    const scope = options.scope === "shared" ? "shared" : "personal";
    const presetId =
      typeof options.presetId === "string" && options.presetId !== "" ? options.presetId : null;
    const targetFilters =
      options.filters && typeof options.filters === "object" ? options.filters : filters;
    const setDefaultFlag = scope === "shared" && Boolean(options.setDefault);

    return bookingApi
      .savePreset({
        name: presetName,
        filters: targetFilters,
        scope,
        preset_id: presetId,
        default: setDefaultFlag,
      })
      .then((response) => {
        if (response && (Array.isArray(response.presets) || Array.isArray(response.personal_presets))) {
          const personal = Array.isArray(response.presets)
            ? response.presets
            : Array.isArray(response.personal_presets)
              ? response.personal_presets
              : [];
          const shared = Array.isArray(response.shared_presets) ? response.shared_presets : [];
          setPersonalPresets(personal);
          setSharedPresets(shared);
          setCanManageSharedPresets(Boolean(response.can_manage_shared));
          const defaultId =
            typeof response.default_shared_preset_id === "string" && response.default_shared_preset_id !== ""
              ? response.default_shared_preset_id
              : null;
          setDefaultSharedPresetId((previous) => {
            if (defaultId === null && previous !== null) {
              setHasAppliedDefaultPreset(false);
            } else if (defaultId !== null && defaultId !== previous) {
              setHasAppliedDefaultPreset(false);
            }

            return defaultId;
          });
          setNotice(__("Preset opgeslagen.", "sbdp"));
          return response;
        }

        return loadPresets().then((data) => {
          setNotice(__("Preset opgeslagen.", "sbdp"));
          return data ?? response;
        });
      })
      .catch((requestError) => {
        setError(requestError.message);
        throw requestError;
      })
      .finally(() => {
        setPresetSaving(false);
      });
  };

  const handlePresetDelete = (presetId, scope = "personal") => {
    if (!presetId) {
      return Promise.resolve();
    }

    setPresetDeletingId(presetId);
    setError("");

    return bookingApi
      .deletePreset(presetId, scope)
      .then((response) => {
        if (response && (Array.isArray(response.presets) || Array.isArray(response.personal_presets))) {
          const personal = Array.isArray(response.presets)
            ? response.presets
            : Array.isArray(response.personal_presets)
              ? response.personal_presets
              : [];
          const shared = Array.isArray(response.shared_presets) ? response.shared_presets : [];
          setPersonalPresets(personal);
          setSharedPresets(shared);
          setCanManageSharedPresets(Boolean(response.can_manage_shared));
          const defaultId =
            typeof response.default_shared_preset_id === "string" && response.default_shared_preset_id !== ""
              ? response.default_shared_preset_id
              : null;
          setDefaultSharedPresetId((previous) => {
            if (defaultId === null && previous !== null) {
              setHasAppliedDefaultPreset(false);
            } else if (defaultId !== null && defaultId !== previous) {
              setHasAppliedDefaultPreset(false);
            }

            return defaultId;
          });
          setNotice(__("Preset verwijderd.", "sbdp"));
          return response;
        }

        return loadPresets().then((data) => {
          setNotice(__("Preset verwijderd.", "sbdp"));
          return data ?? response;
        });
      })
      .catch((requestError) => {
        setError(requestError.message);
        throw requestError;
      })
      .finally(() => {
        setPresetDeletingId((current) => (current === presetId ? "" : current));
      });
  };

  const handlePresetSetDefault = (preset) => {
    if (!preset || preset.scope !== "shared") {
      return Promise.resolve();
    }

    return handlePresetSave(preset.name, {
      scope: "shared",
      presetId: preset.id,
      filters: preset.filters,
      setDefault: true,
    }).then((response) => {
      setNotice(sprintf(__('Team preset "%s" set as default.', "sbdp"), preset.name));
      return response;
    });
  };

  useEffect(() => {
    if (typeof window === "undefined") {
      return;
    }

    const value = viewMode === "calendar" ? `calendar:${calendarView}` : "list";

    try {
      window.localStorage.setItem(storageKey, value);
    } catch (storageError) {
      // ignore storage failures
    }
  }, [calendarView, storageKey, viewMode]);

  const handleCalendarNavigate = (direction) => {
    setCalendarDate((current) => {
      if (direction === "today") {
        return normalizeDate(new Date());
      }

      const next = new Date(current);
      const step = calendarView === "week" ? 7 : 1;

      if (direction === "next") {
        next.setDate(next.getDate() + step);
      } else if (direction === "prev") {
        next.setDate(next.getDate() - step);
      }

      return normalizeDate(next);
    });
  };

  const handleStatusChange = (booking, status) => {
    bookingApi
      .updateBookingDetails({ booking_id: booking.booking_id, status })
      .then((response) => {
        const updated = response.booking || response;
        setBookings((current) =>
          current.map((item) => (item.booking_id === booking.booking_id ? updated : item))
        );
      })
      .catch((requestError) => setError(requestError.message));
  };

  const handleAddBooking = () => setModalOpen(true);

  const handleCreateBooking = (form) => {
    setError("");
    setNotice("");

    const productId = parseInt(form.product, 10);
    const persons = parseInt(form.persons, 10) || 1;

    const payload = {
      product: Number.isNaN(productId) ? 0 : productId,
      date_start: form.date_start,
      time_start: form.time_start,
      date_end: form.date_end || form.date_start,
      time_end: form.time_end,
      persons,
      customer_name: form.customer_name,
      customer_email: form.customer_email,
      customer_phone: form.customer_phone || undefined,
      customer_company: form.customer_company || undefined,
      customer_billing: form.customer_billing,
      customer_shipping: form.customer_shipping,
      customer_id: form.customer_id ? parseInt(form.customer_id, 10) : undefined,
      price: form.price === "" ? undefined : parseFloat(form.price),
      status: (form.status || "pending").toLowerCase(),
      send_invoice: Boolean(form.send_invoice),
    };

    return bookingApi
      .addBooking(payload)
      .then((booking) => {
        setBookings((current) => [booking, ...current]);
        setSelectedBooking(booking);
        const message = form.send_invoice
          ? __("Booking saved and invoice dispatched.", "sbdp")
          : __("Booking saved.", "sbdp");
        setNotice(message);
      })
      .catch((requestError) => setError(requestError.message));
  };

  const handleInvoice = (bookingId, options = {}) => {
    setError("");
    setNotice("");
    setInvoicingBookingId(bookingId);

    return bookingApi
      .invoiceBooking({ booking_id: bookingId, force: Boolean(options.force) })
      .then((response) => {
        if (response.booking) {
          setBookings((current) =>
            current.map((item) => (item.booking_id === bookingId ? response.booking : item))
          );
          setSelectedBooking(response.booking);
        }
        setNotice(__("Invoice request dispatched.", "sbdp"));
      })
      .catch((requestError) => setError(requestError.message))
      .finally(() => setInvoicingBookingId(null));
  };

  const handleDownloadInvoice = (bookingId) => {
    setError("");
    setNotice("");
    setDownloadingBookingId(bookingId);

    return bookingApi
      .downloadInvoice({ booking_id: bookingId })
      .then((response) => {
        if (response.booking) {
          setBookings((current) =>
            current.map((item) => (item.booking_id === bookingId ? response.booking : item))
          );
          setSelectedBooking(response.booking);
        }

        const fileName = response.file_name || `booking-${bookingId}-invoice.pdf`;
        let handled = false;

        if (response.pdf_url) {
          window.open(response.pdf_url, "_blank", "noopener");
          handled = true;
        } else if (response.pdf_base64) {
          try {
            const byteCharacters = atob(response.pdf_base64);
            const byteNumbers = new Array(byteCharacters.length);
            for (let index = 0; index < byteCharacters.length; index += 1) {
              byteNumbers[index] = byteCharacters.charCodeAt(index);
            }

            const byteArray = new Uint8Array(byteNumbers);
            const blob = new Blob([byteArray], { type: "application/pdf" });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            link.download = fileName;
            link.click();
            URL.revokeObjectURL(url);
            handled = true;
          } catch (conversionError) {
            setError(conversionError.message);
          }
        }

        setNotice(
          handled
            ? __("Invoice PDF ready.", "sbdp")
            : __("Invoice data refreshed.", "sbdp")
        );
      })
      .catch((requestError) => setError(requestError.message))
      .finally(() => setDownloadingBookingId(null));
  };

  const handleExport = () => {
    bookingApi
      .exportBookings({ filters })
      .then((data) => {
        const blob = new Blob([JSON.stringify(data.rows, null, 2)], { type: "application/json" });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = data.file_name || "booking-board-export.json";
        link.click();
        URL.revokeObjectURL(url);
      })
      .catch((requestError) => setError(requestError.message));
  };

  const openRescheduleModal = (booking, slot = null) => {
    if (!booking) {
      return;
    }

    setRescheduleState({
      open: true,
      booking,
      slot,
    });
  };

  const closeRescheduleModal = () => {
    setRescheduleState({
      open: false,
      booking: null,
      slot: null,
    });
  };

  const handleReschedule = (booking, slot) => {
    openRescheduleModal(booking, slot || null);
  };

  const handleRescheduleSubmit = ({ date_start, time_start, date_end, time_end, note, resource_id }) => {
    const booking = rescheduleState.booking;
    if (!booking) {
      return Promise.resolve();
    }

    const previous = {
      date_start: booking.from ? booking.from.slice(0, 10) : date_start,
      time_start: booking.from ? booking.from.slice(11, 16) : time_start,
      date_end: booking.to ? booking.to.slice(0, 10) : date_end,
      time_end: booking.to ? booking.to.slice(11, 16) : time_end,
    };

    setError("");
    setNotice("");

    const payload = {
      booking_id: booking.booking_id,
      date_start,
      time_start,
      date_end,
      time_end,
    };

    if (note && note.trim() !== "") {
      payload.note = note.trim();
    }

    if (resource_id) {
      payload.resource_id = resource_id;
    }

    return bookingApi
      .rescheduleBooking(payload)
      .then((response) => {
        const updated = response.booking || response;
        setBookings((current) =>
          current.map((item) => (item.booking_id === booking.booking_id ? updated : item))
        );
        setSelectedBooking((current) =>
          current && current.booking_id === booking.booking_id ? updated : current
        );
        setNotice(__("Booking rescheduled.", "sbdp"));
        setUndoState({
          bookingId: booking.booking_id,
          previous,
          latest: updated,
        });
        closeRescheduleModal();
      })
      .catch((requestError) => {
        setError(requestError.message);
        throw requestError;
      });
  };

  const handleBookingDetailsUpdate = (payload) => {
    if (!payload || !payload.booking_id) {
      return Promise.reject(new Error(__("Invalid booking change.", "sbdp")));
    }

    setError("");
    setNotice("");

    return bookingApi
      .updateBookingDetails(payload)
      .then((response) => {
        const updated = response.booking || response;
        const bookingId = updated?.booking_id ?? payload.booking_id;
        setBookings((current) =>
          current.map((item) => (item.booking_id === bookingId ? updated : item))
        );
        setSelectedBooking((current) =>
          current && current.booking_id === bookingId ? updated : current
        );
        setNotice(__("Booking details updated.", "sbdp"));
        return updated;
      })
      .catch((requestError) => {
        setError(requestError.message);
        throw requestError;
      });
  };

  const handleUndoReschedule = () => {
    if (!undoState) {
      return;
    }

    const { bookingId, previous } = undoState;
    setError("");
    setNotice("");

    return bookingApi
      .rescheduleBooking({
        booking_id: bookingId,
        date_start: previous.date_start,
        time_start: previous.time_start,
        date_end: previous.date_end,
        time_end: previous.time_end,
      })
      .then((response) => {
        const updated = response.booking || response;
        setBookings((current) =>
          current.map((item) => (item.booking_id === bookingId ? updated : item))
        );
        setSelectedBooking((current) =>
          current && current.booking_id === bookingId ? updated : current
        );
        setUndoState(null);
        setNotice(__("Change undone.", "sbdp"));
      })
      .catch((requestError) => setError(requestError.message));
  };

  const undoMessage = undoState
    ? formatSlotLabel(
        undoState.latest && undoState.latest.from
          ? undoState.latest.from.slice(0, 10)
          : undoState.previous.date_start,
        undoState.latest && undoState.latest.from
          ? undoState.latest.from.slice(11, 16)
          : undoState.previous.time_start
      )
    : "";

  return (
    <div className="sbdp-booking-board">
      <div className="sbdp-booking-board__toolbar">
        <PresetQuickSwitch
          personalPresets={personalPresets}
          sharedPresets={sharedPresets}
          onApply={handlePresetApply}
          disabled={loading}
        />
        <HealthIndicator status={serviceStatus.status} details={serviceStatus.details} />
      </div>
      {notice && <div className="notice notice-success"><p>{notice}</p></div>}
      {error && <div className="notice notice-error"><p>{error}</p></div>}
      {undoState ? (
        <div className="notice notice-info sbdp-undo-banner">
          <p className="sbdp-undo-banner__message">
            Boeking verplaatst naar {undoMessage || "nieuw tijdslot"}.
          </p>
          <button type="button" className="button button-link" onClick={handleUndoReschedule}>
            Ongedaan maken
          </button>
        </div>
      ) : null}
      <QuickActions
        onAdd={handleAddBooking}
        onExport={handleExport}
        viewMode={viewMode}
        onViewModeChange={handleViewModeChange}
        calendarView={calendarView}
        onCalendarViewChange={handleCalendarViewChange}
        loading={loading}
      />
      <BookingStatsBar stats={stats} metrics={metrics} />
      {viewMode === "calendar" ? (
        <CalendarView
          bookings={bookings}
          onReschedule={handleReschedule}
          view={calendarView}
          activeDate={calendarDate}
          onNavigate={handleCalendarNavigate}
          loading={loading}
        />
      ) : (
        <BookingList
          columns={columns}
          items={bookings}
          filters={filters}
          onFiltersChange={handleFiltersChange}
          onRowClick={setSelectedBooking}
          onStatusChange={handleStatusChange}
          colorMap={statusColors}
          loading={loading}
          personalPresets={personalPresets}
          sharedPresets={sharedPresets}
          canManageShared={canManageSharedPresets}
          defaultSharedPresetId={defaultSharedPresetId}
          onPresetApply={handlePresetApply}
          onPresetSave={handlePresetSave}
          onPresetDelete={handlePresetDelete}
          onPresetSetDefault={handlePresetSetDefault}
          presetsLoading={presetsLoading}
          presetSaving={presetSaving}
          presetDeletingId={presetDeletingId}
          statusOptions={statusOptions}
        />
      )}
      <BookingDetailsPanel
        booking={selectedBooking}
        onClose={() => setSelectedBooking(null)}
        onInvoice={handleInvoice}
        invoicing={Boolean(selectedBooking && invoicingBookingId === selectedBooking.booking_id)}
        onDownloadInvoice={handleDownloadInvoice}
        downloading={Boolean(selectedBooking && downloadingBookingId === selectedBooking.booking_id)}
        onReschedule={handleReschedule}
        onUpdate={handleBookingDetailsUpdate}
        statusOptions={statusOptions}
      />
      <AddBookingModal
        isOpen={isModalOpen}
        onClose={() => setModalOpen(false)}
        onSubmit={handleCreateBooking}
        onCustomerLookup={lookupCustomers}
        api={bookingApi}
      />
      <RescheduleModal
        isOpen={rescheduleState.open}
        booking={rescheduleState.booking}
        initialSlot={rescheduleState.slot}
        onClose={closeRescheduleModal}
        onSubmit={handleRescheduleSubmit}
        api={bookingApi}
      />
    </div>
  );
}

export default BookingBoardApp;

import React, { useEffect, useMemo, useState } from "react";
import PropTypes from "prop-types";

const DEFAULT_FORM = {
  date_start: "",
  time_start: "",
  date_end: "",
  time_end: "",
  note: "",
};

const pad = (value) => String(value).padStart(2, "0");

function extractParts(value) {
  if (!value || typeof value !== "string" || value.length < 16) {
    return { date: "", time: "" };
  }

  return {
    date: value.slice(0, 10),
    time: value.slice(11, 16),
  };
}

function computeEnd({ date, time, duration }) {
  if (!date || !time || !duration) {
    return { date, time };
  }

  const [hours, minutes] = time.split(":").map((item) => parseInt(item, 10));
  if ([hours, minutes].some((item) => Number.isNaN(item))) {
    return { date, time };
  }

  const dateObj = new Date(`${date}T${pad(hours)}:${pad(minutes)}:00`);
  if (Number.isNaN(dateObj.getTime())) {
    return { date, time };
  }

  dateObj.setMinutes(dateObj.getMinutes() + Number(duration));

  return {
    date: `${dateObj.getFullYear()}-${pad(dateObj.getMonth() + 1)}-${pad(dateObj.getDate())}`,
    time: `${pad(dateObj.getHours())}:${pad(dateObj.getMinutes())}`,
  };
}

function resolveProductId(booking) {
  if (!booking) {
    return null;
  }

  if (booking.product_id) {
    return booking.product_id;
  }

  if (booking.productId) {
    return booking.productId;
  }

  if (booking.items && Array.isArray(booking.items) && booking.items.length > 0) {
    const first = booking.items[0];
    if (typeof first === "object" && first !== null && first.product_id) {
      return first.product_id;
    }
  }

  if (booking.meta && booking.meta.product_id) {
    return booking.meta.product_id;
  }

  return null;
}

function buildInitialForm(booking, slot) {
  if (!booking) {
    return DEFAULT_FORM;
  }

  const currentStart = extractParts(booking.from);
  const currentEnd = extractParts(booking.to);
  const duration = booking.duration || booking.duration_minutes || 0;

  const baseDate = slot && slot.date ? slot.date : currentStart.date;
  const baseTime = slot && slot.time ? slot.time : currentStart.time || "09:00";

  const calculatedEnd = computeEnd({
    date: baseDate,
    time: baseTime,
    duration: duration || 60,
  });

  return {
    date_start: baseDate,
    time_start: baseTime,
    date_end: currentEnd.date || calculatedEnd.date,
    time_end: currentEnd.time || calculatedEnd.time,
    note: "",
  };
}

function RescheduleModal({ isOpen, booking, initialSlot, onClose, onSubmit, api }) {
  const [form, setForm] = useState(DEFAULT_FORM);
  const [saving, setSaving] = useState(false);
  const [conflict, setConflict] = useState({ status: "idle", message: "" });

  const productId = useMemo(() => resolveProductId(booking), [booking]);
  const duration = useMemo(() => booking?.duration || booking?.duration_minutes || 0, [booking]);

  useEffect(() => {
    if (!isOpen || !booking) {
      setForm(DEFAULT_FORM);
      setConflict({ status: "idle", message: "" });
      return;
    }

    setForm(buildInitialForm(booking, initialSlot));
    setConflict({ status: "idle", message: "" });
  }, [booking, initialSlot, isOpen]);

  useEffect(() => {
    if (!isOpen || !booking) {
      return;
    }

    if (!productId || !form.date_start || !form.time_start || !form.date_end || !form.time_end) {
      setConflict({ status: "idle", message: "" });
      return;
    }

    let cancelled = false;
    const timer = setTimeout(() => {
      if (!api || typeof api.checkConflict !== "function") {
        return;
      }

      setConflict({ status: "checking", message: "" });
      api
        .checkConflict({
          booking_id: booking.booking_id,
          product_id: productId,
          start_at: `${form.date_start} ${form.time_start}`,
          end_at: `${form.date_end} ${form.time_end}`,
          participants: booking.people || undefined,
        })
        .then(() => {
          if (!cancelled) {
            setConflict({ status: "ok", message: "Geen conflicten gevonden." });
          }
        })
        .catch((error) => {
          if (!cancelled) {
            setConflict({
              status: "error",
              message: error.message || "Tijdslot geeft een conflict.",
            });
          }
        });
    }, 350);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [api, booking, form.date_end, form.date_start, form.time_end, form.time_start, isOpen, productId]);

  const handleChange = (event) => {
    const { name, value } = event.target;
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const autoAdjustEnd = () => {
    if (!duration || !form.date_start || !form.time_start) {
      return;
    }

    const next = computeEnd({
      date: form.date_start,
      time: form.time_start,
      duration,
    });

    setForm((prev) => ({
      ...prev,
      date_end: next.date,
      time_end: next.time,
    }));
  };

  const handleSubmit = (event) => {
    event.preventDefault();
    if (!booking) {
      return;
    }

    setSaving(true);
    Promise.resolve(
      onSubmit({
        ...form,
        resource_id: initialSlot?.resourceId || null,
      })
    )
      .then(() => {
        setSaving(false);
      })
      .catch((error) => {
        console.error(error); // eslint-disable-line no-console
        setSaving(false);
      });
  };

  if (!isOpen || !booking) {
    return null;
  }

  const conflictClass =
    conflict.status === "error"
      ? "sbdp-conflict-indicator sbdp-conflict-indicator--error"
      : conflict.status === "checking"
        ? "sbdp-conflict-indicator sbdp-conflict-indicator--checking"
        : conflict.status === "ok"
          ? "sbdp-conflict-indicator sbdp-conflict-indicator--ok"
          : null;

  return (
    <div className="sbdp-modal-overlay" role="dialog" aria-modal="true">
      <div className="sbdp-modal">
        <div className="sbdp-modal__header">
          <h2 className="sbdp-modal__title">Boeking verplaatsen</h2>
          <p className="sbdp-modal__subline">
            #{booking.booking_id} — {booking.product || "Onbekend product"}
          </p>
        </div>
        <form onSubmit={handleSubmit}>
          <div className="sbdp-modal__body">
            <div className="sbdp-form-grid">
              <div className="sbdp-form-field">
                <label htmlFor="reschedule-date-start">Nieuwe startdatum</label>
                <input
                  id="reschedule-date-start"
                  name="date_start"
                  type="date"
                  value={form.date_start}
                  onChange={handleChange}
                  required
                />
              </div>
              <div className="sbdp-form-field">
                <label htmlFor="reschedule-time-start">Starttijd</label>
                <input
                  id="reschedule-time-start"
                  name="time_start"
                  type="time"
                  step="300"
                  value={form.time_start}
                  onChange={handleChange}
                  required
                />
                <p className="sbdp-form-helper">Huidige duur: {duration || 0} minuten</p>
              </div>
              <div className="sbdp-form-field">
                <label htmlFor="reschedule-date-end">Einddatum</label>
                <input
                  id="reschedule-date-end"
                  name="date_end"
                  type="date"
                  value={form.date_end}
                  onChange={handleChange}
                  required
                />
              </div>
              <div className="sbdp-form-field">
                <label htmlFor="reschedule-time-end">Eindtijd</label>
                <input
                  id="reschedule-time-end"
                  name="time_end"
                  type="time"
                  step="300"
                  value={form.time_end}
                  onChange={handleChange}
                  required
                />
                <p className="sbdp-form-helper">
                  <button
                    type="button"
                    className="button button-link"
                    onClick={autoAdjustEnd}
                    disabled={!duration}
                  >
                    Herbereken op basis van duur
                  </button>
                </p>
              </div>
            </div>

            <div className="sbdp-form-field">
              <label htmlFor="reschedule-note">Notitie voor audit trail</label>
              <textarea
                id="reschedule-note"
                name="note"
                placeholder="Optioneel: reden of extra context voor deze wijziging."
                value={form.note}
                onChange={handleChange}
              />
            </div>

            {conflictClass ? (
              <div className={conflictClass}>
                {conflict.status === "checking" ? "Beschikbaarheid controleren…" : conflict.message}
              </div>
            ) : null}
          </div>
          <div className="sbdp-modal__footer">
            <button type="button" className="button button-secondary" onClick={onClose} disabled={saving}>
              Annuleren
            </button>
            <div className="sbdp-modal__actions">
              <button
                type="submit"
                className="button button-primary"
                disabled={saving || conflict.status === "error"}
              >
                {saving ? "Opslaan…" : "Bevestigen"}
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}

RescheduleModal.propTypes = {
  isOpen: PropTypes.bool.isRequired,
  booking: PropTypes.object,
  initialSlot: PropTypes.shape({
    date: PropTypes.string,
    time: PropTypes.string,
    resourceId: PropTypes.string,
  }),
  onClose: PropTypes.func.isRequired,
  onSubmit: PropTypes.func.isRequired,
  api: PropTypes.shape({
    checkConflict: PropTypes.func,
  }),
};

RescheduleModal.defaultProps = {
  booking: null,
  initialSlot: null,
  api: null,
};

export default RescheduleModal;

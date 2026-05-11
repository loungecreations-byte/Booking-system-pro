import React, { useEffect, useMemo, useState } from "react";
import PropTypes from "prop-types";
import { __, sprintf } from "@wordpress/i18n";

const createDraftFromBooking = (booking) => ({
  status: booking?.status || "pending",
  people:
    typeof booking?.people === "number" && !Number.isNaN(booking.people)
      ? String(booking.people)
      : booking?.people
        ? String(booking.people)
        : "",
  price:
    booking?.price && typeof booking.price.amount === "number"
      ? booking.price.amount.toFixed(2)
      : "",
  notes: booking?.notes || "",
});

function BookingDetailsPanel({
  booking,
  onClose,
  onInvoice,
  invoicing,
  onDownloadInvoice,
  downloading,
  onReschedule,
  onUpdate,
  statusOptions,
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(createDraftFromBooking(null));
  const [saving, setSaving] = useState(false);
  const [localError, setLocalError] = useState("");
  const resolvedStatusOptions = useMemo(() => {
    if (Array.isArray(statusOptions) && statusOptions.length > 0) {
      return statusOptions;
    }

    return [
      { value: "created", label: __("Created", "sbdp") },
      { value: "requested", label: __("Requested", "sbdp") },
      { value: "captured", label: __("Captured", "sbdp") },
      { value: "pending", label: __("Pending", "sbdp") },
      { value: "paid", label: __("Paid", "sbdp") },
      { value: "completed", label: __("Completed", "sbdp") },
      { value: "fully_confirmed", label: __("Volledig bevestigd", "sbdp") },
      { value: "cancelled", label: __("Cancelled", "sbdp") },
    ];
  }, [statusOptions]);

  const statusLabelMap = useMemo(() => {
    const map = {};
    resolvedStatusOptions.forEach((option) => {
      map[option.value] = option.label;
    });

    return map;
  }, [resolvedStatusOptions]);

  useEffect(() => {
    if (!booking) {
      setEditing(false);
      setDraft(createDraftFromBooking(null));
      setSaving(false);
      setLocalError("");
      return;
    }

    setEditing(false);
    setDraft(createDraftFromBooking(booking));
    setSaving(false);
    setLocalError("");
  }, [booking]);

  if (!booking) {
    return (
      <div className="sbdp-details-panel sbdp-details-panel--empty">
        <p>{__("Select a booking to see details.", "sbdp")}</p>
      </div>
    );
  }

  const customerDetails = booking.customer_details || {};
  const customerName = customerDetails.name || booking.customer || "";
  const customerEmail = customerDetails.email || booking.customer_email || "";
  const billing = customerDetails.billing?.formatted || "";
  const shipping = customerDetails.shipping?.formatted || "";
  const phone = customerDetails.phone || booking.customer_phone || "";
  const company = customerDetails.company || "";
  const order = booking.order || null;
  const paymentRequest = booking.payment_request || null;
  const currency = booking.price?.currency || "EUR";
  const priceDisplay =
    booking.price && typeof booking.price.amount === "number"
      ? booking.price.amount.toFixed(2)
      : "-";

  const handleDraftChange = (event) => {
    const { name, value } = event.target;
    setDraft((prev) => ({ ...prev, [name]: value }));
  };

  const handleStartEditing = () => {
    setEditing(true);
    setLocalError("");
    setDraft(createDraftFromBooking(booking));
  };

  const handleCancel = () => {
    setEditing(false);
    setSaving(false);
    setLocalError("");
    setDraft(createDraftFromBooking(booking));
  };

  const handleSave = () => {
    if (!onUpdate) {
      setEditing(false);
      return;
    }

    setSaving(true);
    setLocalError("");

    const payload = {
      booking_id: booking.booking_id,
      status: draft.status,
      notes: draft.notes,
    };

    let participants = parseInt(draft.people, 10);
    if (Number.isNaN(participants) || participants <= 0) {
      const originalParticipants = parseInt(booking.people, 10);
      participants =
        Number.isNaN(originalParticipants) || originalParticipants <= 0
          ? null
          : originalParticipants;
    }

    if (Number.isFinite(participants) && participants > 0) {
      payload.participants = participants;
    }

    const priceValue = parseFloat(draft.price);
    if (!Number.isNaN(priceValue)) {
      payload.total = priceValue;
      if (booking.price && booking.price.currency) {
        payload.currency = booking.price.currency;
      }
    }

    Promise.resolve(onUpdate(payload))
      .then(() => {
        setSaving(false);
        setEditing(false);
        setLocalError("");
      })
      .catch((error) => {
        setSaving(false);
        setLocalError(error?.message || __("Saving changes failed.", "sbdp"));
      });
  };

  return (
    <div className="sbdp-details-panel">
      <header className="sbdp-details-panel__header">
        <h3>{sprintf(__("Booking #%s", "sbdp"), booking.booking_id)}</h3>
        <div className="sbdp-details-panel__actions">
          {onReschedule && (
            <button type="button" onClick={() => onReschedule(booking)}>
              {__("Reschedule", "sbdp")}
            </button>
          )}
          {onUpdate && !editing && (
            <button type="button" onClick={handleStartEditing}>
              {__("Edit details", "sbdp")}
            </button>
          )}
          {editing && (
            <>
              <button type="button" onClick={handleSave} disabled={saving}>
                {saving ? "Saving…" : "Save"}
              </button>
              <button type="button" onClick={handleCancel} disabled={saving}>
                Cancel
              </button>
            </>
          )}
          {onDownloadInvoice && (
            <button
              type="button"
              onClick={() => onDownloadInvoice(booking.booking_id)}
              disabled={downloading}
            >
              {downloading ? __("Generating PDF…", "sbdp") : __("Download invoice", "sbdp")}
            </button>
          )}
          {onInvoice && (
            <button
              type="button"
              onClick={() => onInvoice(booking.booking_id)}
              disabled={invoicing}
            >
              {invoicing ? __("Sending invoice…", "sbdp") : __("Send invoice", "sbdp")}
            </button>
          )}
          <button type="button" onClick={onClose}>
            Close
          </button>
        </div>
      </header>
      {localError && (
        <div className="notice notice-error">
          <p>{localError}</p>
        </div>
      )}
      <dl className="sbdp-details-panel__list">
        <div>
          <dt>{__("Product", "sbdp")}</dt>
          <dd>{booking.product}</dd>
        </div>
        <div>
          <dt>{__("Customer", "sbdp")}</dt>
          <dd>
            {customerName || "-"}
            {customerEmail ? (
              <>
                {" "}(<a href={`mailto:${customerEmail}`}>{customerEmail}</a>)
              </>
            ) : null}
          </dd>
        </div>
        <div>
          <dt>{__("Contact", "sbdp")}</dt>
          <dd>
            {phone || "-"}
            {company ? ` | ${company}` : ""}
          </dd>
        </div>
        <div>
          <dt>{__("Schedule", "sbdp")}</dt>
          <dd>
            {booking.from} - {booking.to}
          </dd>
        </div>
        <div>
          <dt>{__("People", "sbdp")}</dt>
          <dd>
            {editing ? (
              <input
                type="number"
                min="0"
                name="people"
                value={draft.people}
                onChange={handleDraftChange}
              />
            ) : (
              booking.people
            )}
          </dd>
        </div>
        <div>
          <dt>{__("Status", "sbdp")}</dt>
          <dd>
            {editing ? (
              <select name="status" value={draft.status} onChange={handleDraftChange}>
                {resolvedStatusOptions.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            ) : (
              statusLabelMap[booking.status] || booking.status
            )}
          </dd>
        </div>
        <div>
          <dt>{__("Billing", "sbdp")}</dt>
          <dd>{billing || "-"}</dd>
        </div>
        <div>
          <dt>{__("Shipping", "sbdp")}</dt>
          <dd>{shipping || "-"}</dd>
        </div>
        <div>
          <dt>{__("Order", "sbdp")}</dt>
          <dd>
            {order && order.id ? (
              <>
                #{order.number || order.id}
                {paymentRequest?.status ? ` | ${paymentRequest.status}` : ""}
                {paymentRequest?.url ? (
                  <>
                    {" | "}
                    <a href={paymentRequest.url} target="_blank" rel="noreferrer">
                      {__("Payment link", "sbdp")}
                    </a>
                  </>
                ) : null}
              </>
            ) : (
              "-"
            )}
          </dd>
        </div>
        <div>
          <dt>{__("Price", "sbdp")}</dt>
          <dd>
            {editing ? (
              <span className="sbdp-inline-field">
                <input
                  type="number"
                  step="0.01"
                  name="price"
                  value={draft.price}
                  onChange={handleDraftChange}
                />
                <span>{currency}</span>
              </span>
            ) : (
              priceDisplay === "-" ? "-" : `${priceDisplay} ${currency}`
            )}
          </dd>
        </div>
        <div>
          <dt>{__("Notes", "sbdp")}</dt>
          <dd>
            {editing ? (
              <textarea
                name="notes"
                value={draft.notes}
                onChange={handleDraftChange}
                rows={4}
              />
            ) : (
              booking.notes || "-"
            )}
          </dd>
        </div>
      </dl>
      {booking.operations?.dietary && (
        <section className="sbdp-details-panel__dietary">
          <h4>{__("Dietary / Allergens", "sbdp")}</h4>
          <dl className="sbdp-details-panel__list">
            <div>
              <dt>{__("Guest count", "sbdp")}</dt>
              <dd>{booking.operations.dietary.guest_count ?? "-"}</dd>
            </div>
            <div>
              <dt>{__("Highest severity", "sbdp")}</dt>
              <dd>{booking.operations.dietary.highest_severity || "-"}</dd>
            </div>
            <div>
              <dt>{__("Allergens", "sbdp")}</dt>
              <dd>
                {Array.isArray(booking.operations.dietary.allergen_flags) && booking.operations.dietary.allergen_flags.length
                  ? booking.operations.dietary.allergen_flags.join(", ")
                  : "-"}
              </dd>
            </div>
            <div>
              <dt>{__("Partner status", "sbdp")}</dt>
              <dd>
                {booking.operations.dietary.unresolved
                  ? __("Pending partner confirmation", "sbdp")
                  : __("Cleared", "sbdp")}
              </dd>
            </div>
          </dl>
        </section>
      )}
    </div>
  );
}

BookingDetailsPanel.propTypes = {
  booking: PropTypes.object,
  onClose: PropTypes.func.isRequired,
  onInvoice: PropTypes.func,
  invoicing: PropTypes.bool,
  onDownloadInvoice: PropTypes.func,
  downloading: PropTypes.bool,
  onReschedule: PropTypes.func,
  onUpdate: PropTypes.func,
  statusOptions: PropTypes.arrayOf(
    PropTypes.shape({
      value: PropTypes.string.isRequired,
      label: PropTypes.string.isRequired,
    })
  ),
};

BookingDetailsPanel.defaultProps = {
  booking: null,
  onInvoice: null,
  invoicing: false,
  onDownloadInvoice: null,
  downloading: false,
  onReschedule: null,
  onUpdate: null,
  statusOptions: [],
};

export default BookingDetailsPanel;






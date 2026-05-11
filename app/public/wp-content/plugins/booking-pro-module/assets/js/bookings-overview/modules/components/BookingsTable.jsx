import React, { useMemo } from "react";

function formatCurrency(amount, currency) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: currency || "EUR",
    }).format(amount);
  } catch (error) {
    const prefix = currency || "EUR";
    return `${prefix} ${Number(amount).toFixed(2)}`;
  }
}

function formatCountdown(start, now, i18n) {
  if (!start) {
    return "";
  }

  const target = new Date(start).getTime();
  if (Number.isNaN(target)) {
    return "";
  }

  const diffMinutes = Math.round((target - now) / 60000);
  const prettyDiff = (minutes) => {
    const absolute = Math.abs(minutes);
    const days = Math.floor(absolute / (60 * 24));
    const hours = Math.floor((absolute % (60 * 24)) / 60);
    const mins = absolute % 60;
    const parts = [];

    if (days) {
      parts.push(`${days}d`);
    }

    if (hours) {
      parts.push(`${hours}u`);
    }

    if (mins && parts.length < 2) {
      parts.push(`${mins}m`);
    }

    return parts.slice(0, 2).join(" ") || `${mins}m`;
  };

  if (diffMinutes === 0) {
    return i18n.countdownNow || "Start nu";
  }

  if (diffMinutes > 0) {
    const template = i18n.countdownIn || "Over %s";
    return template.replace("%s", prettyDiff(diffMinutes));
  }

  return `${i18n.countdownPast || "Verlopen"} ${prettyDiff(diffMinutes)}`;
}

function resolveStartLabel(booking) {
  if (booking.formatted_start) {
    return booking.formatted_start;
  }

  if (!booking.start) {
    return "";
  }

  const date = new Date(booking.start);
  if (Number.isNaN(date.getTime())) {
    return booking.start;
  }

  return date.toLocaleString();
}

function BookingsTable({
  bookings,
  loading,
  pagination,
  onPageChange,
  selectedIds,
  onToggleSelect,
  onToggleAll,
  statusBadges,
  columns,
  i18n,
  now,
}) {
  const selectedSet = useMemo(() => new Set((selectedIds || []).map((value) => Number(value))), [selectedIds]);
  const allSelected = bookings.length > 0 && selectedSet.size === bookings.length;

  if (loading && bookings.length === 0) {
    return <div className="sbdp-bookings-overview-empty">{i18n.loading || "Boekingen laden…"}</div>
  }

  if (!loading && bookings.length === 0) {
    return <div className="sbdp-bookings-overview-empty">{i18n.noResults || "Geen resultaten."}</div>;
  }

  return (
    <div>
      <table>
        <thead>
          <tr>
            <th>
              <input
                type="checkbox"
                aria-label={i18n.selectAll || "Alles selecteren"}
                checked={allSelected}
                onChange={() => onToggleAll()}
              />
            </th>
            <th>{columns.order_number}</th>
            <th>{columns.activity}</th>
            <th>{columns.customer}</th>
            <th>{columns.start}</th>
            <th>{columns.duration}</th>
            <th>{columns.people}</th>
            <th>{columns.total}</th>
            <th>{columns.status}</th>
            <th>{columns.extras}</th>
          </tr>
        </thead>
        <tbody>
          {bookings.map((booking) => {
            const isSelected = selectedSet.has(Number(booking.id));
            const extras = Array.isArray(booking.extras) ? booking.extras.join(", ") : "";
            const countdown = formatCountdown(booking.start, now, i18n);
            const statusLabel = statusBadges[booking.status] || booking.status || "";

            return (
              <tr key={booking.id}>
                <td>
                  <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={() => onToggleSelect(booking.id)}
                    aria-label={`Selecteer boeking ${booking.order_number || booking.id}`}
                  />
                </td>
                <td>{booking.order_number || booking.id}</td>
                <td>{booking.activity || "-"}</td>
                <td>
                  <div>{booking.customer || "-"}</div>
                  {booking.email && <small>{booking.email}</small>}
                </td>
                <td>
                  <div>{resolveStartLabel(booking)}</div>
                  {countdown && <span className="sbdp-bookings-overview-countdown">{countdown}</span>}
                </td>
                <td>{booking.duration_label || booking.duration || "-"}</td>
                <td>{booking.people ?? "-"}</td>
                <td>{formatCurrency(booking.total, booking.currency)}</td>
                <td>
                  <span className="sbdp-bookings-overview-badge">{statusLabel}</span>
                </td>
                <td>{extras || "-"}</td>
              </tr>
            );
          })}
        </tbody>
      </table>

      <div className="sbdp-bookings-overview-pagination">
        <button
          type="button"
          className="button"
          onClick={() => onPageChange(Math.max(1, (pagination.page || 1) - 1))}
          disabled={loading || (pagination.page || 1) <= 1}
        >
          {"\u2039"}
        </button>
        <span>
          {(i18n.pagination || "Pagina %1$d van %2$d")
            .replace("%1$d", pagination.page || 1)
            .replace("%2$d", pagination.total_pages || 1)}
        </span>
        <button
          type="button"
          className="button"
          onClick={() => onPageChange((pagination.page || 1) + 1)}
          disabled={
            loading ||
            (pagination.total_pages && pagination.page >= pagination.total_pages)
          }
        >
          {"\u203A"}
        </button>
      </div>
    </div>
  );
}

export default BookingsTable;




import React, { useMemo } from "react";
import PropTypes from "prop-types";

const START_HOUR = 6;
const END_HOUR = 22;
const SLOT_INTERVAL = 30;
const SLOT_HEIGHT = 40;
const TOTAL_MINUTES = (END_HOUR - START_HOUR) * 60;

const dayFormatter = new Intl.DateTimeFormat(undefined, {
  weekday: "short",
  month: "short",
  day: "numeric",
});

const pad = (value) => String(value).padStart(2, "0");

function buildTimeSlots() {
  const slots = [];
  const startMinutes = START_HOUR * 60;
  const endMinutes = END_HOUR * 60;

  for (let minutes = startMinutes; minutes < endMinutes; minutes += SLOT_INTERVAL) {
    const hour = Math.floor(minutes / 60);
    const minute = minutes % 60;

    slots.push({
      value: `${pad(hour)}:${pad(minute)}`,
      label: minute === 0 ? `${pad(hour)}:00` : "",
      minutesFromStart: minutes - startMinutes,
    });
  }

  return slots;
}

function formatDateKey(date) {
  const normalized = new Date(date);
  normalized.setHours(0, 0, 0, 0);

  return normalized.toISOString().slice(0, 10);
}

function normalizeBooking(booking) {
  if (!booking || !booking.from) {
    return null;
  }

  const start = new Date(booking.from);
  if (Number.isNaN(start.getTime())) {
    return null;
  }

  let end = booking.to ? new Date(booking.to) : null;
  let duration = booking.duration;

  if (!duration || duration <= 0) {
    if (end && !Number.isNaN(end.getTime())) {
      duration = Math.max(SLOT_INTERVAL, Math.round((end.getTime() - start.getTime()) / 60000));
    } else {
      duration = 60;
    }
  }

  if (!end || Number.isNaN(end.getTime())) {
    end = new Date(start);
    end.setMinutes(end.getMinutes() + duration);
  }

  const vendor = booking.vendor && typeof booking.vendor === "object" ? booking.vendor : {};
  const vendorId = vendor.id ? `vendor:${vendor.id}` : null;
  const resourceKey =
    vendorId ||
    `product:${booking.product_id || booking.product || booking.booking_id || start.getTime()}`;
  const resourceLabel =
    vendor.name ||
    vendor.title ||
    booking.product ||
    booking.product_label ||
    booking.product_name ||
    "Unassigned";

  return {
    raw: booking,
    start,
    end,
    duration,
    dateKey: start.toISOString().slice(0, 10),
    resourceKey,
    resourceLabel,
    vendorId,
  };
}

function derivePosition(booking) {
  const startMinutes = booking.start.getHours() * 60 + booking.start.getMinutes() - START_HOUR * 60;
  const offset = Math.max(0, Math.min(startMinutes, TOTAL_MINUTES));
  const top = (offset / SLOT_INTERVAL) * SLOT_HEIGHT;

  const remaining = TOTAL_MINUTES - offset;
  const duration = Math.max(SLOT_INTERVAL, Math.min(booking.duration, remaining));
  const height = Math.max(SLOT_HEIGHT - 4, (duration / SLOT_INTERVAL) * SLOT_HEIGHT - 4);

  return { top, height };
}

function DragDropScheduler({ bookings, onReschedule, view, rangeStart, rangeEnd }) {
  const normalizedBookings = useMemo(
    () => bookings.map(normalizeBooking).filter(Boolean),
    [bookings]
  );

  const bookingsMap = useMemo(
    () =>
      new Map(
        normalizedBookings.map((booking) => [
          String(booking.raw.booking_id ?? booking.raw.id),
          booking,
        ])
      ),
    [normalizedBookings]
  );

  const timeSlots = useMemo(() => buildTimeSlots(), []);

  const columns = useMemo(() => {
    if (view === "day") {
      const dayKey = formatDateKey(rangeStart);
      const byResource = new Map();

      normalizedBookings.forEach((booking) => {
        if (booking.dateKey !== dayKey) {
          return;
        }

        const key = booking.resourceKey;
        if (!byResource.has(key)) {
          byResource.set(key, {
            id: key,
            label: booking.resourceLabel,
            date: rangeStart,
            dateKey: dayKey,
            type: "resource",
            resourceKey: key,
            bookings: [],
          });
        }

        byResource.get(key).bookings.push(booking);
      });

      if (byResource.size === 0) {
        return [
          {
            id: "resource:unassigned",
            label: "Unassigned",
            date: rangeStart,
            dateKey: dayKey,
            type: "resource",
            resourceKey: null,
            bookings: [],
          },
        ];
      }

      return Array.from(byResource.values())
        .sort((a, b) => a.label.localeCompare(b.label))
        .map((column) => ({
          ...column,
          bookings: column.bookings.sort((a, b) => a.start.getTime() - b.start.getTime()),
        }));
    }

    const cols = [];
    const cursor = new Date(rangeStart);
    cursor.setHours(0, 0, 0, 0);

    while (cursor <= rangeEnd) {
      const key = formatDateKey(cursor);
      const columnBookings = normalizedBookings
        .filter((booking) => booking.dateKey === key)
        .sort((a, b) => a.start.getTime() - b.start.getTime());

      cols.push({
        id: `day:${key}`,
        label: dayFormatter.format(cursor),
        date: new Date(cursor),
        dateKey: key,
        type: "day",
        resourceKey: null,
        bookings: columnBookings,
      });

      cursor.setDate(cursor.getDate() + 1);
    }

    return cols;
  }, [normalizedBookings, rangeEnd, rangeStart, view]);

  const handleDragStart = (booking) => (event) => {
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData(
      "application/x-sbdp-booking",
      String(booking.raw.booking_id ?? booking.raw.id)
    );
    event.currentTarget.classList.add("is-dragging");
  };

  const handleDragEnd = (event) => {
    event.currentTarget.classList.remove("is-dragging");
  };

  const handleSlotDragOver = (event) => {
    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    event.currentTarget.classList.add("is-droppable");
  };

  const handleSlotDragLeave = (event) => {
    event.currentTarget.classList.remove("is-droppable");
  };

  const handleDrop = (column, slot) => (event) => {
    event.preventDefault();
    event.currentTarget.classList.remove("is-droppable");

    const bookingId = event.dataTransfer.getData("application/x-sbdp-booking");
    if (!bookingId) {
      return;
    }

    const booking = bookingsMap.get(bookingId);
    if (!booking) {
      return;
    }

    const payload = {
      date: column.dateKey,
      time: slot.value,
      resourceId: column.type === "resource" ? column.resourceKey : booking.resourceKey,
    };

    onReschedule(booking.raw, payload);
  };

  const gridHeight = timeSlots.length * SLOT_HEIGHT;
  const emptyState = columns.every((column) => column.bookings.length === 0);

  return (
    <div className="sbdp-scheduler">
      <div
        className="sbdp-scheduler__grid"
        style={{ gridTemplateColumns: `70px repeat(${columns.length}, minmax(160px, 1fr))` }}
      >
        <div className="sbdp-scheduler__column sbdp-scheduler__column--times">
          <div className="sbdp-scheduler__column-header">Time</div>
          <div className="sbdp-scheduler__column-body" style={{ minHeight: gridHeight }}>
            {timeSlots.map((slot) => (
              <div key={slot.value} className="sbdp-scheduler__timeslot">
                {slot.label}
              </div>
            ))}
          </div>
        </div>
        {columns.map((column) => (
          <div className="sbdp-scheduler__column" key={column.id}>
            <div className="sbdp-scheduler__column-header">{column.label}</div>
            <div className="sbdp-scheduler__column-body" style={{ minHeight: gridHeight }}>
              {timeSlots.map((slot) => (
                <div
                  key={`${column.id}-${slot.value}`}
                  className="sbdp-scheduler__slot"
                  onDragOver={handleSlotDragOver}
                  onDragLeave={handleSlotDragLeave}
                  onDrop={handleDrop(column, slot)}
                  role="presentation"
                />
              ))}
              {column.bookings.map((booking) => {
                const { top, height } = derivePosition(booking);
                const startLabel = `${pad(booking.start.getHours())}:${pad(
                  booking.start.getMinutes()
                )}`;
                const endLabel = `${pad(booking.end.getHours())}:${pad(booking.end.getMinutes())}`;

                return (
                  <div
                    key={`booking-${booking.raw.booking_id ?? booking.raw.id}`}
                    className="sbdp-scheduler__event"
                    style={{ top, height }}
                    draggable
                    onDragStart={handleDragStart(booking)}
                    onDragEnd={handleDragEnd}
                  >
                    <div className="sbdp-scheduler__event-title">
                      {booking.raw.product || booking.resourceLabel}
                    </div>
                    <div className="sbdp-scheduler__event-meta">
                      <span>
                        {startLabel} – {endLabel}
                      </span>
                      {view === "week" ? <span>{booking.resourceLabel}</span> : null}
                      {booking.raw.people ? <span>{booking.raw.people} ppl</span> : null}
                      {booking.raw.status ? <span>{booking.raw.status}</span> : null}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        ))}
      </div>
      {emptyState ? <div className="sbdp-scheduler__empty">No bookings for this period.</div> : null}
    </div>
  );
}

DragDropScheduler.propTypes = {
  bookings: PropTypes.arrayOf(PropTypes.object).isRequired,
  onReschedule: PropTypes.func.isRequired,
  view: PropTypes.oneOf(["day", "week"]).isRequired,
  rangeStart: PropTypes.instanceOf(Date).isRequired,
  rangeEnd: PropTypes.instanceOf(Date).isRequired,
};

export default DragDropScheduler;

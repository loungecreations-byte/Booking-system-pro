import React, { useCallback, useEffect, useMemo, useState } from "react";

import BookingList from "./BookingList";
import BookingStatsBar from "./BookingStatsBar";
import BookingDetailsPanel from "./BookingDetailsPanel";
import QuickActions from "./QuickActions";
import AddBookingModal from "./AddBookingModal";
import CalendarView from "./CalendarView";

const DEFAULT_FILTERS = {
  search: "",
  status: [],
};

const COLUMNS = [
  { id: "booking_id", label: "Booking #" },
  { id: "product", label: "Product" },
  { id: "customer", label: "Customer" },
  { id: "from", label: "From" },
  { id: "to", label: "To" },
  { id: "duration", label: "Duration" },
  { id: "people", label: "People" },
  { id: "status", label: "Status" },
  { id: "price", label: "Price" },
];

const STATUS_COLORS = {
  paid: "#16a34a",
  pending: "#eab308",
  cancelled: "#ef4444",
  completed: "#3b82f6",
};

function fetchJson(url, options = {}) {
  return fetch(url, options).then(async (response) => {
    if (!response.ok) {
      const body = await response.json().catch(() => ({}));
      throw new Error(body.message || `Request failed with status ${response.status}`);
    }

    return response.json();
  });
}

function buildQuery(filters) {
  const searchParams = new URLSearchParams();
  if (filters.search) {
    searchParams.append("search", filters.search);
  }
  if (filters.status.length > 0) {
    filters.status.forEach((status) => searchParams.append("status[]", status));
  }

  const query = searchParams.toString();

  return query ? `?${query}` : "";
}

function BookingBoardApp({ config }) {
  const restBase = config.restBase || "";
  const nonce = config.nonce || "";
  const [filters, setFilters] = useState(DEFAULT_FILTERS);
  const [bookings, setBookings] = useState([]);
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [viewMode, setViewMode] = useState(() => {
    try {
      return window.localStorage.getItem(config.storage_key || "booking_board_view_mode") || "list";
    } catch (storageError) {
      return "list";
    }
  });
  const [selectedBooking, setSelectedBooking] = useState(null);
  const [isModalOpen, setModalOpen] = useState(false);
  const [invoicingBookingId, setInvoicingBookingId] = useState(null);
  const [downloadingBookingId, setDownloadingBookingId] = useState(null);

  const headers = useMemo(() => {
    const h = { "Content-Type": "application/json" };
    if (nonce) {
      h["X-WP-Nonce"] = nonce;
    }

    return h;
  }, [nonce]);

  const loadBookings = useCallback(() => {
    setLoading(true);
    setError("");

    return fetchJson(`${restBase}/bookings${buildQuery(filters)}`, {
      method: "GET",
      headers,
      credentials: "same-origin",
    })
      .then((data) => {
        setBookings(data.items || []);
        setStats(data.stats || null);
      })
      .catch((requestError) => {
        setError(requestError.message);
      })
      .finally(() => setLoading(false));
  }, [filters, headers, restBase]);

  const lookupCustomers = useCallback((term) => {
    const query = term.trim();
    if (query.length < 2) {
      return Promise.resolve([]);
    }

    return fetchJson(`${restBase}/customers?term=${encodeURIComponent(query)}`, {
      method: "GET",
      headers,
      credentials: "same-origin",
    })
      .then((data) => data.items || [])
      .catch(() => []);
  }, [headers, restBase]);

  useEffect(() => {
    loadBookings();
  }, [loadBookings]);

  const handleFiltersChange = (next) => {
    setFilters(next);
  };

  const handleViewModeChange = (mode) => {
    setViewMode(mode);
    try {
      window.localStorage.setItem(config.storage_key || "booking_board_view_mode", mode);
    } catch (storageError) {
      // ignore
    }
  };

  const handleStatusChange = (booking, status) => {
    fetchJson(`${restBase}/bookings/update`, {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ booking_id: booking.booking_id, status }),
    })
      .then((response) => {
        setBookings((current) =>
          current.map((item) => (item.booking_id === booking.booking_id ? response.booking : item))
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

    return fetchJson(`${restBase}/bookings/manual`, {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify(payload),
    })
      .then((response) => {
        setBookings((current) => [response.booking, ...current]);
        setSelectedBooking(response.booking);
        setNotice(form.send_invoice ? "Booking saved and invoice dispatched." : "Booking saved.");
      })
      .catch((requestError) => setError(requestError.message));
  };

  const handleInvoice = (bookingId, options = {}) => {
    setError("");
    setNotice("");
    setInvoicingBookingId(bookingId);

    return fetchJson(`${restBase}/bookings/invoice`, {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ booking_id: bookingId, force: Boolean(options.force) }),
    })
      .then((response) => {
        if (response.booking) {
          setBookings((current) =>
            current.map((item) => (item.booking_id === bookingId ? response.booking : item))
          );
          setSelectedBooking(response.booking);
        }
        setNotice("Invoice request dispatched.");
      })
      .catch((requestError) => setError(requestError.message))
      .finally(() => setInvoicingBookingId(null));
  };

  const handleDownloadInvoice = (bookingId) => {
    setError("");
    setNotice("");
    setDownloadingBookingId(bookingId);

    return fetchJson(`${restBase}/bookings/invoice/pdf`, {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ booking_id: bookingId }),
    })
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

        setNotice(handled ? "Invoice PDF ready." : "Invoice data refreshed.");
      })
      .catch((requestError) => setError(requestError.message))
      .finally(() => setDownloadingBookingId(null));
  };

  const handleExport = () => {
    fetchJson(`${restBase}/export`, {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ filters }),
    })
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

  const handleReschedule = (booking) => {
    const nextDate = window.prompt("Enter new start date (YYYY-MM-DD)", booking.from.slice(0, 10));
    const nextTime = window.prompt("Enter new start time (HH:MM)", booking.from.slice(11, 16));
    if (!nextDate || !nextTime) {
      return;
    }

    fetchJson(`${restBase}/bookings/reschedule`, {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({
        booking_id: booking.booking_id,
        date_start: nextDate,
        time_start: nextTime,
      }),
    })
      .then((response) => {
        setBookings((current) =>
          current.map((item) => (item.booking_id === booking.booking_id ? response.booking : item))
        );
      })
      .catch((requestError) => setError(requestError.message));
  };

  return (
    <div className="sbdp-booking-board">
      {notice && <div className="notice notice-success"><p>{notice}</p></div>}
      {error && <div className="notice notice-error"><p>{error}</p></div>}
      <QuickActions
        onAdd={handleAddBooking}
        onExport={handleExport}
        viewMode={viewMode}
        onViewModeChange={handleViewModeChange}
        loading={loading}
      />
      <BookingStatsBar stats={stats} />
      {viewMode === "calendar" ? (
        <CalendarView bookings={bookings} onReschedule={handleReschedule} />
      ) : (
        <BookingList
          columns={COLUMNS}
          items={bookings}
          filters={filters}
          onFiltersChange={handleFiltersChange}
          onRowClick={setSelectedBooking}
          onStatusChange={handleStatusChange}
          colorMap={STATUS_COLORS}
          loading={loading}
        />
      )}
      <BookingDetailsPanel
        booking={selectedBooking}
        onClose={() => setSelectedBooking(null)}
        onInvoice={handleInvoice}
        invoicing={Boolean(selectedBooking && invoicingBookingId === selectedBooking.booking_id)}
        onDownloadInvoice={handleDownloadInvoice}
        downloading={Boolean(selectedBooking && downloadingBookingId === selectedBooking.booking_id)}
      />
      <AddBookingModal
        isOpen={isModalOpen}
        onClose={() => setModalOpen(false)}
        onSubmit={handleCreateBooking}
        onCustomerLookup={lookupCustomers}
      />
    </div>
  );
}

export default BookingBoardApp;

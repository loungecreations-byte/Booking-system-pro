import React, { useCallback, useEffect, useMemo, useState } from "react";
import FiltersBar from "./components/FiltersBar";
import BulkActionsBar from "./components/BulkActionsBar";
import BookingsTable from "./components/BookingsTable";

const DEFAULT_PAGINATION = {
  page: 1,
  per_page: 50,
  total: 0,
  total_pages: 0,
};

function normaliseBaseUrl(base) {
  if (!base) {
    return "";
  }

  return base.endsWith("/") ? base.slice(0, -1) : base;
}

function BookingsOverviewApp({ config }) {
  const defaultFilters = useMemo(() => {
    return {
      ...(config?.filters?.default || {}),
      status: config?.filters?.default?.status ?? "",
      date_from: config?.filters?.default?.date_from ?? "",
      date_to: config?.filters?.default?.date_to ?? "",
      email: config?.filters?.default?.email ?? "",
      product_id: config?.filters?.default?.product_id ?? "",
    };
  }, [config]);

  const initialPerPage =
    Number.isFinite(Number(config?.perPage)) && Number(config?.perPage) > 0
      ? Number(config.perPage)
      : DEFAULT_PAGINATION.per_page;

  const [filters, setFilters] = useState(defaultFilters);
  const [bookings, setBookings] = useState([]);
  const [pagination, setPagination] = useState({
    ...DEFAULT_PAGINATION,
    per_page: initialPerPage,
  });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [feedback, setFeedback] = useState("");
  const [selectedIds, setSelectedIds] = useState([]);
  const [products, setProducts] = useState([]);
  const [productsLoading, setProductsLoading] = useState(false);
  const [actionInFlight, setActionInFlight] = useState("");
  const [now, setNow] = useState(Date.now());

  const restBase = normaliseBaseUrl(config?.restBase);
  const statusOptions = config?.statusOptions || [];
  const statusBadges = config?.statusBadges || {};
  const bulkActions = config?.bulkActions || [];
  const i18n = config?.i18n || {};
  const perPage = pagination.per_page || initialPerPage;

  useEffect(() => {
    const interval = window.setInterval(() => {
      setNow(Date.now());
    }, 60000);

    return () => window.clearInterval(interval);
  }, []);

  const loadBookings = useCallback(
    async (pageToLoad) => {
      if (!restBase) {
        return;
      }

      setLoading(true);
      setError("");
      setFeedback("");

      const page = pageToLoad || 1;
      const params = new URLSearchParams();

      if (filters.status) {
        params.append("status", filters.status);
      }

      if (filters.date_from) {
        params.append("date_from", filters.date_from);
      }

      if (filters.date_to) {
        params.append("date_to", filters.date_to);
      }

      if (filters.email) {
        params.append("email", filters.email);
      }

      if (filters.product_id) {
        params.append("product_id", String(filters.product_id));
      }

      params.append("page", String(page));
      params.append("per_page", String(perPage));

      const url = `${restBase}/all?${params.toString()}`;

      try {
        const response = await window.fetch(url, {
          headers: {
            "X-WP-Nonce": config?.nonce || "",
          },
          credentials: "same-origin",
        });

        if (!response.ok) {
          const fallback = await response.json().catch(() => ({}));
          throw new Error(fallback?.message || `Request failed (${response.status})`);
        }

        const body = await response.json();
        const items = Array.isArray(body?.items) ? body.items : [];
        const meta = body?.pagination || {};

        setBookings(items);
        setPagination({
          page: meta.page || page,
          per_page: meta.per_page || perPage,
          total: meta.total || items.length,
          total_pages: meta.total_pages || (meta.total ? Math.max(1, Math.ceil(meta.total / perPage)) : 1),
        });
        setSelectedIds((previous) =>
          previous.filter((id) => items.some((item) => Number(item.id) === Number(id)))
        );
      } catch (fetchError) {
        setError(fetchError?.message || i18n.error || "Onbekende fout.");
      } finally {
        setLoading(false);
      }
    },
    [config?.nonce, filters, i18n.error, perPage, restBase]
  );

  const loadProducts = useCallback(async () => {
    if (!config?.productsEndpoint) {
      return;
    }

    setProductsLoading(true);

    try {
      const response = await window.fetch(config.productsEndpoint, {
        headers: {
          "X-WP-Nonce": config?.nonce || "",
        },
        credentials: "same-origin",
      });

      if (!response.ok) {
        throw new Error(`Product request failed (${response.status})`);
      }

      const payload = await response.json();
      let items = [];

      if (Array.isArray(payload?.items)) {
        items = payload.items;
      } else if (Array.isArray(payload)) {
        items = payload;
      }

      const mapped = items
        .map((item) => {
          const id = item?.id || item?.product_id || item?.value;
          const name = item?.name || item?.label || item?.title;

          if (!id || !name) {
            return null;
          }

          return {
            id,
            name,
          };
        })
        .filter(Boolean);

      setProducts(mapped);
    } catch (productError) {
      console.warn("Failed to load products", productError); // eslint-disable-line no-console
      setProducts([]);
    } finally {
      setProductsLoading(false);
    }
  }, [config?.nonce, config?.productsEndpoint]);

  useEffect(() => {
    loadProducts();
  }, [loadProducts]);

  useEffect(() => {
    setPagination((prev) => ({ ...prev, page: 1 }));
    setSelectedIds([]);
    loadBookings(1);
  }, [filters, loadBookings]);

  const handleFilterChange = useCallback(
    (partial) => {
      setFilters((previous) => ({ ...previous, ...partial }));
    },
    [setFilters]
  );

  const handleResetFilters = useCallback(() => {
    setFilters(defaultFilters);
  }, [defaultFilters]);

  const handlePageChange = useCallback(
    (nextPage) => {
      if (nextPage < 1 || (pagination.total_pages && nextPage > pagination.total_pages)) {
        return;
      }

      setPagination((prev) => ({ ...prev, page: nextPage }));
      loadBookings(nextPage);
    },
    [loadBookings, pagination.total_pages]
  );

  const handleToggleSelect = useCallback((rawId) => {
    const id = Number(rawId);
    if (Number.isNaN(id)) {
      return;
    }

    setSelectedIds((prev) => {
      const exists = prev.some((value) => Number(value) === id);
      if (exists) {
        return prev.filter((value) => Number(value) !== id);
      }

      return [...prev, id];
    });
  }, []);

  const handleToggleAll = useCallback(() => {
    if (selectedIds.length === bookings.length) {
      setSelectedIds([]);
      return;
    }

    setSelectedIds(
      bookings
        .map((item) => Number(item.id))
        .filter((value) => !Number.isNaN(value))
    );
  }, [bookings, selectedIds.length]);

  const runBulkAction = useCallback(
    async (action) => {
      if (!restBase || !action || selectedIds.length === 0) {
        return;
      }

      if (i18n.confirmAction && !window.confirm(i18n.confirmAction)) {
        return;
      }

      setActionInFlight(action);
      setError("");
      setFeedback("");

      try {
        const response = await window.fetch(`${restBase}/${action}`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": config?.nonce || "",
          },
          credentials: "same-origin",
          body: JSON.stringify({ ids: selectedIds }),
        });

        if (!response.ok) {
          const details = await response.json().catch(() => ({}));
          throw new Error(details?.message || `Action failed (${response.status})`);
        }

        setFeedback(i18n.success || "Actie voltooid.");
        setSelectedIds([]);
        loadBookings(pagination.page);
      } catch (actionError) {
        setError(actionError?.message || i18n.error || "Actie mislukt.");
      } finally {
        setActionInFlight("");
      }
    },
    [config?.nonce, i18n.confirmAction, i18n.error, i18n.success, loadBookings, pagination.page, restBase, selectedIds]
  );

  const columns = useMemo(() => {
    const defaults = {
      order_number: "Boekingnummer",
      activity: "Activiteit",
      customer: "Klantinfo",
      start: "Starttijd",
      duration: "Duur",
      people: "Personen",
      total: "Bedrag",
      status: "Status",
      extras: "Extra's",
    };

    return { ...defaults, ...(config?.columns || {}) };
  }, [config?.columns]);

  return (
    <div className="sbdp-bookings-overview-root">
      <FiltersBar
        filters={filters}
        onChange={handleFilterChange}
        onReset={handleResetFilters}
        statusOptions={statusOptions}
        products={products}
        productsLoading={productsLoading}
        i18n={i18n}
      />

      <BulkActionsBar
        selectedCount={selectedIds.length}
        bulkActions={bulkActions}
        onRun={runBulkAction}
        disabled={selectedIds.length === 0 || Boolean(actionInFlight)}
        activeAction={actionInFlight}
        i18n={i18n}
      />

      {feedback && <div className="notice notice-success"><p>{feedback}</p></div>}
      {error && <div className="notice notice-error error"><p>{error}</p></div>}

      <BookingsTable
        bookings={bookings}
        loading={loading}
        now={now}
        pagination={pagination}
        onPageChange={handlePageChange}
        selectedIds={selectedIds}
        onToggleSelect={handleToggleSelect}
        onToggleAll={handleToggleAll}
        statusBadges={statusBadges}
        columns={columns}
        i18n={i18n}
      />
    </div>
  );
}

export default BookingsOverviewApp;

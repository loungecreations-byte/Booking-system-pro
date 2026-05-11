import React from "react";

function FiltersBar({
  filters,
  onChange,
  onReset,
  statusOptions,
  products,
  productsLoading,
  i18n,
}) {
  const handleInput = (field) => (event) => {
    onChange({
      [field]: event.target.value,
    });
  };

  return (
    <div className="sbdp-bookings-overview-filters">
      <div className="field">
        <label htmlFor="sbdp-bookings-status">{i18n.statusLabel || "Status"}</label>
        <select
          id="sbdp-bookings-status"
          value={filters.status || ""}
          onChange={handleInput("status")}
        >
          {(statusOptions || []).map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      <div className="field">
        <label htmlFor="sbdp-bookings-date-from">{i18n.dateFromLabel || "Vanaf"}</label>
        <input
          id="sbdp-bookings-date-from"
          type="date"
          value={filters.date_from || ""}
          onChange={handleInput("date_from")}
        />
      </div>

      <div className="field">
        <label htmlFor="sbdp-bookings-date-to">{i18n.dateToLabel || "Tot"}</label>
        <input
          id="sbdp-bookings-date-to"
          type="date"
          value={filters.date_to || ""}
          onChange={handleInput("date_to")}
        />
      </div>

      <div className="field">
        <label htmlFor="sbdp-bookings-email">{i18n.emailLabel || "E-mail"}</label>
        <input
          id="sbdp-bookings-email"
          type="email"
          placeholder={i18n.emailPlaceholder || ""}
          value={filters.email || ""}
          onChange={handleInput("email")}
        />
      </div>

      <div className="field">
        <label htmlFor="sbdp-bookings-product">{i18n.productLabel || "Activiteit"}</label>
        <select
          id="sbdp-bookings-product"
          value={filters.product_id || ""}
          onChange={handleInput("product_id")}
        >
          <option value="">{i18n.productPlaceholder || "Alle activiteiten"}</option>
          {(products || []).map((product) => (
            <option key={product.id} value={product.id}>
              {product.name}
            </option>
          ))}
        </select>
        {productsLoading && <small>{i18n.loading || "Laden…"}</small>}
      </div>

      <div className="field">
        <button type="button" className="button" onClick={onReset}>
          {i18n.resetFilters || "Reset"}
        </button>
      </div>
    </div>
  );
}

export default FiltersBar;

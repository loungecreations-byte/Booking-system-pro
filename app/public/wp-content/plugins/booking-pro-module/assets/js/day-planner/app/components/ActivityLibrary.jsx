import React, { useMemo, useState } from "react";
import PropTypes from "prop-types";

import { minutesToTime, timeToMinutes } from "../utils/time.js";
import { computeSlotPricing, formatPrice } from "../../shared/booking.js";

export default function ActivityLibrary({
  products,
  allProducts,
  filters,
  setFilters,
  timeOptions,
  plan,
  currency,
  onAddRequest,
  dialogProduct,
  onCloseDialog,
  onConfirmAdd,
}) {
  const [selectedDay, setSelectedDay] = useState(0);
  const [startTime, setStartTime] = useState("");

  const totalParticipants = Math.max(
    1,
    parseInt(plan?.participants || plan?.participants === 0 ? plan.participants : 0, 10) ||
      parseInt(plan?.participants, 10) ||
      1
  );

  const handleDragStart = (event, product) => {
    if (!event.dataTransfer) {
      return;
    }
    if (product?.can_add_to_cart === false) {
      event.preventDefault();
      return;
    }
    const payload = JSON.stringify({ productId: product.id });
    event.dataTransfer.effectAllowed = "copy";
    event.dataTransfer.setData("application/x-sbdp-product", payload);
    event.dataTransfer.setData("text/plain", String(product.id));
  };

  return (
    <aside className="sbdp-day-planner__column">
      <header className="sbdp-day-planner__section-heading">
        <h3>Activiteiten</h3>
        <p>Kies activiteiten en voeg deze toe aan de dagindeling.</p>
      </header>

      <div className="sbdp-filter-bar">
        <input
          type="search"
          placeholder="Zoek op naam…"
          value={filters.search}
          onChange={(event) => setFilters({ search: event.target.value })}
        />
        <select
          value={filters.duration}
          onChange={(event) => setFilters({ duration: event.target.value })}
        >
          <option value="all">Alle duur</option>
          <option value="short">≤ 60 minuten</option>
          <option value="medium">61–120 minuten</option>
          <option value="long">≥ 121 minuten</option>
        </select>
      </div>

      <ul className="sbdp-activity-grid">
        {products.map((product) => (
          <li
            key={product.id}
            className="sbdp-activity"
            draggable={product.can_add_to_cart !== false}
            onDragStart={(event) => handleDragStart(event, product)}
          >
            <div className="sbdp-activity__media">
              {product.image ? (
                <img src={product.image} alt={product.name} loading="lazy" referrerPolicy="no-referrer" />
              ) : (
                <span className="sbdp-activity__media-placeholder" aria-hidden="true" />
              )}
            </div>
            <div className="sbdp-activity__details">
              <div className="sbdp-activity__header">
                <h4>{product.name}</h4>
                <p className="sbdp-activity__duration">
                  Duur: {formatDuration(product.duration?.minutes)}
                </p>
              </div>
              <div className="sbdp-activity__badges">
                <span className={`sbdp-planner-badge ${product.isArrangement || product.kind === "arrangement" ? "sbdp-planner-badge--accent" : ""}`}>
                  {product.isArrangement || product.kind === "arrangement" ? "Arrangement" : "Los item"}
                </span>
                {product.arrangement_type ? (
                  <span className={`sbdp-planner-badge ${product.arrangement_type === "fixed" ? "sbdp-planner-badge--strong" : "sbdp-planner-badge--soft"}`}>
                    {product.arrangement_type === "fixed" ? "Vast" : product.arrangement_type === "dynamic" ? "Flexibel" : "Maatwerk"}
                  </span>
                ) : null}
                {Number.isFinite(product.segment_count) && product.segment_count > 0 ? (
                  <span className="sbdp-planner-badge sbdp-planner-badge--soft">
                    {product.segment_count} onderdelen
                  </span>
                ) : null}
              </div>
              <div className="sbdp-activity__context">
                {(() => {
                  const pricing = estimateActivityPricing(product, totalParticipants);
                  return (
                    <p className="sbdp-activity__price">
                      {formatPrice(pricing.total, currency)}
                      <small className="sbdp-activity__price-note">
                        {formatPrice(pricing.perPerson, currency)} p.p.
                      </small>
                    </p>
                  );
                })()}
                {product.people?.enabled ? (
                  <p className="sbdp-activity__meta">
                    Capaciteit: {product.people.min}-{product.people.max} personen
                  </p>
                ) : null}
              </div>
              <div className="sbdp-activity__actions">
                <button
                  type="button"
                  className="ui-btn ui-btn--secondary"
                  disabled={product.can_add_to_cart === false}
                  onClick={() => {
                    if (product.can_add_to_cart === false) {
                      return;
                    }
                    setSelectedDay(0);
                    setStartTime("");
                    onAddRequest(product);
                  }}
                >
                  {product.can_add_to_cart === false ? "Preview" : "Toevoegen"}
                </button>
              </div>
            </div>
          </li>
        ))}
      </ul>

      {dialogProduct ? (
        <AddActivityDialog
          product={dialogProduct}
          plan={plan}
          timeOptions={timeOptions}
          selectedDay={selectedDay}
          setSelectedDay={setSelectedDay}
          startTime={startTime}
          setStartTime={setStartTime}
          onClose={onCloseDialog}
          onConfirm={(payload) => onConfirmAdd(dialogProduct.id, payload)}
        />
      ) : null}

      {products.length === 0 && allProducts.length > 0 ? (
        <p className="sbdp-activity-empty">Geen activiteiten gevonden voor deze filters.</p>
      ) : null}
      {products.length === 0 && allProducts.length === 0 ? (
        <p className="sbdp-activity-empty">Er zijn nog geen activiteiten beschikbaar.</p>
      ) : null}
    </aside>
  );
}

ActivityLibrary.propTypes = {
  products: PropTypes.array.isRequired,
  allProducts: PropTypes.array.isRequired,
  filters: PropTypes.object.isRequired,
  setFilters: PropTypes.func.isRequired,
  timeOptions: PropTypes.array.isRequired,
  plan: PropTypes.object.isRequired,
  currency: PropTypes.string,
  onAddRequest: PropTypes.func.isRequired,
  dialogProduct: PropTypes.object,
  onCloseDialog: PropTypes.func.isRequired,
  onConfirmAdd: PropTypes.func.isRequired,
};

ActivityLibrary.defaultProps = {
  currency: "EUR",
  dialogProduct: null,
};

function AddActivityDialog({
  product,
  plan,
  timeOptions,
  selectedDay,
  setSelectedDay,
  startTime,
  setStartTime,
  onClose,
  onConfirm,
}) {
  const handleSubmit = (event) => {
    event.preventDefault();
    onConfirm({
      dayIndex: selectedDay,
      startTime,
    });
  };

  return (
    <div className="sbdp-dialog-backdrop" role="dialog" aria-modal="true">
      <form className="sbdp-dialog" onSubmit={handleSubmit}>
        <header>
          <h4>{product.name}</h4>
          <p>Kies het tijdstip waarop deze activiteit moet beginnen.</p>
        </header>
        <div className="sbdp-dialog__body">
          <label className="sbdp-field">
            <span className="sbdp-field__label">Dag</span>
            <select
              value={selectedDay}
              onChange={(event) => setSelectedDay(parseInt(event.target.value, 10))}
            >
              {plan.days.map((day, index) => (
                <option key={day.date} value={index}>
                  Dag {index + 1} – {day.date}
                </option>
              ))}
            </select>
          </label>
            <label className="sbdp-field">
              <span className="sbdp-field__label">Starttijd</span>
            <select value={startTime} onChange={(event) => setStartTime(event.target.value)}>
              <option value="">Selecteer een tijd</option>
              {timeOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
          <p className="sbdp-dialog__meta">
            Duur: {formatDuration(product.duration?.minutes)}
            {startTime && Number.isFinite(product.duration?.minutes) && product.duration?.minutes > 0 ? (
              <>
                {" "}
                – verwacht einde {minutesToTime(timeToMinutes(startTime) + product.duration.minutes)}
              </>
            ) : (
              " – kies eerst een starttijd"
            )}
          </p>
        </div>
        <footer className="sbdp-dialog__actions">
          <button type="button" className="ui-btn ui-btn--secondary" onClick={onClose}>
            Annuleren
          </button>
          <button
            type="submit"
            className="ui-btn ui-btn--primary ui-btn--planner"
            disabled={!startTime}
          >
            Toevoegen
          </button>
        </footer>
      </form>
    </div>
  );
}

AddActivityDialog.propTypes = {
  product: PropTypes.object.isRequired,
  plan: PropTypes.object.isRequired,
  timeOptions: PropTypes.array.isRequired,
  selectedDay: PropTypes.number.isRequired,
  setSelectedDay: PropTypes.func.isRequired,
  startTime: PropTypes.string.isRequired,
  setStartTime: PropTypes.func.isRequired,
  onClose: PropTypes.func.isRequired,
  onConfirm: PropTypes.func.isRequired,
};

function formatDuration(minutes) {
  const value = Number.isFinite(minutes) ? minutes : 0;
  if (value <= 0) {
    return "duur onbekend";
  }
  if (value >= 120) {
    const hours = Math.floor(value / 60);
    const rest = value % 60;
    return rest ? `${hours}u ${rest}m` : `${hours}u`;
  }
  if (value >= 60) {
    const hours = (value / 60).toFixed(1);
    return `${hours} uur`;
  }
  return `${value} minuten`;
}

function estimateActivityPricing(product, participants) {
  const breakdown = computeSlotPricing(product?.pricing || {}, participants, {
    pricePerPerson: product?.price_pp,
    sourceProduct: product,
  });
  return {
    perPerson: breakdown.perPerson || 0,
    total: breakdown.total || 0,
  };
}

import React, { useEffect, useState } from "react";
import PropTypes from "prop-types";

const defaultAddress = {
  company: "",
  address_1: "",
  address_2: "",
  postcode: "",
  city: "",
  state: "",
  country: "",
  formatted: "",
};

const createDefaultForm = () => ({
  product: "",
  date_start: "",
  time_start: "09:00",
  date_end: "",
  time_end: "10:00",
  persons: 1,
  customer_id: "",
  customer_name: "",
  customer_email: "",
  customer_phone: "",
  customer_company: "",
  customer_billing: { ...defaultAddress },
  customer_shipping: { ...defaultAddress },
  price: "",
  status: "pending",
  send_invoice: true,
});

function AddBookingModal({ isOpen, onClose, onSubmit, onCustomerLookup }) {
  const [form, setForm] = useState(() => createDefaultForm());
  const [customerQuery, setCustomerQuery] = useState("");
  const [customerResults, setCustomerResults] = useState([]);
  const [loadingCustomers, setLoadingCustomers] = useState(false);

  useEffect(() => {
    if (!isOpen) {
      setForm(createDefaultForm());
      setCustomerQuery("");
      setCustomerResults([]);
    }
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || !onCustomerLookup) {
      return;
    }

    const term = customerQuery.trim();
    if (term.length < 2) {
      setCustomerResults([]);
      setLoadingCustomers(false);
      return;
    }

    let cancelled = false;
    setLoadingCustomers(true);
    onCustomerLookup(term)
      .then((items) => {
        if (!cancelled) {
          setCustomerResults(Array.isArray(items) ? items : []);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setCustomerResults([]);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoadingCustomers(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [customerQuery, isOpen, onCustomerLookup]);

  if (!isOpen) {
    return null;
  }

  const handleChange = (event) => {
    const { name, value, type, checked } = event.target;
    setForm((prev) => ({ ...prev, [name]: type === "checkbox" ? checked : value }));
  };

  const handleCustomerSelect = (customer) => {
    setCustomerQuery("");
    setCustomerResults([]);

    setForm((prev) => ({
      ...prev,
      customer_id: customer.id ? String(customer.id) : "",
      customer_name: customer.name || prev.customer_name,
      customer_email: customer.email || prev.customer_email,
      customer_phone: customer.phone || "",
      customer_company: customer.company || "",
      customer_billing: {
        company: customer.billing?.company || "",
        address_1: customer.billing?.address_1 || "",
        address_2: customer.billing?.address_2 || "",
        postcode: customer.billing?.postcode || "",
        city: customer.billing?.city || "",
        state: customer.billing?.state || "",
        country: customer.billing?.country || "",
        formatted: customer.billing?.formatted || "",
      },
      customer_shipping: {
        company: customer.shipping?.company || "",
        address_1: customer.shipping?.address_1 || "",
        address_2: customer.shipping?.address_2 || "",
        postcode: customer.shipping?.postcode || "",
        city: customer.shipping?.city || "",
        state: customer.shipping?.state || "",
        country: customer.shipping?.country || "",
        formatted: customer.shipping?.formatted || "",
      },
    }));
  };

  const handleCustomerClear = () => {
    setForm((prev) => ({
      ...prev,
      customer_id: "",
      customer_company: "",
      customer_phone: "",
      customer_billing: { ...defaultAddress },
      customer_shipping: { ...defaultAddress },
    }));
  };

  const handleSubmit = (event) => {
    event.preventDefault();
    onSubmit(form).then(() => {
      setForm(createDefaultForm());
      setCustomerQuery("");
      setCustomerResults([]);
      onClose();
    });
  };

  const billingFormatted = form.customer_billing?.formatted || "";
  const shippingFormatted = form.customer_shipping?.formatted || "";

  return (
    <div className="sbdp-modal">
      <div className="sbdp-modal__dialog">
        <header className="sbdp-modal__header">
          <h3>New Booking</h3>
          <button type="button" onClick={onClose}>
            &times;
          </button>
        </header>
        <form className="sbdp-modal__body" onSubmit={handleSubmit}>
          <label>
            Zoek WooCommerce klant
            <input
              type="search"
              value={customerQuery}
              onChange={(event) => setCustomerQuery(event.target.value)}
              placeholder="Naam, e-mail of bedrijf"
            />
          </label>
          {loadingCustomers && <p className="sbdp-hint">Zoeken…</p>}
          {customerResults.length > 0 && (
            <ul className="sbdp-modal__list">
              {customerResults.map((customer) => {
                const key = customer.id || customer.email || customer.name;
                return (
                  <li key={key}>
                    <button
                      type="button"
                      className="button-link"
                      onClick={() => handleCustomerSelect(customer)}
                    >
                      {customer.name} — {customer.email}
                    </button>
                  </li>
                );
              })}
            </ul>
          )}

          {(form.customer_id || form.customer_email) && (
            <div className="sbdp-modal__summary">
              <strong>Geselecteerde klant:</strong>{" "}
              {form.customer_name} ({form.customer_email})
              <button type="button" className="button-link" onClick={handleCustomerClear}>
                Verwijder selectie
              </button>
            </div>
          )}

          <label>
            Product ID
            <input name="product" value={form.product} onChange={handleChange} required />
          </label>
          <div className="sbdp-modal__grid">
            <label>
              Start Date
              <input type="date" name="date_start" value={form.date_start} onChange={handleChange} required />
            </label>
            <label>
              Start Time
              <input type="time" name="time_start" value={form.time_start} onChange={handleChange} required />
            </label>
          </div>
          <div className="sbdp-modal__grid">
            <label>
              End Date
              <input type="date" name="date_end" value={form.date_end} onChange={handleChange} />
            </label>
            <label>
              End Time
              <input type="time" name="time_end" value={form.time_end} onChange={handleChange} />
            </label>
          </div>
          <label>
            Persons
            <input
              type="number"
              min="1"
              name="persons"
              value={form.persons}
              onChange={handleChange}
              required
            />
          </label>
          <label>
            Customer Name
            <input name="customer_name" value={form.customer_name} onChange={handleChange} required />
          </label>
          <label>
            Customer Email
            <input type="email" name="customer_email" value={form.customer_email} onChange={handleChange} required />
          </label>
          <label>
            Customer Phone
            <input name="customer_phone" value={form.customer_phone} onChange={handleChange} />
          </label>
          <label>
            Company
            <input name="customer_company" value={form.customer_company} onChange={handleChange} />
          </label>

          <div className="sbdp-modal__info">
            <strong>Factuuradres</strong>
            <p>{billingFormatted || "—"}</p>
          </div>
          <div className="sbdp-modal__info">
            <strong>Afleveradres</strong>
            <p>{shippingFormatted || "—"}</p>
          </div>

          <label>
            Price
            <input type="number" step="0.01" name="price" value={form.price} onChange={handleChange} />
          </label>
          <label>
            Status
            <select name="status" value={form.status} onChange={handleChange}>
              <option value="pending">Pending</option>
              <option value="requested">Requested</option>
              <option value="captured">Captured</option>
              <option value="paid">Paid</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </label>
          <label className="sbdp-modal__checkbox">
            <input
              type="checkbox"
              name="send_invoice"
              checked={form.send_invoice}
              onChange={handleChange}
            />
            <span>Stuur direct WooCommerce factuur</span>
          </label>
          <div className="sbdp-modal__actions">
            <button type="button" onClick={onClose}>
              Cancel
            </button>
            <button type="submit">Save Booking</button>
          </div>
        </form>
      </div>
    </div>
  );
}

AddBookingModal.propTypes = {
  isOpen: PropTypes.bool.isRequired,
  onClose: PropTypes.func.isRequired,
  onSubmit: PropTypes.func.isRequired,
  onCustomerLookup: PropTypes.func,
};

AddBookingModal.defaultProps = {
  onCustomerLookup: null,
};

export default AddBookingModal;

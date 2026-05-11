import React, { useEffect, useMemo, useRef, useState } from "react";
import PropTypes from "prop-types";

import { computeEndTime, computePrice, normaliseCustomer } from "../../../bookingsboard";

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
  product_label: "",
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

const CUSTOMER_STORAGE_KEY = "sbdp_booking_board_customer_info";
const EMAIL_VALIDATION_MESSAGE = "Voer een geldig e-mailadres in.";
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function AddBookingModal({ isOpen, onClose, onSubmit, onCustomerLookup, api }) {
  const [form, setForm] = useState(() => createDefaultForm());
  const [customerQuery, setCustomerQuery] = useState("");
  const [customerResults, setCustomerResults] = useState([]);
  const [loadingCustomers, setLoadingCustomers] = useState(false);
  const [productQuery, setProductQuery] = useState("");
  const [productResults, setProductResults] = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(false);
  const [selectedProduct, setSelectedProduct] = useState(null);
  const [priceTouched, setPriceTouched] = useState(false);
  const [endTouched, setEndTouched] = useState(false);
  const [formError, setFormError] = useState("");
  const [availabilityState, setAvailabilityState] = useState({ status: "idle", message: "" });
  const [saving, setSaving] = useState(false);
  const [emailError, setEmailError] = useState("");
  const [emailTouched, setEmailTouched] = useState(false);

  const nameInputRef = useRef(null);
  const emailInputRef = useRef(null);
  const phoneInputRef = useRef(null);

  const emailErrorId = useMemo(
    () => `sbdp-email-error-${Math.random().toString(36).slice(2)}`,
    []
  );

  const productId = useMemo(() => {
    if (selectedProduct && typeof selectedProduct.id !== "undefined") {
      return parseInt(selectedProduct.id, 10);
    }

    const value = parseInt(form.product, 10);
    return Number.isFinite(value) && value > 0 ? value : null;
  }, [form.product, selectedProduct]);

  useEffect(() => {
    if (!isOpen) {
      setForm(createDefaultForm());
      setCustomerQuery("");
      setCustomerResults([]);
      setLoadingCustomers(false);
      setProductQuery("");
      setProductResults([]);
      setLoadingProducts(false);
      setSelectedProduct(null);
      setPriceTouched(false);
      setEndTouched(false);
      setFormError("");
      setAvailabilityState({ status: "idle", message: "" });
      setSaving(false);
      setEmailError("");
      setEmailTouched(false);
    }
  }, [isOpen]);

  const focusFirstEmptyCustomerField = (nextValues = form) => {
    if (typeof window === "undefined" || typeof document === "undefined") {
      return;
    }

    const candidates = [
      { value: (nextValues.customer_name || "").trim(), ref: nameInputRef },
      { value: (nextValues.customer_email || "").trim(), ref: emailInputRef },
      { value: (nextValues.customer_phone || "").trim(), ref: phoneInputRef },
    ];

    const target = candidates.find((field) => field.value === "");
    if (!target || !target.ref.current) {
      return;
    }

    const activeElement = document.activeElement;
    const activeIsCustomerField =
      activeElement === nameInputRef.current ||
      activeElement === emailInputRef.current ||
      activeElement === phoneInputRef.current;

    if (activeIsCustomerField && activeElement && activeElement !== target.ref.current) {
      const activeValue =
        typeof activeElement.value === "string" ? activeElement.value.trim() : "";
      if (activeValue !== "") {
        return;
      }
    }

    const focus = () => {
      if (target.ref.current) {
        target.ref.current.focus();
        if (typeof target.ref.current.select === "function") {
          target.ref.current.select();
        }
      }
    };

    if (typeof window.requestAnimationFrame === "function") {
      window.requestAnimationFrame(focus);
    } else {
      setTimeout(focus, 0);
    }
  };

  const validateEmailValue = (value, { force = false } = {}) => {
    const email = (value || "").trim();

    if (email === "") {
      if (force) {
        setEmailError(EMAIL_VALIDATION_MESSAGE);
      } else {
        setEmailError("");
      }
      return false;
    }

    if (!EMAIL_PATTERN.test(email)) {
      setEmailError(EMAIL_VALIDATION_MESSAGE);
      return false;
    }

    setEmailError("");
    return true;
  };

  useEffect(() => {
    if (!isOpen || typeof window === "undefined") {
      return;
    }

    try {
      const stored = window.localStorage.getItem(CUSTOMER_STORAGE_KEY);
      if (!stored) {
        return;
      }

      const parsed = JSON.parse(stored);
      if (!parsed || typeof parsed !== "object") {
        return;
      }

      setForm((prev) => {
        if (prev.customer_id) {
          return prev;
        }

        const next = { ...prev };
        let changed = false;

        if (!prev.customer_name && parsed.customer_name) {
          next.customer_name = parsed.customer_name;
          changed = true;
        }
        if (!prev.customer_email && parsed.customer_email) {
          next.customer_email = parsed.customer_email;
          changed = true;
        }
        if (!prev.customer_phone && parsed.customer_phone) {
          next.customer_phone = parsed.customer_phone;
          changed = true;
        }

        return changed ? next : prev;
      });

      if (parsed.customer_email) {
        setEmailTouched(true);
        validateEmailValue(parsed.customer_email, { force: true });
      }
    } catch (error) {
      // Ignore storage errors and fall back to fresh state.
    }
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || typeof window === "undefined") {
      return;
    }

    const payload = {
      customer_name: (form.customer_name || "").trim(),
      customer_email: (form.customer_email || "").trim(),
      customer_phone: (form.customer_phone || "").trim(),
    };

    const hasData = payload.customer_name || payload.customer_email || payload.customer_phone;

    try {
      if (hasData) {
        window.localStorage.setItem(CUSTOMER_STORAGE_KEY, JSON.stringify(payload));
      } else {
        window.localStorage.removeItem(CUSTOMER_STORAGE_KEY);
      }
    } catch (error) {
      // Ignore storage errors.
    }
  }, [form.customer_email, form.customer_name, form.customer_phone, isOpen]);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    focusFirstEmptyCustomerField({
      customer_name: form.customer_name,
      customer_email: form.customer_email,
      customer_phone: form.customer_phone,
    });
  }, [form.customer_email, form.customer_name, form.customer_phone, isOpen]);

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

  useEffect(() => {
    if (!isOpen || !api || typeof api.searchProducts !== "function") {
      setProductResults([]);
      setLoadingProducts(false);
      return;
    }

    const term = productQuery.trim();
    if (term.length < 2) {
      setProductResults([]);
      setLoadingProducts(false);
      return;
    }

    let cancelled = false;
    setLoadingProducts(true);
    api
      .searchProducts(term)
      .then((items) => {
        if (!cancelled) {
          setProductResults(Array.isArray(items) ? items : []);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setProductResults([]);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoadingProducts(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [api, isOpen, productQuery]);

  if (!isOpen) {
    return null;
  }

  const handleChange = (event) => {
    const { name, value, type, checked } = event.target;
    const nextValue = type === "checkbox" ? checked : value;

    if (name === "price") {
      setPriceTouched(true);
    }

    if (name === "date_end" || name === "time_end") {
      setEndTouched(true);
    }

    if (name === "customer_email") {
      if (!emailTouched) {
        setEmailTouched(true);
      }
      validateEmailValue(nextValue, { force: true });
    }

    setForm((prev) => ({ ...prev, [name]: nextValue }));
  };

  useEffect(() => {
    if (!selectedProduct || endTouched) {
      return;
    }

    if (!form.date_start || !form.time_start || !selectedProduct.duration_minutes) {
      return;
    }

    const nextEnd = computeEndTime({
      startDate: form.date_start,
      startTime: form.time_start,
      durationMinutes: selectedProduct.duration_minutes,
    });

    setForm((prev) => {
      const nextDate = nextEnd.date || prev.date_end;
      const nextTime = nextEnd.time || prev.time_end;

      if (prev.date_end === nextDate && prev.time_end === nextTime) {
        return prev;
      }

      return { ...prev, date_end: nextDate, time_end: nextTime };
    });
  }, [selectedProduct, form.date_start, form.time_start, endTouched]);

  useEffect(() => {
    if (!selectedProduct || priceTouched) {
      return;
    }

    const pricing = computePrice(selectedProduct, form.persons);
    const formatted = pricing.total ? pricing.total.toFixed(2) : "";

    setForm((prev) => (prev.price === formatted ? prev : { ...prev, price: formatted }));
  }, [selectedProduct, form.persons, priceTouched]);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    if (!api || typeof api.checkConflict !== "function") {
      setAvailabilityState({ status: "idle", message: "" });
      return;
    }

    if (!productId || !form.date_start || !form.time_start) {
      setAvailabilityState({ status: "idle", message: "" });
      return;
    }

    const endDate = form.date_end || form.date_start;
    const endTime = form.time_end || form.time_start;

    if (!endDate || !endTime) {
      setAvailabilityState({ status: "idle", message: "" });
      return;
    }

    let cancelled = false;
    const participants = parseInt(form.persons, 10);
    const payload = {
      product_id: productId,
      start_at: `${form.date_start} ${form.time_start}`,
      end_at: `${endDate} ${endTime}`,
      participants: Number.isFinite(participants) && participants > 0 ? participants : 1,
    };

    setAvailabilityState({ status: "checking", message: "" });

    const timer = setTimeout(() => {
      api
        .checkConflict(payload)
        .then(() => {
          if (!cancelled) {
            setAvailabilityState({
              status: "ok",
              message: "Geen conflicten gevonden.",
            });
          }
        })
        .catch((error) => {
          if (!cancelled) {
            setAvailabilityState({
              status: "error",
              message: error?.message || "Tijdslot niet beschikbaar.",
            });
          }
        });
    }, 350);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [
    api,
    form.date_end,
    form.date_start,
    form.time_end,
    form.time_start,
    form.persons,
    isOpen,
    productId,
  ]);

  const handleCustomerSelect = (rawCustomer) => {
    setCustomerQuery("");
    setCustomerResults([]);

    const customer = normaliseCustomer(rawCustomer);
    const customerEmail = customer.email || "";

    setForm((prev) => ({
      ...prev,
      customer_id: customer.id ? String(customer.id) : "",
      customer_name: customer.name || prev.customer_name,
      customer_email: customerEmail || prev.customer_email,
      customer_phone: customer.phone || "",
      customer_company: customer.company || "",
      customer_billing: { ...customer.billing },
      customer_shipping: { ...customer.shipping },
    }));

    setEmailTouched(Boolean(customerEmail));
    validateEmailValue(customerEmail, { force: Boolean(customerEmail) });
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
    setEmailTouched(false);
    setEmailError("");
  };

  const handleProductQueryChange = (event) => {
    const value = event.target.value;
    setProductQuery(value);
    setForm((prev) => ({ ...prev, product_label: value }));
  };

  const handleProductSelect = (product) => {
    setSelectedProduct(product);
    setProductQuery(product.name || "");
    setProductResults([]);
    setPriceTouched(false);
    setEndTouched(false);
    setAvailabilityState({ status: "idle", message: "" });

    setForm((prev) => {
      const next = {
        ...prev,
        product: product.id ? String(product.id) : "",
        product_label: product.name || "",
      };

      if (prev.date_start && prev.time_start && product.duration_minutes) {
        const nextEnd = computeEndTime({
          startDate: prev.date_start,
          startTime: prev.time_start,
          durationMinutes: product.duration_minutes,
        });
        next.date_end = nextEnd.date || prev.date_end;
        next.time_end = nextEnd.time || prev.time_end;
      }

      const pricing = computePrice(product, prev.persons);
      if (pricing.total) {
        next.price = pricing.total.toFixed(2);
      }

      return next;
    });
  };

  const handleProductClear = () => {
    setSelectedProduct(null);
    setProductQuery("");
    setProductResults([]);
    setPriceTouched(false);
    setEndTouched(false);
    setAvailabilityState({ status: "idle", message: "" });
    setForm((prev) => ({
      ...prev,
      product: "",
      product_label: "",
      price: "",
    }));
  };

  const handleSubmit = (event) => {
    event.preventDefault();

    if (saving) {
      return;
    }

    if (!form.product) {
      setFormError("Selecteer een product voordat je de boeking opslaat.");
      return;
    }

    if (availabilityState.status === "error") {
      setFormError(availabilityState.message || "Dit tijdslot is niet beschikbaar.");
      return;
    }

    if (availabilityState.status === "checking") {
      setFormError("Wacht tot de beschikbaarheidscontrole is afgerond.");
      return;
    }

    setEmailTouched(true);
    const emailValid = validateEmailValue(form.customer_email, { force: true });
    if (!emailValid) {
      return;
    }

    setFormError("");
    setSaving(true);

    const submitForm = {
      ...form,
      customer_name: (form.customer_name || "").trim(),
      customer_email: (form.customer_email || "").trim(),
      customer_phone: (form.customer_phone || "").trim(),
    };

    return Promise.resolve(onSubmit(submitForm))
      .then(() => {
        onClose();
      })
      .catch((submitError) => {
        if (submitError && submitError.message) {
          setFormError(submitError.message);
        } else {
          setFormError("Opslaan van de boeking is mislukt.");
        }
        throw submitError;
      })
      .finally(() => {
        setSaving(false);
      });
  };

  const billingFormatted = form.customer_billing?.formatted || "";
  const shippingFormatted = form.customer_shipping?.formatted || "";
  const availabilityClass =
    availabilityState.status === "error"
      ? "sbdp-conflict-indicator sbdp-conflict-indicator--error"
      : availabilityState.status === "ok"
        ? "sbdp-conflict-indicator sbdp-conflict-indicator--ok"
        : availabilityState.status === "checking"
          ? "sbdp-conflict-indicator sbdp-conflict-indicator--checking"
          : null;

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
          {formError && <p className="sbdp-error">{formError}</p>}
          <label>
            Zoek WooCommerce klant
            <input
              type="search"
              value={customerQuery}
              onChange={(event) => setCustomerQuery(event.target.value)}
              placeholder="Naam, e-mail of bedrijf"
            />
          </label>
          {loadingCustomers && <p className="sbdp-hint">Zoeken...</p>}
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
            Zoek product
            <input
              type="search"
              value={productQuery}
              onChange={handleProductQueryChange}
              placeholder="Naam of SKU"
              autoComplete="off"
            />
          </label>
          {loadingProducts && <p className="sbdp-hint">Producten laden...</p>}
          {productResults.length > 0 && (
            <ul className="sbdp-modal__list">
              {productResults.map((product) => {
                const preview = computePrice(product, form.persons);
                const previewPrice = Number.isFinite(preview.total)
                  ? preview.total.toFixed(2)
                  : "0.00";

                return (
                  <li key={product.id}>
                    <button
                      type="button"
                      className="button-link"
                      onClick={() => handleProductSelect(product)}
                    >
                      {product.name} - {product.duration_minutes || 0} min - EUR {previewPrice}
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
          {selectedProduct ? (
            <div className="sbdp-modal__summary">
              <strong>Geselecteerd product:</strong> {selectedProduct.name} ({selectedProduct.duration_minutes || 0} min)
              <button type="button" className="button-link" onClick={handleProductClear}>
                Wijzig
              </button>
            </div>
          ) : (
            <p className="sbdp-hint">Selecteer een product om eindtijd en prijs automatisch te vullen.</p>
          )}
          <input type="hidden" name="product" value={form.product} />
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
          {availabilityClass ? (
            <div className={availabilityClass}>
              {availabilityState.status === "checking"
                ? "Beschikbaarheid controleren..."
                : availabilityState.message}
            </div>
          ) : null}
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
            <input
              name="customer_name"
              value={form.customer_name}
              onChange={handleChange}
              ref={nameInputRef}
              required
            />
          </label>
          <label>
            Customer Email
            <input
              type="email"
              name="customer_email"
              value={form.customer_email}
              onChange={handleChange}
              ref={emailInputRef}
              required
              aria-invalid={emailError ? "true" : "false"}
              aria-describedby={emailError ? emailErrorId : undefined}
            />
            {emailError ? (
              <span className="sbdp-error" id={emailErrorId} role="alert">
                {emailError}
              </span>
            ) : null}
          </label>
          <label>
            Customer Phone
            <input
              name="customer_phone"
              value={form.customer_phone}
              onChange={handleChange}
              ref={phoneInputRef}
            />
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
            <button type="button" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button
              type="submit"
              disabled={availabilityState.status === "checking" || saving}
              aria-busy={saving ? "true" : "false"}
            >
              {saving ? "Verwerken..." : "Save Booking"}
            </button>
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
  api: PropTypes.shape({
    searchProducts: PropTypes.func,
    checkConflict: PropTypes.func,
  }),
};

AddBookingModal.defaultProps = {
  onCustomerLookup: null,
  api: null,
};

export default AddBookingModal;


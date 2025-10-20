export const BOOKING_FORM_SELECTOR = '[data-sbdp-booking-form]';

const DEFAULT_LABELS = {
  name: 'Name',
  email: 'Email',
  date: 'Date',
  time: 'Time',
  submit: 'Request Booking',
};

export function renderBookingForm(root, labels = {}) {
  if (!root) {
    return;
  }

  const text = { ...DEFAULT_LABELS, ...labels };

  root.innerHTML = `
    <form class="sbdp-booking-form">
      <label>
        <span>${text.name}</span>
        <input type="text" name="customer_name" required />
      </label>
      <label>
        <span>${text.email}</span>
        <input type="email" name="customer_email" required />
      </label>
      <label>
        <span>${text.date}</span>
        <input type="date" name="date" required />
      </label>
      <label>
        <span>${text.time}</span>
        <input type="time" name="time" required />
      </label>
      <button type="submit" class="sbdp-button">${text.submit}</button>
    </form>
  `;
}

export function hydrateBookingForms(context = document) {
  const nodes = context.querySelectorAll(BOOKING_FORM_SELECTOR);
  nodes.forEach((node) => renderBookingForm(node));
}

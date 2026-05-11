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
      <div class="sbdp-booking-form__grid">
        <label class="sbdp-booking-form__field">
          <span class="sbdp-booking-form__label">${text.name}</span>
          <input class="sbdp-booking-form__input" type="text" name="customer_name" autocomplete="name" required />
        </label>
        <label class="sbdp-booking-form__field">
          <span class="sbdp-booking-form__label">${text.email}</span>
          <input class="sbdp-booking-form__input" type="email" name="customer_email" autocomplete="email" required />
        </label>
        <label class="sbdp-booking-form__field">
          <span class="sbdp-booking-form__label">${text.date}</span>
          <input class="sbdp-booking-form__input" type="date" name="date" required />
        </label>
        <label class="sbdp-booking-form__field">
          <span class="sbdp-booking-form__label">${text.time}</span>
          <input class="sbdp-booking-form__input" type="time" name="time" required />
        </label>
      </div>
      <button type="submit" class="sbdp-button sbdp-button--primary sbdp-booking-form__submit">${text.submit}</button>
    </form>
  `;
}

export function hydrateBookingForms(context = document) {
  const nodes = context.querySelectorAll(BOOKING_FORM_SELECTOR);
  nodes.forEach((node) => renderBookingForm(node));
}

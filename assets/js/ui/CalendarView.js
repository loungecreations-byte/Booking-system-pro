const DEFAULT_OPTIONS = {
  weekStartsOn: 1,
  highlightToday: true,
};

export const CALENDAR_VIEW_SELECTOR = '[data-sbdp-calendar-view]';

export function renderCalendarView(root, options = {}) {
  if (!root) {
    return;
  }

  const settings = { ...DEFAULT_OPTIONS, ...options };
  const today = new Date();
  const title = settings.title || today.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

  root.innerHTML = `
    <section class="sbdp-calendar-view">
      <header class="sbdp-calendar-view__header">
        <h3 class="sbdp-calendar-view__title">${title}</h3>
      </header>
      <div class="sbdp-calendar-view__body">
        <p class="sbdp-calendar-view__empty">
          ${settings.emptyMessage || 'Calendar data will appear here once loaded.'}
        </p>
      </div>
    </section>
  `;

  if (settings.highlightToday) {
    root.dataset.currentDate = today.toISOString();
  }
}

export function hydrateCalendarView(context = document) {
  const nodes = context.querySelectorAll(CALENDAR_VIEW_SELECTOR);
  nodes.forEach((node) => renderCalendarView(node));
}

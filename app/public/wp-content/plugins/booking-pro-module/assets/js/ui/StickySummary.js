export const STICKY_SUMMARY_SELECTOR = '[data-sbdp-sticky-summary]';

export function renderStickySummary(root, summary = {}) {
  if (!root) {
    return;
  }

  const title = summary.title || 'Booking summary';
  const total = summary.total || '0.00';
  const currency = summary.currency || 'EUR';

  root.innerHTML = `
    <aside class="sbdp-sticky-summary">
      <h4 class="sbdp-sticky-summary__title">${title}</h4>
      <dl class="sbdp-sticky-summary__list">
        ${(summary.lines || [])
          .map(
            (line) => `
          <div class="sbdp-sticky-summary__item">
            <dt>${line.label}</dt>
            <dd>${line.value}</dd>
          </div>
        `
          )
          .join('')}
      </dl>
      <footer class="sbdp-sticky-summary__footer">
        <span class="sbdp-sticky-summary__total">${currency} ${total}</span>
      </footer>
    </aside>
  `;
}

export function hydrateStickySummaries(context = document) {
  const nodes = context.querySelectorAll(STICKY_SUMMARY_SELECTOR);
  nodes.forEach((node) => renderStickySummary(node));
}

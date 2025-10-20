export const DASHBOARD_GRID_SELECTOR = '[data-sbdp-dashboard-grid]';

export function renderDashboardGrid(root, cards = []) {
  if (!root) {
    return;
  }

  const items = cards.length
    ? cards
    : [
        { label: 'Total bookings', value: '0' },
        { label: 'Revenue', value: '0' },
        { label: 'Occupancy', value: '0%' },
      ];

  root.innerHTML = `
    <section class="sbdp-dashboard-grid">
      ${items
        .map(
          (item) => `
        <article class="sbdp-dashboard-grid__card">
          <h4 class="sbdp-dashboard-grid__label">${item.label}</h4>
          <span class="sbdp-dashboard-grid__value">${item.value}</span>
        </article>
      `
        )
        .join('')}
    </section>
  `;
}

export function hydrateDashboardGrids(context = document) {
  const nodes = context.querySelectorAll(DASHBOARD_GRID_SELECTOR);
  nodes.forEach((node) => renderDashboardGrid(node));
}

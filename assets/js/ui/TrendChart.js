export const TREND_CHART_SELECTOR = '[data-sbdp-trend-chart]';

export function renderTrendChart(root, points = []) {
  if (!root) {
    return;
  }

  const dataset = points.length
    ? points
    : [
        { label: 'Mon', value: 0 },
        { label: 'Tue', value: 0 },
        { label: 'Wed', value: 0 },
        { label: 'Thu', value: 0 },
        { label: 'Fri', value: 0 },
      ];

  const maxValue = Math.max(...dataset.map((entry) => entry.value), 1);

  root.innerHTML = `
    <section class="sbdp-trend-chart">
      <div class="sbdp-trend-chart__bars">
        ${dataset
          .map((entry) => {
            const height = Math.max(4, Math.round((entry.value / maxValue) * 100));
            return `
              <div class="sbdp-trend-chart__bar" style="--height:${height}%">
                <span class="sbdp-trend-chart__value">${entry.value}</span>
                <span class="sbdp-trend-chart__label">${entry.label}</span>
              </div>
            `;
          })
          .join('')}
      </div>
    </section>
  `;
}

export function hydrateTrendCharts(context = document) {
  const nodes = context.querySelectorAll(TREND_CHART_SELECTOR);
  nodes.forEach((node) => renderTrendChart(node));
}

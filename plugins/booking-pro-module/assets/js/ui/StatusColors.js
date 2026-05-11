export const STATUS_COLORS = {
  pending: 'var(--ui-color-warning)',
  confirmed: 'var(--ui-color-success)',
  cancelled: 'var(--ui-color-danger)',
  draft: 'var(--ui-color-primary)',
};

export function getStatusColor(status) {
  const key = String(status || '').toLowerCase();
  return STATUS_COLORS[key] || STATUS_COLORS.pending;
}

export function applyStatusColor(element, status) {
  if (!element) {
    return;
  }

  element.style.setProperty('--sbdp-status-color', getStatusColor(status));
}

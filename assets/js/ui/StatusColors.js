export const STATUS_COLORS = {
  pending: '#f0ad4e',
  confirmed: '#5cb85c',
  cancelled: '#d9534f',
  draft: '#5bc0de',
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

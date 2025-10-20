export const AVAILABILITY_BADGE_SELECTOR = '[data-sbdp-availability-badge]';

export function renderAvailabilityBadge(root, status = 'unknown') {
  if (!root) {
    return;
  }

  const badgeStatus = String(status).toLowerCase();
  root.classList.add('sbdp-availability-badge', `sbdp-availability-badge--${badgeStatus}`);
  root.textContent = labelForStatus(badgeStatus);
}

export function hydrateAvailabilityBadges(context = document) {
  const nodes = context.querySelectorAll(AVAILABILITY_BADGE_SELECTOR);
  nodes.forEach((node) => renderAvailabilityBadge(node, node.dataset.status));
}

function labelForStatus(status) {
  switch (status) {
    case 'available':
      return 'Available';
    case 'limited':
      return 'Limited';
    case 'soldout':
    case 'sold_out':
      return 'Sold out';
    default:
      return 'Checking...';
  }
}

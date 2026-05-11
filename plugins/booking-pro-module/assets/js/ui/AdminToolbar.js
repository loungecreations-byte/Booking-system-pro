export const ADMIN_TOOLBAR_SELECTOR = '[data-sbdp-admin-toolbar]';

export function renderAdminToolbar(root, actions = []) {
  if (!root) {
    return;
  }

  const items = actions.length
    ? actions
    : [
        { label: 'New booking', action: () => {} },
        { label: 'Export', action: () => {} },
      ];

  root.innerHTML = `
    <nav class="sbdp-admin-toolbar">
      ${items
        .map(
          (item, index) => `
        <button type="button" class="sbdp-admin-toolbar__button" data-index="${index}">
          ${item.label}
        </button>
      `
        )
        .join('')}
    </nav>
  `;

  root.querySelectorAll('.sbdp-admin-toolbar__button').forEach((button) => {
    button.addEventListener('click', () => {
      const index = Number(button.dataset.index || 0);
      const action = items[index] && items[index].action;
      if (typeof action === 'function') {
        action();
      }
    });
  });
}

export function hydrateAdminToolbars(context = document) {
  const nodes = context.querySelectorAll(ADMIN_TOOLBAR_SELECTOR);
  nodes.forEach((node) => renderAdminToolbar(node));
}

/* global fetch */
(function () {
  if (typeof window === 'undefined' || !window.SBDP_RESOURCE_CALENDAR) {
    return;
  }

  const config = window.SBDP_RESOURCE_CALENDAR;
  const button = document.querySelector('#sbdp-calendar-sync-now');
  const statusNode = document.querySelector('#sbdp-calendar-sync-status');

  if (!button || !config.resourceId) {
    return;
  }

  const setStatus = function (text) {
    if (statusNode) {
      statusNode.textContent = text;
    }
  };

  const handleError = function (message) {
    setStatus('Status: ' + message);
  };

  button.addEventListener('click', function () {
    button.disabled = true;
    button.textContent = 'Syncing...';
    const payload = {
      resource_id: parseInt(config.resourceId, 10) || 0,
    };

    fetch(config.restUrl + '/sync', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce || '',
      },
      body: JSON.stringify(payload),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data && data.success) {
          setStatus('Status: synced at ' + new Date().toLocaleString());
        } else {
          handleError(data && data.data && data.data.message ? data.data.message : 'Sync failed');
        }
      })
      .catch((error) => {
        handleError(error.message || 'Sync error');
      })
      .finally(() => {
        button.disabled = false;
        button.textContent = 'Sync now';
      });
  });
})();

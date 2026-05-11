(() => {
  const namespace = window.sbdpPrivateTour || {};
  const playerSettings = window.sbdpPrivateTourPlayer || {};
  const serviceWorkerUrl = playerSettings.serviceWorker || '';
  const strings = playerSettings.strings || {};
  let serviceWorkerRegistered = false;

  function registerServiceWorker() {
    if (serviceWorkerRegistered || !('serviceWorker' in navigator) || !serviceWorkerUrl) {
      return;
    }

    navigator.serviceWorker.register(serviceWorkerUrl).then(() => {
      serviceWorkerRegistered = true;
    }).catch(() => {
      // ignore registration errors
    });
  }

  function formatTime(index, total) {
    return `${index + 1}/${total}`;
  }

  function buildNav(list, stops, onSelect) {
    if (!list) {
      return;
    }

    list.innerHTML = '';
    stops.forEach((stop, index) => {
      const item = document.createElement('li');
      item.className = 'sbdp-player__stop';

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'sbdp-player__stop-button';
      button.dataset.index = String(index);
      button.textContent = stop.title || `${strings.stop || 'Stop'} ${index + 1}`;
      button.addEventListener('click', () => onSelect(index));

      item.appendChild(button);
      list.appendChild(item);
    });
  }

  function initPlayer(root) {
    const playerId = root.id;
    const config = namespace[playerId];
    if (!config || !Array.isArray(config.stops) || config.stops.length === 0) {
      return;
    }

    registerServiceWorker();

    const state = {
      index: 0,
      stops: config.stops,
    };

    const nav = root.querySelector('[data-player-nav]');
    const text = root.querySelector('[data-player-text]');
    const video = root.querySelector('[data-player-video]');
    const audio = root.querySelector('[data-player-audio]');
    const progressBar = root.querySelector('[data-player-progress-bar]');
    const prevButton = root.querySelector('[data-player-prev]');
    const nextButton = root.querySelector('[data-player-next]');
    const offlineButton = root.querySelector('[data-player-offline]');
    const mapContainer = root.querySelector('[data-player-map]');

    buildNav(nav, state.stops, (index) => selectStep(index));

    if (prevButton) {
      prevButton.addEventListener('click', () => changeStep(-1));
    }

    if (nextButton) {
      nextButton.addEventListener('click', () => changeStep(1));
    }

    if (offlineButton) {
      offlineButton.addEventListener('click', () => {
        offlineButton.disabled = true;
        offlineButton.setAttribute('aria-busy', 'true');
        if (strings.offlinePreparing) {
          offlineButton.textContent = strings.offlinePreparing;
        }

        registerServiceWorker();

        window.setTimeout(() => {
          offlineButton.removeAttribute('aria-busy');
          if (strings.offlineReady) {
            offlineButton.textContent = strings.offlineReady;
          }
        }, 1200);
      });
    }

    function selectStep(index) {
      if (typeof index !== 'number' || index < 0 || index >= state.stops.length) {
        return;
      }
      state.index = index;
      render();
    }

    function changeStep(delta) {
      const nextIndex = state.index + delta;
      if (nextIndex < 0 || nextIndex >= state.stops.length) {
        return;
      }
      state.index = nextIndex;
      render();
    }

    function renderMap(step) {
      if (!mapContainer) {
        return;
      }

      const lat = typeof step.lat === 'number' ? step.lat : null;
      const lng = typeof step.lng === 'number' ? step.lng : null;

      if (lat === null || lng === null || (lat === 0 && lng === 0)) {
        mapContainer.innerHTML = '<p class="sbdp-player__map-placeholder">' + (strings.mapUnavailable || 'Locatiegegevens niet beschikbaar.') + '</p>';
        return;
      }

      mapContainer.innerHTML = '<div class="sbdp-player__map-canvas"></div>';
      const canvas = mapContainer.querySelector('.sbdp-player__map-canvas');
      if (!canvas) {
        return;
      }

      if (window.google && window.google.maps) {
        const map = new window.google.maps.Map(canvas, {
          center: { lat, lng },
          zoom: 15,
        });
        new window.google.maps.Marker({
          position: { lat, lng },
          map,
          title: step.title || '',
        });
      } else {
        canvas.innerHTML = '<p class="sbdp-player__map-placeholder">' + (strings.mapAwaiting || 'Kaartintegratie is nog niet actief.') + '</p>';
      }
    }

    function render() {
      const stop = state.stops[state.index];
      if (!stop) {
        return;
      }

      if (nav) {
        nav.querySelectorAll('.sbdp-player__stop-button').forEach((button) => {
          if (button instanceof HTMLButtonElement) {
            const idx = Number(button.dataset.index || '0');
            button.classList.toggle('is-active', idx === state.index);
            button.setAttribute('aria-current', idx === state.index ? 'true' : 'false');
          }
        });
      }

      if (video) {
        if (stop.video) {
          video.hidden = false;
          video.src = stop.video;
          video.load();
        } else {
          video.hidden = true;
          video.removeAttribute('src');
        }
      }

      if (audio) {
        if (stop.audio) {
          audio.hidden = false;
          audio.src = stop.audio;
          audio.load();
        } else {
          audio.hidden = true;
          audio.removeAttribute('src');
        }
      }

      if (text) {
        text.innerHTML = stop.content || '';
      }

      if (progressBar) {
        const progress = ((state.index + 1) / state.stops.length) * 100;
        progressBar.style.width = `${progress}%`;
        progressBar.setAttribute('aria-valuenow', String(state.index + 1));
        progressBar.setAttribute('aria-valuemax', String(state.stops.length));
        progressBar.setAttribute('data-progress-label', formatTime(state.index, state.stops.length));
      }

      renderMap(stop);
    }

    render();
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-private-tour-player]').forEach((element) => initPlayer(element));
  });
})();

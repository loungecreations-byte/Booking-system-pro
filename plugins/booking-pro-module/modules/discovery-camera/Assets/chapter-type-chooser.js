(function () {
  'use strict';

  const config = window.ddbPhotoChapterChooser || {};
  const addSelector = '[data-private-tour-add-step]';
  const types = [
    { type: 'blank', icon: '＋', title: 'Lege interactieve stap', description: 'Begin leeg en voeg zelf meerdere modules toe.' },
    { type: 'story', icon: '📝', title: 'Verhaal', description: 'Start met een tekstmodule; voeg daarna media en opdrachten toe.' },
    { type: 'audio', icon: '🎵', title: 'Verhaal + audio', description: 'Tekst en audio alvast in de juiste volgorde.' },
    { type: 'video', icon: '🎬', title: 'Verhaal + video', description: 'Tekst en video als startopbouw.' },
    { type: 'sketchfab', icon: '🥽', title: 'Verhaal + 3D', description: 'Tekst en een veilige Sketchfab 3D-module.' },
    { type: 'quiz', icon: '❓', title: 'Quizopdracht', description: 'Verhaal, quiz en server-side beloning.' },
    { type: 'discovery', icon: '📷', title: 'AI Discovery', description: 'Verhaal, camera-opdracht en server-side beloning.' }
  ];

  function closeChooser() {
    document.querySelector('[data-ddb-chapter-chooser]')?.remove();
  }

  function chooseType(type) {
    const url = new URL(config.createUrl, window.location.origin);
    url.searchParams.set('preset', type);
    window.location.assign(url.toString());
  }

  function openChooser() {
    closeChooser();

    const overlay = document.createElement('div');
    overlay.className = 'ddb-chapter-chooser';
    overlay.dataset.ddbChapterChooser = '';
    overlay.innerHTML = `
      <section class="ddb-chapter-chooser__dialog" role="dialog" aria-modal="true" aria-labelledby="ddb-chapter-chooser-title">
        <header class="ddb-chapter-chooser__header">
          <div>
            <span class="ddb-chapter-chooser__eyebrow">TOURBUILDER</span>
            <h2 id="ddb-chapter-chooser-title">Nieuwe stap toevoegen</h2>
            <p>Kies alleen een startopbouw. In iedere stap kun je daarna meerdere modules combineren en sorteren.</p>
          </div>
          <button type="button" class="ddb-chapter-chooser__close" aria-label="Sluiten">×</button>
        </header>
        <div class="ddb-chapter-chooser__grid"></div>
      </section>`;

    const grid = overlay.querySelector('.ddb-chapter-chooser__grid');
    types.forEach(function (item) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `ddb-chapter-chooser__card${item.type === 'discovery' ? ' is-featured' : ''}`;
      button.dataset.chapterType = item.type;
      button.innerHTML = `
        <span class="ddb-chapter-chooser__icon" aria-hidden="true">${item.icon}</span>
        <strong>${item.title}</strong>
        <span>${item.description}</span>`;
      grid.appendChild(button);
    });

    overlay.addEventListener('click', function (event) {
      const typeButton = event.target.closest('[data-chapter-type]');
      if (typeButton) {
        chooseType(typeButton.dataset.chapterType);
      } else if (event.target === overlay || event.target.closest('.ddb-chapter-chooser__close')) {
        closeChooser();
      }
    });
    document.addEventListener('keydown', closeOnEscape, { once: true });
    document.body.appendChild(overlay);
    overlay.querySelector('[data-chapter-type]')?.focus();
  }

  function closeOnEscape(event) {
    if (event.key === 'Escape') closeChooser();
  }

  function getBlueprintSteps() {
    const field = document.querySelector('#sbdp_tour_blueprint');
    if (!field?.value) return [];
    try {
      return JSON.parse(field.value).steps || [];
    } catch (error) {
      return [];
    }
  }

  document.addEventListener('click', function (event) {
    const addButton = event.target.closest(addSelector);
    if (addButton) {
      event.preventDefault();
      event.stopImmediatePropagation();
      openChooser();
      return;
    }

    const editButton = event.target.closest('.sbdp-step-card [data-step-edit]');
    if (!editButton) return;
    const card = editButton.closest('[data-step-index]');
    const step = getBlueprintSteps()[Number(card?.dataset.stepIndex)];
    if (!Number(step?.id) || Number(step.id) > 2147483647) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    window.location.assign(`${config.editBaseUrl}${encodeURIComponent(step.id)}`);
  }, true);
}());

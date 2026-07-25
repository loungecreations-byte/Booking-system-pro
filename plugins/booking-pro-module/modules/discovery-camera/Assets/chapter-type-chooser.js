(function () {
  'use strict';

  const config = window.ddbPhotoChapterChooser || {};
  const addSelector = '[data-private-tour-add-step]';
  let passThrough = false;

  const types = [
    { type: 'text', icon: '📝', title: 'Verhaal', description: 'Tekst, context en storytelling.' },
    { type: 'audio', icon: '🎵', title: 'Audio', description: 'Een hoofdstuk met audiobeleving.' },
    { type: 'video', icon: '🎬', title: 'Video', description: 'Een hoofdstuk met videobeleving.' },
    { type: 'vr', icon: '🥽', title: 'AR / VR', description: 'Een augmented of virtual reality-ervaring.' },
    { type: 'game', icon: '🎮', title: 'Game', description: 'Een interactieve spelopdracht.' },
    { type: 'photo_challenge', icon: '📷', title: 'AI Photo Challenge', description: 'Camera-opdracht met AI-validatie, hints en rewards.' }
  ];

  function closeChooser() {
    document.querySelector('[data-ddb-chapter-chooser]')?.remove();
  }

  function handOffToExistingBuilder(type) {
    closeChooser();
    const addButton = document.querySelector(addSelector);
    if (!addButton) return;

    passThrough = true;
    addButton.click();
    passThrough = false;

    window.setTimeout(function () {
      document.querySelector(`.sbdp-template-card[data-template="${type}"]`)?.click();
    }, 0);
  }

  function chooseType(type) {
    if (type === 'photo_challenge') {
      window.location.assign(config.createUrl);
      return;
    }
    handOffToExistingBuilder(type);
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
            <h2 id="ddb-chapter-chooser-title">Welk hoofdstuk wil je toevoegen?</h2>
            <p>Alle hoofdstuktypen gebruiken dezelfde tourvolgorde, voortgang en publicatieflow.</p>
          </div>
          <button type="button" class="ddb-chapter-chooser__close" aria-label="Sluiten">×</button>
        </header>
        <div class="ddb-chapter-chooser__grid"></div>
      </section>`;

    const grid = overlay.querySelector('.ddb-chapter-chooser__grid');
    types.forEach(function (item) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `ddb-chapter-chooser__card${item.type === 'photo_challenge' ? ' is-featured' : ''}`;
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
    if (addButton && !passThrough) {
      event.preventDefault();
      event.stopImmediatePropagation();
      openChooser();
      return;
    }

    const editButton = event.target.closest('.sbdp-step-card [data-step-edit]');
    if (!editButton) return;
    const card = editButton.closest('[data-step-index]');
    const step = getBlueprintSteps()[Number(card?.dataset.stepIndex)];
    if (step?.type !== 'photo_challenge' || !Number(step.id)) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    window.location.assign(`${config.editBaseUrl}${encodeURIComponent(step.id)}`);
  }, true);
}());

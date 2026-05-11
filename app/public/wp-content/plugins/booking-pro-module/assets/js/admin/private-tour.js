(function () {
    var config = window.sbdpPrivateTourAdmin;
    if (!config) {
        return;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        setupBuilder(config);
        setupProductPicker(config);
    }

    function getI18nText(i18n, key, fallback) {
        if (i18n && Object.prototype.hasOwnProperty.call(i18n, key)) {
            var value = i18n[key];
            if (value !== null && value !== undefined && value !== '') {
                return String(value);
            }
        }

        return fallback;
    }

    function normaliseGamification(raw) {
        if (typeof raw === 'string') {
            return raw;
        }

        if (raw && typeof raw === 'object') {
            try {
                return JSON.stringify(raw);
            } catch (error) {
                return '';
            }
        }

        return '';
    }

    function setupBuilder(builderConfig) {
        var root = document.querySelector('[data-private-tour-builder]');
        if (!root) {
            return;
        }

        var listEl = root.querySelector('[data-builder-list]');
        var emptyEl = root.querySelector('[data-builder-empty]');
        var addButton = root.querySelector('[data-private-tour-add-step]');
        var hiddenField = document.getElementById(builderConfig.blueprintField);
        var chapterField = document.getElementById('sbdp_tour_chapter_count');
        var stepTypes = builderConfig.stepTypes || { text: 'Tekst' };
        var typeKeys = Object.keys(stepTypes);
        var blueprint = builderConfig.blueprint && typeof builderConfig.blueprint === 'object'
            ? builderConfig.blueprint
            : {};
        var initialSteps = Array.isArray(blueprint.steps) ? blueprint.steps : [];

        var state = {
            steps: initialSteps.map(function (step, index) {
                return normaliseStep(step, stepTypes, index);
            })
        };

        if (chapterField) {
            chapterField.addEventListener('input', function () {
                chapterField.setAttribute('data-manual', '1');
            });
        }

        function normaliseStep(source, types, index) {
            var step = source || {};
            var defaultType = typeKeys.length > 0 ? typeKeys[0] : 'text';
            var selectedType = typeof step.type === 'string' && Object.prototype.hasOwnProperty.call(types, step.type)
                ? step.type
                : defaultType;
            var template = step.template && typeof step.template === 'object' ? step.template : {};
            var templateId = 0;

            if (typeof step.templateId === 'number') {
                templateId = step.templateId;
            } else if (typeof step.templateId === 'string' && step.templateId !== '') {
                templateId = Number(step.templateId);
            } else if (typeof template.id === 'number') {
                templateId = template.id;
            } else if (typeof template.id === 'string' && template.id !== '') {
                templateId = Number(template.id);
            }

            if (!Number.isFinite(templateId) || templateId < 0) {
                templateId = 0;
            }

            var templateTitle = '';
            if (typeof template.title === 'string') {
                templateTitle = template.title;
            } else if (typeof step.templateTitle === 'string') {
                templateTitle = step.templateTitle;
            }

            var templateType = '';
            if (typeof template.type === 'string') {
                templateType = template.type;
            } else if (typeof step.templateType === 'string') {
                templateType = step.templateType;
            }

            var templateStatus = '';
            if (typeof template.status === 'string') {
                templateStatus = template.status;
            } else if (typeof step.templateStatus === 'string') {
                templateStatus = step.templateStatus;
            }

            var templateEditUrl = '';
            if (typeof template.editUrl === 'string') {
                templateEditUrl = template.editUrl;
            } else if (typeof step.templateEditUrl === 'string') {
                templateEditUrl = step.templateEditUrl;
            }

            return {
                id: step.id ? Number(step.id) : 0,
                number: index + 1,
                title: step.title ? String(step.title) : '',
                content: step.content ? String(step.content) : '',
                videoUrl: step.videoUrl ? String(step.videoUrl) : '',
                audioUrl: step.audioUrl
                    ? String(step.audioUrl)
                    : (step.mediaUrl ? String(step.mediaUrl) : ''),
                imageUrl: step.imageUrl ? String(step.imageUrl) : '',
                lat: normaliseCoordinate(step.lat),
                lng: normaliseCoordinate(step.lng),
                type: selectedType,
                points: step.points ? Number(step.points) : 0,
                vrAsset: step.vrAsset ? String(step.vrAsset) : '',
                templateId: templateId,
                templateTitle: templateTitle,
                templateType: templateType,
                templateStatus: templateStatus,
                templateEditUrl: templateEditUrl,
                gamification: normaliseGamification(step.gamification)
            };
        }

        function createBlankStep() {
            var defaultType = typeKeys.length > 0 ? typeKeys[0] : 'text';
            return {
                id: 0,
                number: state.steps.length + 1,
                title: '',
                content: '',
                videoUrl: '',
                audioUrl: '',
                imageUrl: '',
                lat: null,
                lng: null,
                type: defaultType,
                points: 0,
                vrAsset: '',
                templateId: 0,
                templateTitle: '',
                templateType: '',
                templateStatus: '',
                templateEditUrl: '',
                gamification: ''
            };
        }

        if (emptyEl && !emptyEl.getAttribute('data-label-initialised')) {
            emptyEl.textContent = getI18nText(builderConfig.i18n, 'emptyBuilder', emptyEl.textContent || 'Nog geen stappen. Voeg de eerste stap toe om te starten.');
            emptyEl.setAttribute('data-label-initialised', '1');
        }

        function render() {
            if (!listEl || !emptyEl) {
                return;
            }

            listEl.innerHTML = '';

            if (state.steps.length === 0) {
                emptyEl.hidden = false;
            } else {
                emptyEl.hidden = true;
            }

            state.steps.forEach(function (step, index) {
                step.number = index + 1;

                var card = document.createElement('article');
                card.className = 'sbdp-step-card';
                card.setAttribute('data-index', String(index));

                var header = document.createElement('header');
                header.className = 'sbdp-step-card__header';

                var titleGroup = document.createElement('div');
                titleGroup.className = 'sbdp-step-card__title-group';

                var numberBadge = document.createElement('span');
                numberBadge.className = 'sbdp-step-card__number';
                numberBadge.textContent = String(step.number);

                var titleInput = document.createElement('input');
                titleInput.type = 'text';
                titleInput.className = 'sbdp-step-card__title';
                titleInput.placeholder = getI18nText(builderConfig.i18n, 'contentLabel', 'Omschrijving');
                titleInput.value = step.title;
                titleInput.addEventListener('input', function (event) {
                    step.title = event.target.value;
                    updateHiddenField();
                });

                titleGroup.appendChild(numberBadge);
                titleGroup.appendChild(titleInput);

                var actions = document.createElement('div');
                actions.className = 'sbdp-step-card__actions';

                var moveUp = document.createElement('button');
                moveUp.type = 'button';
                moveUp.className = 'button button-small';
                moveUp.textContent = getI18nText(builderConfig.i18n, 'moveUp', 'Omhoog');
                moveUp.disabled = index === 0;
                moveUp.addEventListener('click', function () {
                    if (index === 0) {
                        return;
                    }
                    var removed = state.steps.splice(index, 1);
                    state.steps.splice(index - 1, 0, removed[0]);
                    render();
                });

                var moveDown = document.createElement('button');
                moveDown.type = 'button';
                moveDown.className = 'button button-small';
                moveDown.textContent = getI18nText(builderConfig.i18n, 'moveDown', 'Omlaag');
                moveDown.disabled = index === state.steps.length - 1;
                moveDown.addEventListener('click', function () {
                    if (index >= state.steps.length - 1) {
                        return;
                    }
                    var removed = state.steps.splice(index, 1);
                    state.steps.splice(index + 1, 0, removed[0]);
                    render();
                });

                var duplicate = document.createElement('button');
                duplicate.type = 'button';
                duplicate.className = 'button button-small';
                duplicate.textContent = getI18nText(builderConfig.i18n, 'duplicateStep', 'Dupliceer');
                duplicate.addEventListener('click', function () {
                    var clone = {
                        id: 0,
                        number: 0,
                        title: step.title ? step.title + ' (' + getI18nText(builderConfig.i18n, 'duplicateStep', 'kopie') + ')' : '',
                        content: step.content,
                        videoUrl: step.videoUrl,
                        audioUrl: step.audioUrl,
                        imageUrl: step.imageUrl,
                        lat: step.lat,
                        lng: step.lng,
                        type: step.type,
                        points: step.points,
                        vrAsset: step.vrAsset,
                        templateId: step.templateId,
                        templateTitle: step.templateTitle,
                        templateType: step.templateType,
                        templateStatus: step.templateStatus,
                        templateEditUrl: step.templateEditUrl,
                        gamification: step.gamification
                    };

                    state.steps.splice(index + 1, 0, clone);
                    render();
                });

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button button-small button-link-delete';
                remove.textContent = getI18nText(builderConfig.i18n, 'deleteStep', 'Verwijder');
                remove.addEventListener('click', function () {
                    state.steps.splice(index, 1);
                    render();
                });

                actions.appendChild(moveUp);
                actions.appendChild(moveDown);
                actions.appendChild(duplicate);
                actions.appendChild(remove);

                header.appendChild(titleGroup);
                header.appendChild(actions);

                var body = document.createElement('div');
                body.className = 'sbdp-step-card__body';

                var typeField = document.createElement('label');
                typeField.className = 'sbdp-step-card__field';

                var typeLabel = document.createElement('span');
                typeLabel.textContent = getI18nText(builderConfig.i18n, 'typeLabel', 'Type');

                var typeSelect = document.createElement('select');
                typeSelect.className = 'sbdp-step-card__select';

                typeKeys.forEach(function (value) {
                    var option = document.createElement('option');
                    option.value = value;
                    option.textContent = stepTypes[value];
                    if (value === step.type) {
                        option.selected = true;
                    }
                    typeSelect.appendChild(option);
                });

                typeSelect.addEventListener('change', function (event) {
                    step.type = event.target.value;
                    updateHiddenField();
                });

                typeField.appendChild(typeLabel);
                typeField.appendChild(typeSelect);

                var videoField = createInputField(
                    getI18nText(builderConfig.i18n, 'videoLabel', 'Video URL'),
                    'url',
                    step.videoUrl,
                    function (value) {
                        step.videoUrl = value;
                        updateHiddenField();
                    }
                );

                var contentField = document.createElement('label');
                contentField.className = 'sbdp-step-card__field';

                var contentLabel = document.createElement('span');
                contentLabel.textContent = getI18nText(builderConfig.i18n, 'contentLabel', 'Omschrijving');

                var contentTextarea = document.createElement('textarea');
                contentTextarea.className = 'sbdp-step-card__textarea';
                contentTextarea.rows = 5;
                contentTextarea.value = step.content;
                contentTextarea.placeholder = getI18nText(builderConfig.i18n, 'contentLabel', 'Omschrijving');
                contentTextarea.addEventListener('input', function (event) {
                    step.content = event.target.value;
                    updateHiddenField();
                });

                contentField.appendChild(contentLabel);
                contentField.appendChild(contentTextarea);

                var pointsField = createInputField(
                    getI18nText(builderConfig.i18n, 'pointsLabel', 'Punten'),
                    'number',
                    step.points,
                    function (value) {
                        step.points = value ? Number(value) : 0;
                        updateHiddenField();
                    }
                );
                var pointsInput = pointsField.querySelector('input');
                if (pointsInput) {
                    pointsInput.min = '0';
                }

                var advanced = document.createElement('details');
                advanced.className = 'sbdp-step-card__advanced';
                var summary = document.createElement('summary');
                summary.textContent = 'Geavanceerde opties';
                advanced.appendChild(summary);

                var audioField = createInputField(
                    getI18nText(builderConfig.i18n, 'audioLabel', 'Audio URL'),
                    'url',
                    step.audioUrl,
                    function (value) {
                        step.audioUrl = value;
                        updateHiddenField();
                    }
                );

                var imageField = createInputField(
                    getI18nText(builderConfig.i18n, 'imageLabel', 'Afbeelding URL'),
                    'url',
                    step.imageUrl,
                    function (value) {
                        step.imageUrl = value;
                        updateHiddenField();
                    }
                );

                var latField = createInputField(
                    'Latitude',
                    'number',
                    step.lat !== null && step.lat !== undefined ? step.lat : '',
                    function (value) {
                        var parsed = normaliseCoordinate(value);
                        step.lat = parsed;
                        updateHiddenField();
                    }
                );
                var latInput = latField.querySelector('input');
                if (latInput) {
                    latInput.step = '0.000001';
                    latInput.placeholder = '51.6880';
                }

                var lngField = createInputField(
                    'Longitude',
                    'number',
                    step.lng !== null && step.lng !== undefined ? step.lng : '',
                    function (value) {
                        var parsed = normaliseCoordinate(value);
                        step.lng = parsed;
                        updateHiddenField();
                    }
                );
                var lngInput = lngField.querySelector('input');
                if (lngInput) {
                    lngInput.step = '0.000001';
                    lngInput.placeholder = '5.3030';
                }

                var vrField = createInputField(
                    'VR/AR asset URL',
                    'url',
                    step.vrAsset,
                    function (value) {
                        step.vrAsset = value;
                        updateHiddenField();
                    }
                );

                var gamificationField = document.createElement('label');
                gamificationField.className = 'sbdp-step-card__field';

                var gamificationLabel = document.createElement('span');
                gamificationLabel.textContent = 'Gamification JSON';

                var gamificationTextarea = document.createElement('textarea');
                gamificationTextarea.className = 'sbdp-step-card__textarea';
                gamificationTextarea.rows = 3;
                gamificationTextarea.placeholder = '{"badge":"intro"}';
                gamificationTextarea.value = step.gamification;
                gamificationTextarea.addEventListener('input', function (event) {
                    step.gamification = event.target.value;
                    updateHiddenField();
                });

                gamificationField.appendChild(gamificationLabel);
                gamificationField.appendChild(gamificationTextarea);

                advanced.appendChild(audioField);
                advanced.appendChild(imageField);
                advanced.appendChild(latField);
                advanced.appendChild(lngField);
                advanced.appendChild(vrField);
                advanced.appendChild(gamificationField);

                var templateControl = createTemplatePickerField(step, contentTextarea, updateHiddenField);

                body.appendChild(typeField);
                body.appendChild(templateControl.element);
                body.appendChild(videoField);
                body.appendChild(contentField);
                body.appendChild(pointsField);
                body.appendChild(advanced);

                card.appendChild(header);
                card.appendChild(body);

                listEl.appendChild(card);

                templateControl.refresh();
            });

            updateHiddenField();
        }

        function createInputField(labelText, inputType, initialValue, onChange) {
            var wrapper = document.createElement('label');
            wrapper.className = 'sbdp-step-card__field';

            var span = document.createElement('span');
            span.textContent = labelText;

            var input = document.createElement('input');
            input.type = inputType;
            input.value = initialValue !== undefined && initialValue !== null ? initialValue : '';
            input.addEventListener('input', function (event) {
                onChange(event.target.value);
            });

            wrapper.appendChild(span);
            wrapper.appendChild(input);

            return wrapper;
        }

        function createTemplatePickerField(step, contentTextarea, onChange) {
            var templateConfig = builderConfig.templateSearch || null;
            var field = document.createElement('div');
            field.className = 'sbdp-step-card__field sbdp-step-card__field--template';

            var label = document.createElement('span');
            label.textContent = getI18nText(builderConfig.i18n, 'templateLabel', 'Elementor-template');
            field.appendChild(label);

            var summary = document.createElement('p');
            summary.className = 'sbdp-template-picker__summary';
            field.appendChild(summary);

            var helper = document.createElement('p');
            helper.className = 'description sbdp-template-picker__note';
            field.appendChild(helper);

            var controls = document.createElement('div');
            controls.className = 'sbdp-template-picker__controls';

            var selectButton = document.createElement('button');
            selectButton.type = 'button';
            selectButton.className = 'button button-secondary';
            selectButton.textContent = getI18nText(builderConfig.i18n, 'chooseTemplate', 'Kies template');
            selectButton.setAttribute('aria-expanded', 'false');
            if (!templateConfig || !templateConfig.ajaxUrl) {
                selectButton.disabled = true;
            }

            var clearButton = document.createElement('button');
            clearButton.type = 'button';
            clearButton.className = 'button button-link sbdp-template-picker__clear';
            clearButton.textContent = getI18nText(builderConfig.i18n, 'clearTemplate', 'Template ontkoppelen');
            clearButton.hidden = true;

            controls.appendChild(selectButton);
            controls.appendChild(clearButton);
            field.appendChild(controls);

            var panel = document.createElement('div');
            panel.className = 'sbdp-template-search';
            panel.hidden = true;

            var searchInput = document.createElement('input');
            searchInput.type = 'search';
            searchInput.className = 'sbdp-template-search__input';
            searchInput.placeholder = getI18nText(
                builderConfig.i18n,
                'templateSearchPlaceholder',
                'Zoek op titel of ID van een template.'
            );
            panel.appendChild(searchInput);

            var results = document.createElement('ul');
            results.className = 'sbdp-template-search__results';
            panel.appendChild(results);

            var emptyState = document.createElement('p');
            emptyState.className = 'description sbdp-template-search__empty';
            emptyState.hidden = true;
            panel.appendChild(emptyState);

            field.appendChild(panel);

            var debounceTimer = null;
            var currentRequest = null;
            var supportsAbort = typeof AbortController !== 'undefined';

            function composeTemplateMeta(item) {
                var parts = [];
                if (item.type) {
                    parts.push(item.type);
                }
                if (item.status && item.status !== 'publish') {
                    parts.push(item.status);
                }
                if (item.id) {
                    parts.push('#' + item.id);
                }
                return parts.join(' | ');
            }

            function setTemplate(item) {
                panel.hidden = true;
                selectButton.setAttribute('aria-expanded', 'false');

                if (item) {
                    step.templateId = item.id;
                    step.templateTitle = item.title || '';
                    step.templateType = item.type || '';
                    step.templateStatus = item.status || '';
                    step.templateEditUrl = item.edit || '';
                } else {
                    step.templateId = 0;
                    step.templateTitle = '';
                    step.templateType = '';
                    step.templateStatus = '';
                    step.templateEditUrl = '';
                }

                if (typeof onChange === 'function') {
                    onChange();
                }

                refresh();
            }

            function refresh() {
                if (step.templateId) {
                    summary.textContent = step.templateTitle && step.templateTitle !== ''
                        ? step.templateTitle
                        : '#' + step.templateId;
                    helper.textContent = getI18nText(
                        builderConfig.i18n,
                        'templateInUse',
                        'De inhoud van dit hoofdstuk komt uit de geselecteerde Elementor-template.'
                    );
                    clearButton.hidden = false;
                    if (contentTextarea) {
                        contentTextarea.disabled = true;
                        contentTextarea.classList.add('sbdp-step-card__textarea--disabled');
                    }
                } else {
                    summary.textContent = getI18nText(
                        builderConfig.i18n,
                        'templateEmpty',
                        'Nog geen template gekoppeld.'
                    );
                    helper.textContent = '';
                    clearButton.hidden = true;
                    if (contentTextarea) {
                        contentTextarea.disabled = false;
                        contentTextarea.classList.remove('sbdp-step-card__textarea--disabled');
                    }
                }
            }

            function togglePanel() {
                panel.hidden = !panel.hidden;
                selectButton.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');

                if (!panel.hidden) {
                    searchInput.focus();
                    performSearch(searchInput.value.trim());
                } else if (supportsAbort && currentRequest) {
                    currentRequest.abort();
                }
            }

            function performSearch(term) {
                if (!templateConfig || !templateConfig.ajaxUrl) {
                    emptyState.hidden = false;
                    emptyState.textContent = getI18nText(
                        builderConfig.i18n,
                        'templateNoResults',
                        'Geen templates gevonden. Probeer een andere zoekterm.'
                    );
                    return;
                }

                var payload = new FormData();
                payload.append('action', 'sbdp_private_tour_templates');
                payload.append('nonce', templateConfig.nonce || '');
                payload.append('search', term || '');

                var fetchOptions = {
                    method: 'POST',
                    body: payload
                };

                if (supportsAbort) {
                    if (currentRequest) {
                        currentRequest.abort();
                    }
                    currentRequest = new AbortController();
                    fetchOptions.signal = currentRequest.signal;
                }

                results.innerHTML = '';
                results.classList.add('is-loading');
                emptyState.hidden = false;
                emptyState.textContent = getI18nText(
                    builderConfig.i18n,
                    'templateLoading',
                    'Templates laden...'
                );

                fetch(templateConfig.ajaxUrl, fetchOptions)
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        var items = data && data.success && data.data && Array.isArray(data.data.items)
                            ? data.data.items
                            : [];

                        results.innerHTML = '';
                        if (items.length === 0) {
                            emptyState.hidden = false;
                            emptyState.textContent = getI18nText(
                                builderConfig.i18n,
                                'templateNoResults',
                                'Geen templates gevonden. Probeer een andere zoekterm.'
                        );
                        return;
                    }

                    emptyState.hidden = true;
                    emptyState.textContent = '';

                    items.forEach(function (item) {
                            var li = document.createElement('li');
                            li.className = 'sbdp-template-search__result';

                            var button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'button button-link sbdp-template-search__result-btn';
                            button.textContent = item.title || ('#' + item.id);
                            button.addEventListener('click', function () {
                                setTemplate(item);
                                panel.hidden = true;
                            });

                            var meta = document.createElement('span');
                            meta.className = 'sbdp-template-search__meta';
                            meta.textContent = composeTemplateMeta(item);

                            li.appendChild(button);
                            li.appendChild(meta);
                            results.appendChild(li);
                        });
                    })
                    .catch(function (error) {
                        if (supportsAbort && error.name === 'AbortError') {
                            return;
                        }
                        emptyState.hidden = false;
                        emptyState.textContent = getI18nText(
                            builderConfig.i18n,
                            'templateNoResults',
                            'Geen templates gevonden. Probeer een andere zoekterm.'
                        );
                    })
                    .finally(function () {
                        results.classList.remove('is-loading');
                        if (supportsAbort) {
                            currentRequest = null;
                        }
                    });
            }

            selectButton.addEventListener('click', function () {
                togglePanel();
            });

            clearButton.addEventListener('click', function () {
                setTemplate(null);
            });

            searchInput.addEventListener('input', function (event) {
                var term = event.target.value.trim();
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                }
                debounceTimer = setTimeout(function () {
                    performSearch(term);
                }, 250);
            });

            refresh();

            return {
                element: field,
                refresh: refresh
            };
        }

        function updateHiddenField() {
            if (!hiddenField) {
                return;
            }

            var payload = {
                steps: state.steps.map(function (step, index) {
                    return {
                        id: step.id,
                        number: index + 1,
                        title: step.title,
                        content: step.content,
                        videoUrl: step.videoUrl,
                        audioUrl: step.audioUrl,
                        imageUrl: step.imageUrl,
                        lat: step.lat,
                        lng: step.lng,
                        type: step.type,
                        points: step.points,
                        vrAsset: step.vrAsset,
                        templateId: step.templateId,
                        templateTitle: step.templateTitle,
                        templateType: step.templateType,
                        templateStatus: step.templateStatus,
                        templateEditUrl: step.templateEditUrl,
                        gamification: step.gamification
                    };
                })
            };

            hiddenField.value = JSON.stringify(payload);

            if (chapterField && chapterField.getAttribute('data-manual') !== '1') {
                chapterField.value = String(payload.steps.length);
            }
        }

        if (addButton) {
            addButton.textContent = getI18nText(builderConfig.i18n, 'addStep', addButton.textContent || 'Nieuwe stap toevoegen');
            addButton.addEventListener('click', function () {
                state.steps.push(createBlankStep());
                render();
            });
        }

        var form = root.closest('form');
        if (form) {
            form.addEventListener('submit', updateHiddenField);
        }

        render();
    }

    function setupProductPicker(productConfig) {
        var wrapper = document.querySelector('[data-private-tour-product]');
        if (!wrapper) {
            return;
        }

        var idInput = wrapper.querySelector('input[name="sbdp_tour_product_id"]');
        var toggleButton = wrapper.querySelector('[data-product-search-trigger]');
        var clearButton = wrapper.querySelector('[data-product-clear]');
        var panel = wrapper.querySelector('[data-product-panel]');
        var searchInput = wrapper.querySelector('[data-product-search]');
        var results = wrapper.querySelector('[data-product-results]');
        var emptyState = wrapper.querySelector('[data-product-empty]');
        var label = wrapper.querySelector('[data-product-label]');

        if (!idInput || !toggleButton || !panel || !searchInput || !results) {
            return;
        }

        function updateLabel(text) {
            if (!label) {
                return;
            }

            label.textContent = text && text !== ''
                ? text
                : getI18nText(productConfig.i18n, 'linkProduct', 'Koppel product');
        }

        function setProduct(item) {
            if (item && item.id) {
                idInput.value = String(item.id);
                updateLabel(item.title || '');
                if (clearButton) {
                    clearButton.hidden = false;
                }
            } else {
                idInput.value = '';
                updateLabel('');
                if (clearButton) {
                    clearButton.hidden = true;
                }
            }
        }

        var initialTitle = wrapper.getAttribute('data-product-title');
        if (initialTitle) {
            updateLabel(initialTitle);
            if (clearButton) {
                clearButton.hidden = false;
            }
        }

        var debounceTimer = null;
        var currentRequest = null;
        var supportsAbort = typeof window.AbortController === 'function';

        function performSearch(term) {
            if (supportsAbort && currentRequest) {
                currentRequest.abort();
            }

            var controller = supportsAbort ? new AbortController() : null;
            currentRequest = controller;

            var payload = new URLSearchParams();
            payload.append('action', 'sbdp_private_tour_products');
            payload.append('nonce', productConfig.nonce);
            payload.append('search', term);

            results.classList.add('is-loading');
            if (emptyState) {
                emptyState.hidden = true;
            }
            results.innerHTML = '';

            var fetchOptions = {
                method: 'POST',
                body: payload
            };

            if (controller) {
                fetchOptions.signal = controller.signal;
            }

            fetch(productConfig.ajaxUrl, fetchOptions)
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error('Request failed');
                    }

                    var items = data.data && Array.isArray(data.data.items) ? data.data.items : [];
                    results.innerHTML = '';

                    if (items.length === 0) {
                        if (emptyState) {
                            emptyState.hidden = false;
                        }
                        return;
                    }

                    if (emptyState) {
                        emptyState.hidden = true;
                    }

                    items.forEach(function (item) {
                        var li = document.createElement('li');
                        li.className = 'sbdp-product-search__result';

                        var button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'button button-link sbdp-product-search__result-btn';
                        button.textContent = item.title || ('#' + item.id);
                        button.addEventListener('click', function () {
                            setProduct(item);
                            panel.hidden = true;
                        });

                        var meta = document.createElement('span');
                        meta.className = 'sbdp-product-search__meta';
                        meta.textContent = composeProductMeta(item, productConfig.i18n);

                        li.appendChild(button);
                        li.appendChild(meta);
                        results.appendChild(li);
                    });
                })
                .catch(function (error) {
                    if (supportsAbort && error.name === 'AbortError') {
                        return;
                    }
                    if (emptyState) {
                        emptyState.hidden = false;
                        emptyState.textContent = getI18nText(productConfig.i18n, 'noResults', 'Geen producten gevonden.');
                    }
                })
                .finally(function () {
                    results.classList.remove('is-loading');
                    if (supportsAbort) {
                        currentRequest = null;
                    }
                });
        }

        toggleButton.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            if (!panel.hidden) {
                searchInput.focus();
                performSearch(searchInput.value.trim());
            }
        });

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                setProduct(null);
            });
        }

        searchInput.addEventListener('input', function (event) {
            var value = event.target.value.trim();
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }

            debounceTimer = setTimeout(function () {
                performSearch(value);
            }, 250);
        });
    }

    function composeProductMeta(item, strings) {
        var parts = [];
        var map = strings || {};

        if (item && item.type) {
            parts.push((getI18nText(map, 'typeLabel', 'Type')) + ': ' + item.type);
        }

        if (item && item.status && item.status !== 'publish') {
            parts.push((getI18nText(map, 'draftLabel', 'Concept')) + ': ' + item.status);
        }

        if (item && item.id) {
            parts.push('#' + item.id);
        }

        return parts.join(' | ');
    }
})();



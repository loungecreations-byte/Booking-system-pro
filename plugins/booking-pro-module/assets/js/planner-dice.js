(function (window, document) {
    'use strict';

    const DEFAULT_PREFERENCES = {
        pace: 'balanced',
        budget: 'regular',
    };

    const PACE_OPTIONS = [
        { value: 'relaxed', label: 'Rustig tempo' },
        { value: 'balanced', label: 'In balans' },
        { value: 'active', label: 'Energiek tempo' },
    ];

    const BUDGET_OPTIONS = [
        { value: 'budget', label: 'Budgetvriendelijk' },
        { value: 'regular', label: 'Standaard' },
        { value: 'premium', label: 'Premium' },
    ];

    const CLASSES = {
        container: 'bpm-planner__dice',
        button: 'bpm-planner__btn bpm-planner__btn--secondary bpm-planner__btn--dice',
        meta: 'bpm-planner__dice-meta',
        controls: 'bpm-planner__dice-controls',
        control: 'bpm-planner__dice-control',
        variants: 'bpm-planner__dice-variants',
        variant: 'bpm-planner__dice-variant',
        variantSelected: 'is-selected',
        variantList: 'bpm-planner__dice-variant-list',
        variantTotal: 'bpm-planner__dice-variant-total',
        variantEmpty: 'bpm-planner__dice-empty',
    };

    function ensureDiceState(planner) {
        if (!planner.dice) {
            planner.dice = {};
        }

        planner.dice.preferences = Object.assign({}, DEFAULT_PREFERENCES, planner.dice.preferences || {});
        planner.dice.variantCount = planner.dice.variantCount || 2;
        planner.dice.variants = Array.isArray(planner.dice.variants) ? planner.dice.variants : [];
        planner.dice.selectedVariantId = planner.dice.selectedVariantId || null;
        planner.dice.ui = planner.dice.ui || {};
    }

    function minutesToReadable(minutes) {
        const hrs = Math.floor(minutes / 60);
        const mins = minutes % 60;

        return `${String(hrs).padStart(2, '0')}:${String(mins).padStart(2, '0')}`;
    }

    function buildSummaryText(planner, result) {
        if (!result || !result.plan || !Array.isArray(result.plan.items)) {
            return '';
        }

        const items = result.plan.items;
        const lines = items.map((item, index) => {
            const start = item.start || minutesToReadable(index * 90 + 600);
            const end = item.end || minutesToReadable(index * 90 + 690);
            const title = item.title || `#${item.product_id}`;

            return `${start} - ${end}: ${title}`;
        });

        const total = result.totals && typeof result.totals.total !== 'undefined'
            ? result.totals.total
            : null;

        const totalText = total !== null && typeof planner.formatCurrency === 'function'
            ? planner.formatCurrency(total)
            : '';

        const header = totalText ? `Totale richtprijs ${totalText}` : 'Nieuwe planning:';

        return `${header}\n${lines.join('\n')}`;
    }

    function applyPlan(planner, result, metaElement, options) {
        if (!result || !result.plan) {
            return;
        }

        const opts = options || {};
        const plan = result.plan;

        planner.state.planId = plan.plan_id ?? null;
        planner.state.sessionId = plan.session_id ?? planner.state.sessionId ?? null;
        planner.state.date = plan.date ?? planner.state.date ?? '';
        planner.state.participants = plan.participants ?? planner.state.participants ?? 1;
        planner.state.items = Array.isArray(plan.items) ? plan.items : [];
        planner.state.totals = result.totals ?? planner.state.totals;

        if (typeof planner.render === 'function') {
            planner.render();
        } else {
            if (typeof planner.renderItems === 'function') {
                planner.renderItems();
            }
            if (typeof planner.renderTotals === 'function') {
                planner.renderTotals();
            }
        }

        if (typeof planner.updateSubmitState === 'function') {
            planner.updateSubmitState();
        }

        if (metaElement) {
            const summary = buildSummaryText(planner, result);
            if (summary) {
                metaElement.textContent = summary;
            }
        }

        if (!opts.silent && typeof planner.toast === 'function') {
            planner.toast(opts.toastMessage || 'Dobbelsteen-plan klaar!');
        }
    }

    function updateVariantSelection(planner) {
        const ui = planner.dice.ui || {};
        const container = ui.variants;
        if (!container) {
            return;
        }

        const selectedId = planner.dice.selectedVariantId;
        const cards = container.querySelectorAll('.' + CLASSES.variant);
        cards.forEach(function (card) {
            if (card.dataset.variantId === selectedId) {
                card.classList.add(CLASSES.variantSelected);
            } else {
                card.classList.remove(CLASSES.variantSelected);
            }
        });
    }

    function handleVariantSelect(planner, variant, metaElement, options) {
        if (!variant) {
            return;
        }

        planner.dice.selectedVariantId = variant.id || null;
        applyPlan(planner, variant, metaElement, options);
        updateVariantSelection(planner);
    }

    function renderVariants(planner) {
        const ui = planner.dice.ui || {};
        const container = ui.variants;
        if (!container) {
            return;
        }

        container.innerHTML = '';
        container.classList.remove('is-loading');

        const variants = Array.isArray(planner.dice.variants) ? planner.dice.variants : [];
        if (variants.length === 0) {
            const empty = document.createElement('p');
            empty.className = CLASSES.variantEmpty;
            empty.textContent = 'Nog geen voorstellen. Klik op Dobbelsteen om te starten.';
            container.appendChild(empty);
            return;
        }

        variants.forEach(function (variant) {
            const card = document.createElement('div');
            card.className = CLASSES.variant;
            card.dataset.variantId = variant.id || '';

            const heading = document.createElement('h4');
            heading.textContent = variant.label || variant.id || 'Variant';
            card.appendChild(heading);

            const list = document.createElement('ul');
            list.className = CLASSES.variantList;

            const items = Array.isArray(variant.plan && variant.plan.items) ? variant.plan.items : [];
            items.forEach(function (item, index) {
                const li = document.createElement('li');
                const start = item.start || minutesToReadable(index * 90 + 600);
                const end = item.end || minutesToReadable(index * 90 + 690);
                const title = item.title || `#${item.product_id}`;
                li.textContent = `${start} - ${end}: ${title}`;
                list.appendChild(li);
            });

            card.appendChild(list);

            if (variant.totals && typeof variant.totals.total !== 'undefined') {
                const total = document.createElement('div');
                total.className = CLASSES.variantTotal;
                if (typeof planner.formatCurrency === 'function') {
                    total.textContent = planner.formatCurrency(variant.totals.total);
                } else {
                    total.textContent = `≈ ${variant.totals.total}`;
                }
                card.appendChild(total);
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'bpm-planner__btn bpm-planner__btn--secondary';
            button.textContent = 'Gebruik deze variant';
            button.addEventListener('click', function () {
                handleVariantSelect(
                    planner,
                    variant,
                    ui.meta,
                    {
                        toastMessage: 'Variant toegepast',
                    }
                );
            });

            card.appendChild(button);
            container.appendChild(card);
        });

        updateVariantSelection(planner);
    }

    function createSelectControl(labelText, options, value, onChange) {
        const wrapper = document.createElement('div');
        wrapper.className = CLASSES.control;

        const label = document.createElement('label');
        label.textContent = labelText;

        const select = document.createElement('select');
        options.forEach(function (option) {
            const opt = document.createElement('option');
            opt.value = option.value;
            opt.textContent = option.label;
            select.appendChild(opt);
        });

        select.value = value;
        select.addEventListener('change', function (event) {
            onChange(event.target.value);
        });

        wrapper.appendChild(label);
        wrapper.appendChild(select);

        return {
            wrapper: wrapper,
            select: select,
        };
    }

    function rollDice(planner, button, ui) {
        if (typeof planner.request !== 'function') {
            return;
        }

        const metaElement = ui.meta;
        const variantsContainer = ui.variants;

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        if (metaElement) {
            metaElement.textContent = 'Dobbelsteen wordt gegooid...';
        }
        if (variantsContainer) {
            variantsContainer.classList.add('is-loading');
        }

        const currentParticipants = Number.parseInt(planner.state.participants, 10);
        if (!Number.isFinite(currentParticipants) || currentParticipants <= 0) {
            if (metaElement) {
                metaElement.textContent = 'Kies eerst het aantal personen.';
            }
            button.disabled = false;
            button.removeAttribute('aria-busy');
            if (variantsContainer) {
                variantsContainer.classList.remove('is-loading');
            }
            return;
        }

        const payload = {
            date: planner.state.date || null,
            participants: currentParticipants,
            filters: {
                query: planner.state.filter ? (planner.state.filter.query || '') : '',
                duration: planner.state.filter ? planner.state.filter.duration || '' : '',
            },
            preferences: planner.dice.preferences,
            variant_count: planner.dice.variantCount,
        };

        planner
            .request('/dice-plan', {
                method: 'POST',
                body: payload,
            })
            .then(function (result) {
                const variants = Array.isArray(result.variants) ? result.variants : [];
                planner.dice.variants = variants;
                planner.dice.selectedVariantId = null;

                renderVariants(planner);

                if (variants.length > 0) {
                    const primary = variants[0];
                    planner.dice.selectedVariantId = primary.id || null;
                    handleVariantSelect(
                        planner,
                        primary,
                        metaElement,
                        {
                            toastMessage: 'Dobbelsteen-plan klaar!',
                        }
                    );
                } else {
                    handleVariantSelect(
                        planner,
                        result,
                        metaElement,
                        {
                            toastMessage: 'Dobbelsteen-plan klaar!',
                        }
                    );
                }
            })
            .catch(function (error) {
                planner.dice.variants = [];
                renderVariants(planner);

                const message = (error && error.message) || 'Geen dobbelsteen-plan beschikbaar.';
                if (typeof planner.showError === 'function') {
                    planner.showError(message, error);
                } else {
                    window.alert(message);
                }
            })
            .finally(function () {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                if (variantsContainer) {
                    variantsContainer.classList.remove('is-loading');
                }
            });
    }

    function createUi(planner) {
        const root = document.getElementById('bpm-planner');
        if (!root) {
            return false;
        }

        const overviewHeader = root.querySelector('.bpm-planner__column--overview .bpm-planner__header');
        if (!overviewHeader || overviewHeader.querySelector('.' + CLASSES.container)) {
            return overviewHeader !== null;
        }

        ensureDiceState(planner);

        const container = document.createElement('div');
        container.className = CLASSES.container;

        const controls = document.createElement('div');
        controls.className = CLASSES.controls;

        const paceControl = createSelectControl(
            'Tempo',
            PACE_OPTIONS,
            planner.dice.preferences.pace,
            function (value) {
                planner.dice.preferences.pace = value;
            }
        );

        const budgetControl = createSelectControl(
            'Budget',
            BUDGET_OPTIONS,
            planner.dice.preferences.budget,
            function (value) {
                planner.dice.preferences.budget = value;
            }
        );

        controls.appendChild(paceControl.wrapper);
        controls.appendChild(budgetControl.wrapper);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = CLASSES.button;
        button.textContent = 'Dobbelsteen';
        button.title = 'Laat een dagplanning voor je samenstellen';

        const meta = document.createElement('div');
        meta.className = CLASSES.meta;
        meta.setAttribute('aria-live', 'polite');

        const variants = document.createElement('div');
        variants.className = CLASSES.variants;

        button.addEventListener('click', function () {
            rollDice(planner, button, planner.dice.ui);
        });

        container.appendChild(controls);
        container.appendChild(button);
        container.appendChild(meta);
        container.appendChild(variants);
        overviewHeader.appendChild(container);

        planner.dice.ui = {
            container: container,
            controls: controls,
            pace: paceControl.select,
            budget: budgetControl.select,
            button: button,
            meta: meta,
            variants: variants,
        };

        renderVariants(planner);

        return true;
    }

    function bootstrap() {
        const planner = window.BPMPlanner;
        if (!planner || !planner.state) {
            return false;
        }

        ensureDiceState(planner);
        return createUi(planner);
    }

    function init() {
        if (bootstrap()) {
            return;
        }

        let attempts = 0;
        const timer = window.setInterval(function () {
            attempts += 1;
            if (attempts > 20) {
                window.clearInterval(timer);
                return;
            }

            if (bootstrap()) {
                window.clearInterval(timer);
            }
        }, 400);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);

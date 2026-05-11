<?php

if (!defined('ABSPATH')) {
    exit;
}

add_filter('sbdp_day_planner_force_enqueue', function($force) {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($request_uri, 'plan-je-dag') !== false) {
        return true;
    }

    return $force;
}, 1);

add_filter('sbdp_day_planner_enqueue_assets', function($should_enqueue, $post) {
    unset($post);

    if (function_exists('is_page') && is_page('plan-je-dag')) {
        return true;
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($request_uri, 'plan-je-dag') !== false) {
        return true;
    }

    return $should_enqueue;
}, 1, 2);

add_action('wp_enqueue_scripts', function() {
    if (!is_page('plan-je-dag')) {
        return;
    }

    // Intentionally empty placeholder: historical planner CSS injections were retired.
}, 99999);

add_action('wp_enqueue_scripts', function() {
    if (!is_page('bossche-wiel') && !is_page('activiteiten')) {
        return;
    }

    wp_enqueue_style(
        'sbdp-page-overrides',
        WP_PLUGIN_URL . '/booking-pro-module/assets/css/page-overrides.css',
        [],
        filemtime(WP_PLUGIN_DIR . '/booking-pro-module/assets/css/page-overrides.css')
    );
}, 10000);

add_action('wp_footer', function() {
    if (!is_page('plan-je-dag')) {
        return;
    }
    ?>
    <script>
    (function () {
        const FALLBACK_TEXT = ['Kies datum', 'Kies aantal personen', 'Kies voorkeur'];

        function ensureLabels(scope) {
            const root = scope.querySelector ? scope : document;
            const labels = root.querySelectorAll('.sbdp-day-planner__hero .sbdp-field__label:not(.sbdp-label-ready)');
            if (!labels.length) {
                return;
            }

            labels.forEach((label, index) => {
                const desired = label.dataset.accessibleLabel || FALLBACK_TEXT[index] || label.textContent.trim() || 'Kies optie';
                const span = document.createElement('span');
                span.className = 'sbdp-label-text';
                span.textContent = desired;
                label.textContent = '';
                label.appendChild(span);
                label.classList.add('sbdp-label-ready');
            });
        }

        function boot() {
            ensureLabels(document);
            if (!window.MutationObserver) {
                return;
            }
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType !== 1) {
                            return;
                        }
                        if (node.matches && node.matches('.sbdp-day-planner__hero, .sbdp-day-planner__hero *')) {
                            ensureLabels(node.closest('.sbdp-day-planner__hero') || node);
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    })();
    </script>
    <?php
}, 1001);

add_action('wp_footer', function() {
    if (!is_page('plan-je-dag')) {
        return;
    }
    ?>
    <script>
    (function() {
        const SETTINGS_KEY = 'sbdpPlannerSettings';
        let prefillLocked = false;

        function getSettings() {
            if (!window.sessionStorage) {
                return null;
            }
            try {
                const raw = sessionStorage.getItem(SETTINGS_KEY);
                return raw ? JSON.parse(raw) : null;
            } catch (error) {
                console.warn('Planner settings konden niet worden gelezen:', error);
                return null;
            }
        }

        const settings = getSettings();
        if (!settings) {
            return;
        }

        function formatDate(value) {
            if (!value) {
                return '';
            }
            const parsed = new Date(value + 'T00:00:00');
            if (Number.isNaN(parsed.getTime())) {
                return value;
            }
            return parsed.toLocaleDateString('nl-NL', {
                day: 'numeric',
                month: 'short',
                year: 'numeric'
            });
        }

        function formatNumber(value) {
            const numeric = Number(value);
            if (!Number.isFinite(numeric)) {
                return '';
            }
            return numeric.toLocaleString('nl-NL', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function formatCurrency(value) {
            const numeric = Number(value);
            if (!Number.isFinite(numeric)) {
                return '';
            }
            const currency = settings.currency || 'EUR';
            try {
                return new Intl.NumberFormat('nl-NL', {
                    style: 'currency',
                    currency
                }).format(numeric);
            } catch (error) {
                return `EUR ${formatNumber(numeric)}`;
            }
        }

        function buildStep(number, label, value) {
            const wrapper = document.createElement('div');
            wrapper.className = 'sbdp-prefill-summary__step';
            wrapper.dataset.step = number;

            const badge = document.createElement('span');
            badge.className = 'sbdp-prefill-summary__badge';
            badge.textContent = number;

            const body = document.createElement('div');
            const labelEl = document.createElement('span');
            labelEl.className = 'sbdp-prefill-summary__label';
            labelEl.textContent = label;

            const valueEl = document.createElement('strong');
            valueEl.className = 'sbdp-prefill-summary__value';
            valueEl.textContent = value || '-';

            body.appendChild(labelEl);
            body.appendChild(valueEl);
            wrapper.appendChild(badge);
            wrapper.appendChild(body);
            return wrapper;
        }

        function markUserTouched() {
            prefillLocked = true;
        }

        function bindUserTouch(hero) {
            const inputs = hero.querySelectorAll('input, select');
            inputs.forEach((input) => {
                if (input.dataset.sbdpPrefillBound === 'true') {
                    return;
                }
                input.dataset.sbdpPrefillBound = 'true';
                input.addEventListener('input', markUserTouched);
                input.addEventListener('change', markUserTouched);
            });
        }

        function setInputValue(input, value) {
            if (!input || value === undefined || value === null || value === '') {
                return false;
            }
            if (prefillLocked) {
                return false;
            }
            const stringValue = String(value);
            if (input.value === stringValue && input.dataset.sbdpPrefilled === 'true') {
                return false;
            }
            input.value = stringValue;
            input.dataset.sbdpPrefilled = 'true';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }

        function syncFormValues(hero) {
            bindUserTouch(hero);
            const dateInput = hero.querySelector('input[type="date"]');
            const numberInput = hero.querySelector('input[type="number"]');
            const didDate = dateInput && setInputValue(dateInput, settings.date);
            const didNumber = numberInput && setInputValue(numberInput, settings.participants);
            return Boolean(didDate || didNumber);
        }

        function renderSummary(hero) {
            syncFormValues(hero);

            let summary = hero.querySelector('.sbdp-prefill-summary');
            if (!summary) {
                summary = document.createElement('div');
                summary.className = 'sbdp-prefill-summary';
                const grid = hero.querySelector('.sbdp-hero__grid');
                if (grid) {
                    grid.insertAdjacentElement('afterend', summary);
                } else {
                    hero.appendChild(summary);
                }
            }

            summary.innerHTML = '';

            const steps = document.createElement('div');
            steps.className = 'sbdp-prefill-summary__steps';
            const participantsCount = parseInt(settings.participants, 10) || 0;
            const peopleLabel = participantsCount === 1 ? 'persoon' : 'personen';

            steps.appendChild(buildStep('1', 'Kies datum', formatDate(settings.date) || settings.date || '-'));
            steps.appendChild(buildStep('2', 'Kies tijd', settings.time || '-'));
            const participantsValue = participantsCount ? `${participantsCount} ${peopleLabel}` : '-';
            steps.appendChild(buildStep('3', 'Aantal deelnemers', participantsValue));
            summary.appendChild(steps);

            const meta = document.createElement('div');
            meta.className = 'sbdp-prefill-summary__meta';
            const minSpan = document.createElement('span');
            minSpan.textContent = `Min: ${typeof settings.minPeople === 'number' ? settings.minPeople : '-'}`;
            const maxSpan = document.createElement('span');
            maxSpan.textContent = `Max: ${typeof settings.maxPeople === 'number' ? settings.maxPeople : '-'}`;
            meta.appendChild(minSpan);
            meta.appendChild(maxSpan);
            summary.appendChild(meta);

            const priceLine = document.createElement('div');
            priceLine.className = 'sbdp-prefill-summary__price-line';
            const perPersonText = settings.perPersonPlain || formatNumber(settings.perPersonValue);
            if (participantsCount && perPersonText) {
                priceLine.textContent = `${participantsCount} ${peopleLabel} x ${perPersonText} (p.p.p.)`;
            } else {
                priceLine.textContent = 'Prijsinformatie volgt na een selectie';
            }
            summary.appendChild(priceLine);

            const total = document.createElement('div');
            total.className = 'sbdp-prefill-summary__total';
            const totalLabel = document.createElement('span');
            totalLabel.textContent = 'Totaalprijs:';
            const totalValue = document.createElement('strong');
            totalValue.textContent = formatCurrency(settings.totalValue) || (settings.totalPlain ? `EUR ${settings.totalPlain}` : '-');
            total.appendChild(totalLabel);
            total.appendChild(totalValue);
            summary.appendChild(total);
        }

        function mount() {
            const hero = document.querySelector('.sbdp-day-planner__hero, .sbdp-hero-bar');
            if (!hero) {
                return false;
            }
            renderSummary(hero);
            if (window.MutationObserver && !hero.dataset.sbdpPrefillWatch) {
                hero.dataset.sbdpPrefillWatch = 'true';
                const observer = new MutationObserver(() => {
                    if (prefillLocked) {
                        observer.disconnect();
                        return;
                    }
                    syncFormValues(hero);
                });
                observer.observe(hero, { childList: true, subtree: true });
            }
            return true;
        }

        if (!mount() && window.MutationObserver) {
            const observer = new MutationObserver(() => {
                if (mount()) {
                    observer.disconnect();
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    })();
    </script>
    <?php
}, 1202);

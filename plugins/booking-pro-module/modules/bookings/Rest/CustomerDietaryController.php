<?php

declare(strict_types=1);

namespace BSP\Bookings\Rest;

use BSP\Bookings\Service\DietaryProfileService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

use function function_exists;
use function is_array;
use function register_rest_route;
use function add_shortcode;
use function wp_enqueue_script;
use function wp_localize_script;
use function wp_json_encode;
use function esc_attr;
use function sprintf;
use function trim;
use function wc_get_order_by_order_key;

/**
 * Public customer-facing endpoint for submitting dietary/allergen profiles.
 *
 * Auth: WooCommerce order key (no WordPress login required).
 * Route: POST /bsp/v1/customer/dietary
 */
final class CustomerDietaryController
{
    public static function registerShortcode(): void
    {
        if (function_exists('add_shortcode')) {
            add_shortcode('sbdp_dietary_intake', array(__CLASS__, 'renderShortcode'));
        }
    }

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/customer/dietary', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handleSubmit'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function renderShortcode(): string
    {
        $orderKey = isset($_GET['order_key']) ? sanitize_text_field($_GET['order_key']) : '';
        $restUrl  = function_exists('rest_url') ? esc_js(rest_url('bsp/v1/customer/dietary')) : '';

        ob_start();
        ?>
        <div id="sbdp-dietary-intake-root" class="sbdp-dietary-intake" data-order-key="<?php echo esc_attr($orderKey); ?>" data-api-url="<?php echo $restUrl; ?>">
            <div class="sbdp-dietary-intake__loading">Laden...</div>
        </div>
        <script>
        (function() {
            const root = document.getElementById('sbdp-dietary-intake-root');
            if (!root) return;

            const orderKey = root.dataset.orderKey;
            const apiUrl   = root.dataset.apiUrl;

            if (!orderKey) {
                root.innerHTML = '<div class="sbdp-dietary-intake__error">Geen ordersleutel gevonden. Gebruik de link uit uw bevestigingsmail.</div>';
                return;
            }

            const ALLERGENS = [
                { id: 'gluten',    label: 'Gluten' },
                { id: 'shellfish', label: 'Schaaldieren' },
                { id: 'eggs',      label: 'Eieren' },
                { id: 'fish',      label: 'Vis' },
                { id: 'peanuts',   label: "Pinda\u2019s" },
                { id: 'soybeans',  label: 'Soja' },
                { id: 'milk',      label: 'Melk (Lactose)' },
                { id: 'nuts',      label: 'Noten' },
                { id: 'celery',    label: 'Selderij' },
                { id: 'mustard',   label: 'Mosterd' },
                { id: 'sesame',    label: 'Sesamzaad' },
                { id: 'sulphites', label: 'Sulfieten' },
                { id: 'lupin',     label: 'Lupine' },
                { id: 'molluscs',  label: 'Weekdieren' }
            ];

            const state = {
                loading: false,
                submitted: false,
                error: null,
                profiles: [{ guest_name: '', allergens: [], severity: 'low', notes: '' }]
            };

            function esc(str) {
                const d = document.createElement('div');
                d.textContent = str;
                return d.innerHTML;
            }

            function renderProfiles() {
                return state.profiles.map(function(p, i) {
                    const allergenBoxes = ALLERGENS.map(function(a) {
                        const checked = p.allergens.indexOf(a.id) > -1 ? ' checked' : '';
                        return '<label class="sbdp-checkbox"><input type="checkbox" data-action="allergen" data-index="' + i + '" data-id="' + a.id + '"' + checked + '><span>' + esc(a.label) + '</span></label>';
                    }).join('');

                    const removeBtn = state.profiles.length > 1
                        ? '<button type="button" class="sbdp-btn-remove" data-action="remove" data-index="' + i + '">&times;</button>'
                        : '';

                    return '<div class="sbdp-dietary-card" data-index="' + i + '">'
                        + '<div class="sbdp-dietary-card__header">'
                        + '<input type="text" placeholder="Naam gast" value="' + esc(p.guest_name) + '" data-action="name" data-index="' + i + '">'
                        + removeBtn
                        + '</div>'
                        + '<div class="sbdp-dietary-card__allergens">' + allergenBoxes + '</div>'
                        + '<div class="sbdp-dietary-card__meta">'
                        + '<select data-action="severity" data-index="' + i + '">'
                        + '<option value="low"' + (p.severity === 'low' ? ' selected' : '') + '>Lichte voorkeur / Intolerantie</option>'
                        + '<option value="medium"' + (p.severity === 'medium' ? ' selected' : '') + '>Serieuze allergie</option>'
                        + '<option value="high"' + (p.severity === 'high' ? ' selected' : '') + '>Levensgevaarlijk (Anafylactisch)</option>'
                        + '</select>'
                        + '<textarea placeholder="Overige opmerkingen..." data-action="notes" data-index="' + i + '">' + esc(p.notes) + '</textarea>'
                        + '</div>'
                        + '</div>';
                }).join('');
            }

            function render() {
                if (state.submitted) {
                    root.innerHTML = '<div class="sbdp-dietary-intake__success"><h3>Bedankt!</h3><p>Uw dieetwensen zijn opgeslagen en gedeeld met onze partners.</p></div>';
                    return;
                }

                root.innerHTML = '<div class="sbdp-dietary-intake__container">'
                    + '<h2>Dieetwensen &amp; Allergieën</h2>'
                    + '<p>Geef hieronder per gast aan of er specifieke dieetwensen of allergieën zijn.</p>'
                    + (state.error ? '<div class="sbdp-dietary-intake__error">' + esc(state.error) + '</div>' : '')
                    + '<div class="sbdp-dietary-intake__profiles">' + renderProfiles() + '</div>'
                    + '<div class="sbdp-dietary-intake__actions">'
                    + '<button type="button" class="sbdp-btn-secondary" data-action="add">+ Extra Gast Toevoegen</button>'
                    + '<button type="button" class="sbdp-btn-primary" data-action="submit"' + (state.loading ? ' disabled' : '') + '>'
                    + (state.loading ? 'Versturen\u2026' : 'Opslaan &amp; Bevestigen')
                    + '</button>'
                    + '</div>'
                    + '</div>';
            }

            root.addEventListener('change', function(e) {
                const el = e.target;
                const action = el.dataset.action;
                const idx = parseInt(el.dataset.index, 10);
                if (isNaN(idx)) return;

                if (action === 'name')     { state.profiles[idx].guest_name = el.value; }
                if (action === 'severity') { state.profiles[idx].severity = el.value; }
                if (action === 'notes')    { state.profiles[idx].notes = el.value; }
                if (action === 'allergen') {
                    const id = el.dataset.id;
                    const list = state.profiles[idx].allergens;
                    const pos = list.indexOf(id);
                    if (el.checked && pos === -1) list.push(id);
                    if (!el.checked && pos > -1) list.splice(pos, 1);
                }
            });

            root.addEventListener('click', function(e) {
                const el = e.target.closest('[data-action]');
                if (!el) return;
                const action = el.dataset.action;
                const idx = parseInt(el.dataset.index, 10);

                if (action === 'add') {
                    state.profiles.push({ guest_name: '', allergens: [], severity: 'low', notes: '' });
                    render();
                }
                if (action === 'remove' && !isNaN(idx)) {
                    state.profiles.splice(idx, 1);
                    render();
                }
                if (action === 'submit') {
                    submitForm();
                }
            });

            async function submitForm() {
                state.loading = true;
                state.error = null;
                render();

                try {
                    const response = await fetch(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ order_key: orderKey, profiles: state.profiles, intake_mode: 'per_guest' })
                    });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'Er is iets misgegaan.');
                    state.submitted = true;
                } catch (err) {
                    state.error = err.message;
                } finally {
                    state.loading = false;
                    render();
                }
            }

            render();
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    public static function handleSubmit(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        $orderKey   = trim((string) ($payload['order_key'] ?? ''));
        $profiles   = $payload['profiles'] ?? [];
        $intakeMode = isset($payload['intake_mode']) ? (string) $payload['intake_mode'] : 'per_guest';

        if ($orderKey === '') {
            return new WP_Error(
                'bsp_customer_dietary_missing_key',
                'order_key is vereist.',
                array('status' => 400)
            );
        }

        if (! is_array($profiles)) {
            return new WP_Error(
                'bsp_customer_dietary_invalid',
                'profiles moet een array zijn.',
                array('status' => 400)
            );
        }

        if (! function_exists('wc_get_order_by_order_key')) {
            return new WP_Error(
                'bsp_customer_dietary_unavailable',
                'WooCommerce is niet beschikbaar.',
                array('status' => 503)
            );
        }

        $order = wc_get_order_by_order_key($orderKey);
        if (! $order) {
            return new WP_Error(
                'bsp_customer_dietary_not_found',
                'Bestelling niet gevonden.',
                array('status' => 404)
            );
        }

        try {
            $result = (new DietaryProfileService())->replaceForWooOrderId(
                (int) $order->get_id(),
                $profiles,
                $intakeMode
            );
        } catch (InvalidArgumentException $exception) {
            return new WP_Error(
                'bsp_customer_dietary_error',
                $exception->getMessage(),
                array('status' => 400)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'data'    => $result,
        ));
    }
}

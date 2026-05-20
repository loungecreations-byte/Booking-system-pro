<?php

declare(strict_types=1);

namespace BSPModule\Core\Admin;

use BSPModule\Core\Services\BookingModeService;
use WP_Post;

final class BookingModeProductMetaBox
{
    private const NONCE_ACTION = 'ddb_booking_mode_meta';
    private const NONCE_NAME = 'ddb_booking_mode_meta_nonce';
    private const ELIIO_PRODUCT_ID = 115;

    /**
     * @var array<string, string>
     */
    private const META_KEYS = array(
        '_ddb_booking_mode' => 'text',
        '_ddb_direct_booking_enabled' => 'yes_no',
        '_ddb_quote_os_enabled' => 'yes_no',
        '_ddb_supplier_confirmation_required' => 'yes_no',
        '_ddb_supplier_name' => 'text',
        '_ddb_supplier_email' => 'email',
        '_ddb_supplier_option_days' => 'int',
        '_ddb_supplier_cancel_mode' => 'cancel_mode',
    );

    public static function init(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('add_meta_boxes_product', array(self::class, 'registerMetaBox'));
        add_action('save_post_product', array(self::class, 'save'), 20, 2);
    }

    public static function registerMetaBox(WP_Post $post): void
    {
        add_meta_box(
            'ddb-booking-mode',
            __('DDB booking mode', 'sbdp'),
            array(self::class, 'render'),
            'product',
            'side',
            'default'
        );
    }

    public static function render(WP_Post $post): void
    {
        $productId = (int) $post->ID;
        $service = new BookingModeService();
        $resolved = $service->resolve($productId);
        $isEliioProduct = self::isEliioProduct($productId);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        if ($isEliioProduct) {
            echo '<p class="notice notice-warning" style="padding:8px;margin:0 0 10px;">';
            echo esc_html__('Eliio-product: direct boeken is uitgeschakeld. Aanbiederbevestiging vereist.', 'sbdp');
            echo '</p>';
        }

        self::renderSelect(
            '_ddb_booking_mode',
            __('Booking mode', 'sbdp'),
            self::modeOptions($isEliioProduct),
            (string) $resolved['bookingMode']
        );
        self::renderYesNo(
            '_ddb_direct_booking_enabled',
            __('Direct booking', 'sbdp'),
            $isEliioProduct ? 'no' : self::metaValue($productId, '_ddb_direct_booking_enabled', 'no'),
            $isEliioProduct
        );
        self::renderYesNo(
            '_ddb_quote_os_enabled',
            __('Quote OS enabled', 'sbdp'),
            $resolved['quoteOsEnabled'] ? 'yes' : 'no'
        );
        self::renderYesNo(
            '_ddb_supplier_confirmation_required',
            __('Supplier confirmation required', 'sbdp'),
            $resolved['supplierConfirmationRequired'] ? 'yes' : 'no',
            $isEliioProduct,
            $isEliioProduct ? 'yes' : null
        );
        self::renderText('_ddb_supplier_name', __('Supplier name', 'sbdp'), (string) $resolved['supplierName']);
        self::renderText('_ddb_supplier_email', __('Supplier email', 'sbdp'), (string) $resolved['supplierEmail'], 'email');
        self::renderText('_ddb_supplier_option_days', __('Supplier option days', 'sbdp'), (string) $resolved['supplierOptionDays'], 'number', '0', '30');
        self::renderSelect(
            '_ddb_supplier_cancel_mode',
            __('Supplier cancel mode', 'sbdp'),
            array(
                'manual' => __('Manual', 'sbdp'),
                'api' => __('API', 'sbdp'),
                'none' => __('None', 'sbdp'),
            ),
            (string) $resolved['supplierCancelMode']
        );

        echo '<p><strong>' . esc_html__('Resolved route', 'sbdp') . ':</strong> <code>' . esc_html((string) $resolved['routeIntent']) . '</code></p>';
        echo '<p><strong>' . esc_html__('directBookable', 'sbdp') . ':</strong> <code>' . esc_html(! empty($resolved['directBookable']) ? 'true' : 'false') . '</code></p>';
    }

    public static function save(int $postId, WP_Post $post): void
    {
        unset($post);

        if (! self::canSave($postId)) {
            return;
        }

        $isEliioProduct = self::isEliioProduct($postId);
        foreach (self::META_KEYS as $key => $type) {
            $value = self::postedValue($key, $type);

            if ($isEliioProduct) {
                if ($key === '_ddb_booking_mode' && $value !== BookingModeService::MODE_BLOCKED) {
                    $value = BookingModeService::MODE_SUPPLIER_CONFIRMATION;
                } elseif ($key === '_ddb_direct_booking_enabled') {
                    $value = 'no';
                } elseif ($key === '_ddb_supplier_confirmation_required') {
                    $value = 'yes';
                } elseif ($key === '_ddb_supplier_cancel_mode' && $value === '') {
                    $value = 'manual';
                }
            }

            if ($value === '') {
                delete_post_meta($postId, $key);
            } else {
                update_post_meta($postId, $key, $value);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function modeOptions(bool $isEliioProduct): array
    {
        $options = array(
            BookingModeService::MODE_DIRECT => __('Direct', 'sbdp'),
            BookingModeService::MODE_QUOTE => __('Quote', 'sbdp'),
            BookingModeService::MODE_SUPPLIER_CONFIRMATION => __('Supplier confirmation', 'sbdp'),
            BookingModeService::MODE_BLOCKED => __('Blocked/contact', 'sbdp'),
        );

        if ($isEliioProduct) {
            unset($options[BookingModeService::MODE_DIRECT], $options[BookingModeService::MODE_QUOTE]);
        }

        return $options;
    }

    /**
     * @param array<string, string> $options
     */
    private static function renderSelect(string $key, string $label, array $options, string $selected, bool $disabled = false): void
    {
        echo '<p><label for="' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" style="width:100%;"' . ($disabled ? ' disabled' : '') . '>';
        foreach ($options as $value => $optionLabel) {
            echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($optionLabel) . '</option>';
        }
        echo '</select></p>';
    }

    private static function renderYesNo(string $key, string $label, string $selected, bool $disabled = false, ?string $forcedValue = null): void
    {
        $effectiveValue = $forcedValue ?? ($disabled ? 'no' : $selected);
        self::renderSelect(
            $key,
            $label,
            array(
                'no' => __('No', 'sbdp'),
                'yes' => __('Yes', 'sbdp'),
            ),
            $effectiveValue,
            $disabled
        );
        if ($disabled) {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($effectiveValue) . '">';
        }
    }

    private static function renderText(string $key, string $label, string $value, string $type = 'text', string $min = '', string $max = ''): void
    {
        echo '<p><label for="' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        echo '<input id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '" style="width:100%;"';
        if ($min !== '') {
            echo ' min="' . esc_attr($min) . '"';
        }
        if ($max !== '') {
            echo ' max="' . esc_attr($max) . '"';
        }
        echo '></p>';
    }

    private static function canSave(int $postId): bool
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }
        if (! isset($_POST[self::NONCE_NAME]) || ! wp_verify_nonce((string) $_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
            return false;
        }

        return current_user_can('edit_post', $postId);
    }

    private static function isEliioProduct(int $productId): bool
    {
        $provider = strtolower(trim((string) get_post_meta($productId, '_ddb_supplier_provider', true)));
        return $productId === self::ELIIO_PRODUCT_ID || $provider === 'eliio';
    }

    private static function metaValue(int $productId, string $key, string $default = ''): string
    {
        $value = get_post_meta($productId, $key, true);
        $value = is_scalar($value) || $value === null ? trim((string) $value) : '';
        return $value !== '' ? $value : $default;
    }

    private static function postedValue(string $key, string $type): string
    {
        $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        $value = is_scalar($raw) || $raw === null ? trim((string) $raw) : '';

        if ($type === 'yes_no') {
            return $value === 'yes' ? 'yes' : 'no';
        }
        if ($type === 'email') {
            return sanitize_email($value);
        }
        if ($type === 'int') {
            return (string) max(0, min(30, (int) $value));
        }
        if ($type === 'cancel_mode') {
            return in_array($value, BookingModeService::cancelModes(), true) ? $value : 'none';
        }
        if ($key === '_ddb_booking_mode') {
            return in_array($value, BookingModeService::modes(), true) ? $value : BookingModeService::MODE_DIRECT;
        }

        return sanitize_text_field($value);
    }
}

<?php

declare(strict_types=1);

namespace BSP\Sales\Vendors;

use WP_Error;

use function __;
use function array_key_exists;
use function array_map;
use function array_values;
use function esc_url_raw;
use function in_array;
use function is_array;
use function is_scalar;
use function preg_replace;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_title;
use function strtoupper;
use function wp_unslash;

final class VendorValidator
{
    private const ALLOWED_STATUSES = array('pending', 'active', 'suspended', 'archived');
    private const ALLOWED_MARKUP_TYPES = array('percent', 'flat');

    public static function validateForCreate(array $input)
    {
        $input = wp_unslash($input);

        $sanitised = self::sanitise($input, array(), true);
        if ($sanitised instanceof WP_Error) {
            return $sanitised;
        }

        return $sanitised;
    }

    public static function validateForUpdate(array $input, array $existing)
    {
        $input = wp_unslash($input);

        $sanitised = self::sanitise($input, $existing, false);
        if ($sanitised instanceof WP_Error) {
            return $sanitised;
        }

        return array_merge($existing, $sanitised);
    }

    private static function sanitise(array $input, array $defaults, bool $requireName)
    {
        $output = array();

        if ($requireName || array_key_exists('name', $input)) {
            $name = isset($input['name']) ? sanitize_text_field($input['name']) : '';
            $name = trim($name);

            if ($name === '') {
                return new WP_Error('bsp_sales_vendor_invalid_name', __('Vendor name is required.', 'sbdp'));
            }

            $output['name'] = $name;
        }

        if ($requireName || array_key_exists('slug', $input)) {
            $base = $input['slug'] ?? ($output['name'] ?? ($defaults['name'] ?? ''));
            $slug = sanitize_title((string) $base);

            if ($slug === '') {
                return new WP_Error('bsp_sales_vendor_invalid_slug', __('Vendor slug cannot be empty.', 'sbdp'));
            }

            $output['slug'] = $slug;
        }

        if (array_key_exists('status', $input)) {
            $status = sanitize_key((string) $input['status']);
            if ($status === '') {
                $status = 'pending';
            }

            if (! in_array($status, self::ALLOWED_STATUSES, true)) {
                return new WP_Error('bsp_sales_vendor_invalid_status', __('Vendor status is not supported.', 'sbdp'));
            }

            $output['status'] = $status;
        } elseif ($requireName) {
            $output['status'] = 'pending';
        }

        if (array_key_exists('channels', $input)) {
            $channels = is_array($input['channels']) ? $input['channels'] : array($input['channels']);
            $channels = array_filter(array_map(static function ($value): string {
                return sanitize_key((string) $value);
            }, $channels));
            $output['channels'] = array_values($channels);
        }

        if (array_key_exists('capabilities', $input)) {
            $output['capabilities'] = self::deepSanitiseArray($input['capabilities']);
        }

        if (array_key_exists('metadata', $input)) {
            $output['metadata'] = self::deepSanitiseArray($input['metadata']);
        }

        if (array_key_exists('payout_terms', $input)) {
            $output['payout_terms'] = $input['payout_terms'] === '' ? null : sanitize_text_field($input['payout_terms']);
        }

        if (array_key_exists('commission_rate', $input)) {
            if ($input['commission_rate'] === '' || $input['commission_rate'] === null) {
                $output['commission_rate'] = null;
            } else {
                $rate = (float) $input['commission_rate'];
                if ($rate < 0 || $rate > 100) {
                    return new WP_Error('bsp_sales_vendor_invalid_commission', __('Commission rate must be between 0 and 100.', 'sbdp'));
                }
                $output['commission_rate'] = $rate;
            }
        }

        if (array_key_exists('contact_name', $input)) {
            $output['contact_name'] = $input['contact_name'] === '' ? null : sanitize_text_field($input['contact_name']);
        }

        if (array_key_exists('contact_email', $input)) {
            $email = $input['contact_email'] === '' ? '' : sanitize_email((string) $input['contact_email']);
            $output['contact_email'] = $email === '' ? null : $email;
        }

        if (array_key_exists('contact_phone', $input)) {
            $phone = $input['contact_phone'];
            if ($phone === '' || $phone === null) {
                $output['contact_phone'] = null;
            } else {
                $clean = preg_replace('/[^0-9+\-\s]/', '', (string) $phone);
                $output['contact_phone'] = $clean === '' ? null : $clean;
            }
        }

        if (array_key_exists('webhook_url', $input)) {
            $url = $input['webhook_url'];
            $output['webhook_url'] = $url === '' ? null : esc_url_raw((string) $url);
        }

        if (array_key_exists('pricing_currency', $input)) {
            $currency = strtoupper(preg_replace('/[^A-Z]/', '', (string) $input['pricing_currency']));
            $output['pricing_currency'] = $currency === '' ? null : $currency;
        }

        if (array_key_exists('pricing_base_rate', $input)) {
            if ($input['pricing_base_rate'] === '' || $input['pricing_base_rate'] === null) {
                $output['pricing_base_rate'] = null;
            } else {
                $baseRate = (float) $input['pricing_base_rate'];
                if ($baseRate < 0) {
                    return new WP_Error('bsp_sales_vendor_invalid_base_rate', __('Base rate must be zero or greater.', 'sbdp'));
                }
                $output['pricing_base_rate'] = $baseRate;
            }
        }

        if (array_key_exists('pricing_markup_type', $input)) {
            $type = sanitize_key((string) $input['pricing_markup_type']);
            if ($type === '') {
                $type = null;
            } elseif (! in_array($type, self::ALLOWED_MARKUP_TYPES, true)) {
                return new WP_Error('bsp_sales_vendor_invalid_markup_type', __('Markup type is not supported.', 'sbdp'));
            }
            $output['pricing_markup_type'] = $type;
        }

        if (array_key_exists('pricing_markup_value', $input)) {
            if ($input['pricing_markup_value'] === '' || $input['pricing_markup_value'] === null) {
                $output['pricing_markup_value'] = null;
            } else {
                $value = (float) $input['pricing_markup_value'];
                if ($value < 0) {
                    return new WP_Error('bsp_sales_vendor_invalid_markup_value', __('Markup value must be zero or greater.', 'sbdp'));
                }
                $output['pricing_markup_value'] = $value;
            }
        }

        $markupType = $output['pricing_markup_type'] ?? ($defaults['pricing_markup_type'] ?? null);
        if (isset($output['pricing_markup_value'])
            && $output['pricing_markup_value'] !== null
            && $markupType === 'percent'
            && $output['pricing_markup_value'] > 100) {
            return new WP_Error('bsp_sales_vendor_invalid_markup_value', __('Markup percentage must be between 0 and 100.', 'sbdp'));
        }
        return $output;
    }

    private static function deepSanitiseArray($value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $sanitised = array();
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $sanitised[$key] = self::deepSanitiseArray($item);
                continue;
            }

            if (is_scalar($item)) {
                $sanitised[$key] = sanitize_text_field((string) $item);
                continue;
            }

            $sanitised[$key] = $item;
        }

        return $sanitised;
    }
}








<?php

declare(strict_types=1);

namespace BSPModule\Core\Services;

final class BookingModeService
{
    public const MODE_DIRECT = 'direct';
    public const MODE_QUOTE = 'quote';
    public const MODE_SUPPLIER_CONFIRMATION = 'supplier_confirmation';
    public const MODE_BLOCKED = 'blocked';

    public const ROUTE_CHECKOUT = 'checkout';
    public const ROUTE_QUOTE = 'quote';
    public const ROUTE_BLOCKED = 'blocked';

    private const ELIIO_PRODUCT_ID = 115;

    /**
     * @return array{
     *   bookingMode:string,
     *   directBookable:bool,
     *   quoteOsEnabled:bool,
     *   supplierConfirmationRequired:bool,
     *   supplierName:string,
     *   supplierEmail:string,
     *   supplierOptionDays:int,
     *   supplierCancelMode:string,
     *   routeIntent:string
     * }
     */
    public function resolve(int $productId): array
    {
        $productId = max(0, $productId);
        $provider = $this->metaString($productId, '_ddb_supplier_provider');
        $isEliioProduct = $productId === self::ELIIO_PRODUCT_ID || strtolower($provider) === 'eliio';
        $rawMode = $this->normalizeMode($this->metaString($productId, '_ddb_booking_mode'));

        if ($isEliioProduct && $rawMode !== self::MODE_BLOCKED) {
            $rawMode = self::MODE_SUPPLIER_CONFIRMATION;
        }

        $directEnabled = $this->metaYesNo($productId, '_ddb_direct_booking_enabled');
        $quoteEnabled = $this->metaYesNo($productId, '_ddb_quote_os_enabled');
        $supplierRequired = $this->metaYesNo($productId, '_ddb_supplier_confirmation_required');
        $supplierName = $this->metaString($productId, '_ddb_supplier_name');
        $supplierEmail = $this->metaString($productId, '_ddb_supplier_email');
        $supplierOptionDays = $this->normalizeOptionDays($this->metaString($productId, '_ddb_supplier_option_days'));
        $supplierCancelMode = $this->normalizeCancelMode($this->metaString($productId, '_ddb_supplier_cancel_mode'));

        if ($isEliioProduct) {
            $directEnabled = false;
            $supplierRequired = true;
            $supplierName = $supplierName !== '' ? $supplierName : 'Eropuitje';
            $supplierOptionDays = $supplierOptionDays > 0 ? $supplierOptionDays : 3;
            $supplierCancelMode = $supplierCancelMode !== '' ? $supplierCancelMode : 'manual';
        }

        $directBookable = false;
        $quoteOsEnabled = $quoteEnabled;

        if ($rawMode === self::MODE_DIRECT) {
            $directBookable = $directEnabled;
        } elseif ($rawMode === self::MODE_QUOTE) {
            $quoteOsEnabled = true;
        } elseif ($rawMode === self::MODE_SUPPLIER_CONFIRMATION) {
            $quoteOsEnabled = true;
            $supplierRequired = true;
        } else {
            $quoteOsEnabled = false;
            $supplierRequired = false;
        }

        if ($rawMode === self::MODE_BLOCKED) {
            $directBookable = false;
        }

        return array(
            'bookingMode' => $rawMode,
            'directBookable' => $directBookable,
            'quoteOsEnabled' => $quoteOsEnabled,
            'supplierConfirmationRequired' => $supplierRequired,
            'supplierName' => $supplierName,
            'supplierEmail' => $supplierEmail,
            'supplierOptionDays' => $supplierOptionDays,
            'supplierCancelMode' => $supplierCancelMode !== '' ? $supplierCancelMode : 'none',
            'routeIntent' => $this->routeIntent($directBookable, $quoteOsEnabled),
        );
    }

    /**
     * @return list<string>
     */
    public static function modes(): array
    {
        return array(
            self::MODE_DIRECT,
            self::MODE_QUOTE,
            self::MODE_SUPPLIER_CONFIRMATION,
            self::MODE_BLOCKED,
        );
    }

    /**
     * @return list<string>
     */
    public static function cancelModes(): array
    {
        return array('manual', 'api', 'none');
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, self::modes(), true)) {
            return $mode;
        }

        return self::MODE_DIRECT;
    }

    private function normalizeCancelMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, self::cancelModes(), true)) {
            return $mode;
        }

        return '';
    }

    private function normalizeOptionDays(string $days): int
    {
        if ($days === '' || ! is_numeric($days)) {
            return 0;
        }

        return max(0, min(30, (int) $days));
    }

    private function routeIntent(bool $directBookable, bool $quoteOsEnabled): string
    {
        if ($directBookable) {
            return self::ROUTE_CHECKOUT;
        }

        return $quoteOsEnabled ? self::ROUTE_QUOTE : self::ROUTE_BLOCKED;
    }

    private function metaString(int $productId, string $key): string
    {
        if ($productId <= 0 || ! function_exists('get_post_meta')) {
            return '';
        }

        $value = get_post_meta($productId, $key, true);
        return is_scalar($value) || $value === null ? trim((string) $value) : '';
    }

    private function metaYesNo(int $productId, string $key): bool
    {
        return strtolower($this->metaString($productId, $key)) === 'yes';
    }
}

<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Service;

use BSP\Sales\Vendors\VendorRepository;
use BSP\Sales\Vendors\VendorService;
use BSP\VendorPortal\Service\VendorDashboardService;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use function absint;
use function apply_filters;
use function current_time;
use function is_array;
use function sanitize_text_field;
use function sprintf;
use function strlen;
use function substr;
use function wp_generate_password;
use function wp_hash_password;

final class VendorPortalAdminService
{
    private VendorRepository $repository;

    public function __construct(?VendorRepository $repository = null)
    {
        $this->repository = $repository ?? new VendorRepository();
        VendorService::init();
    }

    /**
     * @return array{vendors:array<int, array<string,mixed>>,has_more:bool,page:int,per_page:int}
     */
    public function listVendors(string $search = '', string $status = '', int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        $args = array(
            'limit'  => $perPage + 1,
            'offset' => ($page - 1) * $perPage,
        );

        if ($search !== '') {
            $args['search'] = $search;
        }

        if ($status !== '' && $status !== 'all') {
            $args['status'] = $status;
        }

        $vendors = VendorService::list($args);
        $hasMore = count($vendors) > $perPage;
        if ($hasMore) {
            array_pop($vendors);
        }

        $transformed = array_map([$this, 'summarizeVendor'], $vendors);

        return array(
            'vendors'  => $transformed,
            'has_more' => $hasMore,
            'page'     => $page,
            'per_page' => $perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getVendorDetails(int $vendorId): array
    {
        $vendor = VendorService::get($vendorId, true);
        if (! is_array($vendor)) {
            throw new RuntimeException('Vendor not found.');
        }

        $details = $this->summarizeVendor($vendor);
        $details['metadata'] = $this->extractMetadata($vendor);
        $details['resources'] = VendorService::getResources($vendorId);

        try {
            $dashboard = (new VendorDashboardService())->buildDashboard($vendorId);
        } catch (Throwable $exception) {
            $dashboard = null;
        }

        if (is_array($dashboard)) {
            $details['dashboard'] = $dashboard;
        }

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    public function updateAccessKey(int $vendorId, string $newKey): array
    {
        $vendorId = absint($vendorId);
        if ($vendorId <= 0) {
            throw new RuntimeException('Invalid vendor identifier.');
        }

        $vendor = VendorService::get($vendorId, true);
        if (! is_array($vendor)) {
            throw new RuntimeException('Vendor not found.');
        }

        $metadata = $this->extractMetadata($vendor);

        $metadata['vendor_portal_key_hash'] = wp_hash_password($newKey);
        $metadata['vendor_portal_key_hint'] = $this->maskSensitive($newKey);
        unset(
            $metadata['portal_key'],
            $metadata['vendor_portal_key'],
            $metadata['access_key'],
            $metadata['portal_key_hash'],
            $metadata['access_key_hash']
        );

        $success = $this->repository->updateVendorMetadata($vendorId, $metadata);

        if (! $success) {
            throw new RuntimeException('Failed to store access key metadata.');
        }

        /** @psalm-suppress UndefinedFunction */
        do_action('sbdp/vendor_portal/admin/key_updated', $vendorId, $metadata);

        (new VendorPortalAuditLogger())->log('admin_access_key_updated', array(
            'vendor_id' => (string) $vendorId,
            'hint'      => $metadata['vendor_portal_key_hint'] ?? '',
        ));

        $vendor['metadata'] = $metadata;

        return $this->summarizeVendor($vendor);
    }

    public function recordLogin(int $vendorId): void
    {
        $vendorId = absint($vendorId);
        if ($vendorId <= 0) {
            return;
        }

        $vendor = VendorService::get($vendorId, true);
        if (! is_array($vendor)) {
            return;
        }

        $metadata = $this->extractMetadata($vendor);
        $metadata['vendor_portal_last_login'] = gmdate(DateTimeImmutable::ATOM);
        $this->repository->updateVendorMetadata($vendorId, $metadata);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeVendor(array $vendor): array
    {
        $metadata = $this->extractMetadata($vendor);
        $keyInfo  = $this->extractKeyInfo($vendor, $metadata);

        $summary = array(
            'id'           => (int) ($vendor['id'] ?? 0),
            'name'         => (string) ($vendor['name'] ?? ''),
            'status'       => (string) ($vendor['status'] ?? ''),
            'contact_name' => (string) ($vendor['contact_name'] ?? ''),
            'contact_email'=> (string) ($vendor['contact_email'] ?? ''),
            'contact_phone'=> (string) ($vendor['contact_phone'] ?? ''),
            'last_login'   => isset($metadata['vendor_portal_last_login']) ? (string) $metadata['vendor_portal_last_login'] : '',
            'access_key'   => $keyInfo,
        );

        if (isset($vendor['channels']) && is_array($vendor['channels'])) {
            $summary['channels'] = $vendor['channels'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $vendor
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function extractKeyInfo(array $vendor, array $metadata): array
    {
        $plainKey = $this->firstNonEmpty($vendor, array('portal_key', 'vendor_portal_key', 'access_key'));
        $hashKey  = $this->firstNonEmpty($vendor, array('portal_key_hash', 'vendor_portal_key_hash', 'access_key_hash'));

        if ($plainKey === '') {
            $plainKey = $this->firstNonEmpty($metadata, array('portal_key', 'vendor_portal_key', 'access_key'));
        }
        if ($hashKey === '') {
            $hashKey = $this->firstNonEmpty($metadata, array('vendor_portal_key_hash', 'portal_key_hash', 'access_key_hash'));
        }

        $hint = '';
        if (isset($metadata['vendor_portal_key_hint'])) {
            $hint = (string) $metadata['vendor_portal_key_hint'];
        } elseif ($plainKey !== '') {
            $hint = $this->maskSensitive($plainKey);
        }

        return array(
            'has_plain' => $plainKey !== '',
            'has_hash'  => $hashKey !== '',
            'hint'      => $hint,
        );
    }

    /**
     * @param array<string, mixed> $source
     */
    private function firstNonEmpty(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_string($source[$key]) && $source[$key] !== '') {
                return $source[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $vendor
     * @return array<string, mixed>
     */
    private function extractMetadata(array $vendor): array
    {
        if (isset($vendor['metadata']) && is_array($vendor['metadata'])) {
            return $vendor['metadata'];
        }

        return array();
    }

    public function generateAccessKey(int $length = 16): string
    {
        $length = max(8, min($length, 64));
        $key    = wp_generate_password($length, false, false);

        if ($key === '' || strlen($key) < 4) {
            $key = substr(hash('sha256', (string) current_time('timestamp', true)), 0, $length);
        }

        return $key;
    }

    private function maskSensitive(string $value): string
    {
        $value = sanitize_text_field($value);
        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $prefix = substr($value, 0, 2);
        $suffix = substr($value, -2);

        return sprintf('%s%s%s', $prefix, str_repeat('*', $length - 4), $suffix);
    }
}

<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use PHPUnit\Framework\TestCase;

final class DataAnalyticsModuleTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testDataModuleRegistersDashboardAndProtectedCsvExport(): void
    {
        $module = (string) file_get_contents(self::ROOT . '/modules/data/Module.php');
        $menu = (string) file_get_contents(self::ROOT . '/modules/data/Admin/Menu.php');

        self::assertStringContainsString('Menu::init();', $module);
        self::assertStringContainsString("add_action('admin_menu'", $menu);
        self::assertStringContainsString("admin_post_sbdp_data_export_csv", $menu);
        self::assertStringContainsString("check_admin_referer('sbdp_data_export_csv')", $menu);
        self::assertStringContainsString("current_user_can('manage_woocommerce')", $menu);
    }

    public function testAnalyticsConsumesWooTruthWithoutWritingOrdersOrPrices(): void
    {
        $reports = (string) file_get_contents(self::ROOT . '/modules/intelligence/ReportsService.php');
        $menu = (string) file_get_contents(self::ROOT . '/modules/data/Admin/Menu.php');

        self::assertStringContainsString("'status'       => ['completed', 'processing']", $reports);
        self::assertStringContainsString('(new ReportsService())->generateSnapshot()', $menu);
        self::assertStringNotContainsString('wc_create_order', $menu);
        self::assertStringNotContainsString('set_price(', $menu);
        self::assertStringNotContainsString('update_post_meta(', $menu);
    }

    public function testDataDashboardReusesHardenedSpotsImporter(): void
    {
        $menu = (string) file_get_contents(self::ROOT . '/modules/data/Admin/Menu.php');
        $importer = (string) file_get_contents(self::ROOT . '/../ddb-spots-0.1.0/includes/Admin/BulkCsvSyncPage.php');

        self::assertStringContainsString('ddb-spots-bulk-csv-sync', $menu);
        self::assertStringContainsString("private const MAX_FILE_BYTES = 5242880", $importer);
        self::assertStringContainsString("isset(\$seen_spot_ids[ \$spot_id ])", $importer);
        self::assertStringContainsString("'bulk_csv_update'", $importer);
        self::assertStringContainsString("check_admin_referer(self::IMPORT_NONCE_ACTION)", $importer);
        self::assertStringNotContainsString('directBookable', $importer);
        self::assertStringNotContainsString('booking-widget', $importer);
    }
}

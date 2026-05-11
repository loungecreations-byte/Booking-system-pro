<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Support;

use wpdb;
use function delete_option;
use function dbDelta;
use function get_option;
use function get_page_by_path;
use function get_post;
use function get_post_meta;
use function get_users;
use function update_option;
use function update_post_meta;
use function wp_insert_post;
use function wp_json_encode;
use function wp_update_post;
use function current_time;

/**
 * Installer for the Partner Program domain.
 *
 * Installs 12 new tables — all additive, zero destructive changes to existing tables.
 * Uses dbDelta so repeated calls are safe (idempotent).
 *
 * Schema version bump triggers re-install / migrations.
 */
final class Installer
{
    private const SCHEMA_VERSION     = '1.3.0';
    private const OPTION_SCHEMA_KEY  = 'bsp_partner_program_schema_version';
    private const PAGE_CONFIG_VERSION = '1.2.0';
    private const OPTION_PAGE_CONFIG_KEY = 'bsp_partner_program_page_config_version';

    public static function maybeInstall(): void
    {
        if (get_option(self::OPTION_SCHEMA_KEY) !== self::SCHEMA_VERSION) {
            self::install();
        }

        self::maybeNormalizePartnerPages();
    }

    public static function runPageNormalization(): void
    {
        delete_option(self::OPTION_PAGE_CONFIG_KEY);
        self::maybeNormalizePartnerPages();
    }

    public static function install(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        // ------------------------------------------------------------------ //
        // 1. PLACE SEEDS — Google / external source, discovery only           //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_place_seeds (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            external_source ENUM('google','manual','tripadvisor') NOT NULL DEFAULT 'manual',
            external_id     VARCHAR(255) NOT NULL DEFAULT '',
            name            VARCHAR(255) NOT NULL DEFAULT '',
            address         TEXT NULL,
            city            VARCHAR(100) NULL,
            postal_code     VARCHAR(20) NULL,
            lat             DECIMAL(10,7) NULL,
            lng             DECIMAL(10,7) NULL,
            phone           VARCHAR(50) NULL,
            website         VARCHAR(500) NULL,
            categories      LONGTEXT NULL,
            raw_payload     LONGTEXT NULL,
            sync_status     ENUM('pending','synced','failed','stale') NOT NULL DEFAULT 'pending',
            last_synced_at  DATETIME NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY external_source_id (external_source, external_id),
            KEY sync_status (sync_status),
            KEY city (city)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 2. PLACE SEED SYNC LOG                                              //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_place_seed_sync_log (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            place_seed_id       BIGINT(20) UNSIGNED NOT NULL,
            sync_result         ENUM('ok','failed','no_change') NOT NULL,
            api_response_code   SMALLINT UNSIGNED NULL,
            note                TEXT NULL,
            synced_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY place_seed_id (place_seed_id),
            KEY synced_at (synced_at)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 3. BUSINESS ENTITIES — canonical legal identity (OMDB / admin)      //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_business_entities (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            kvk_number      VARCHAR(50) NULL,
            legal_name      VARCHAR(255) NOT NULL DEFAULT '',
            trade_name      VARCHAR(255) NULL,
            address         TEXT NULL,
            city            VARCHAR(100) NULL,
            postal_code     VARCHAR(20) NULL,
            contact_email   VARCHAR(190) NULL,
            contact_phone   VARCHAR(50) NULL,
            place_seed_id   BIGINT(20) UNSIGNED NULL,
            entity_status   ENUM('unverified','verified','suspended','archived') NOT NULL DEFAULT 'unverified',
            verified_at     DATETIME NULL,
            verified_by     BIGINT(20) UNSIGNED NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY kvk_number (kvk_number),
            KEY place_seed_id (place_seed_id),
            KEY entity_status (entity_status)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 4. CLAIM REQUESTS — workflow for claiming a business profile        //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_claim_requests (
            id                      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            place_seed_id           BIGINT(20) UNSIGNED NOT NULL,
            claimant_wp_user_id     BIGINT(20) UNSIGNED NOT NULL,
            business_entity_id      BIGINT(20) UNSIGNED NULL,
            claim_status            ENUM('submitted','under_review','verified','rejected','duplicate','expired') NOT NULL DEFAULT 'submitted',
            verification_method     ENUM('email','phone','postcard','manual') NOT NULL DEFAULT 'email',
            verification_token      VARCHAR(100) NULL,
            token_expires_at        DATETIME NULL,
            admin_note              TEXT NULL,
            reviewed_by             BIGINT(20) UNSIGNED NULL,
            reviewed_at             DATETIME NULL,
            submitted_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY place_seed_id (place_seed_id),
            KEY claimant_wp_user_id (claimant_wp_user_id),
            KEY claim_status (claim_status),
            KEY verification_token (verification_token)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 5. PARTNER ACCOUNTS — commercial truth for partner identity         //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_partner_accounts (
            id                      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id               BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            business_entity_id      BIGINT(20) UNSIGNED NOT NULL,
            wp_user_id              BIGINT(20) UNSIGNED NOT NULL,
            account_status          ENUM('onboarding','active','grace_period','suspended','churned','archived') NOT NULL DEFAULT 'onboarding',
            partner_tier            ENUM('basis','premium','gold') NOT NULL DEFAULT 'basis',
            commercial_mode         ENUM('listing','lead','bookable') NOT NULL DEFAULT 'listing',
            booking_enabled         TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            lead_enabled            TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            onboarding_completed_at DATETIME NULL,
            tier_activated_at       DATETIME NULL,
            suspended_at            DATETIME NULL,
            created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY vendor_id (vendor_id),
            KEY wp_user_id (wp_user_id),
            KEY account_status (account_status),
            KEY partner_tier (partner_tier)
        ) {$c};");;

        // ------------------------------------------------------------------ //
        // 6. SUBSCRIPTION PLANS — canonical tier definitions (seeded below)  //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_subscription_plans (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_slug       VARCHAR(50) NOT NULL,
            plan_name       VARCHAR(100) NOT NULL,
            billing_cycle   ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
            price_eur       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            setup_fee_eur   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            entitlements    LONGTEXT NOT NULL,
            woo_product_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            is_active       TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY plan_slug_cycle (plan_slug, billing_cycle),
            KEY is_active (is_active),
            KEY woo_product_id (woo_product_id)
        ) {$c};");;

        // ------------------------------------------------------------------ //
        // 7. SUBSCRIPTION CONTRACTS — active billing relationship            //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_subscription_contracts (
            id                      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_account_id      BIGINT(20) UNSIGNED NOT NULL,
            plan_id                 BIGINT(20) UNSIGNED NOT NULL,
            woo_subscription_id     BIGINT(20) UNSIGNED NULL,
            contract_status         ENUM('active','past_due','cancelled','paused','expired') NOT NULL DEFAULT 'active',
            billing_cycle           ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
            current_period_start    DATETIME NOT NULL,
            current_period_end      DATETIME NOT NULL,
            grace_period_end        DATETIME NULL,
            cancelled_at            DATETIME NULL,
            cancellation_reason     TEXT NULL,
            created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY partner_account_id (partner_account_id),
            KEY contract_status (contract_status),
            KEY current_period_end (current_period_end)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 8. PARTNER ENTITLEMENTS — current rights, derived from contract    //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_partner_entitlements (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_account_id  BIGINT(20) UNSIGNED NOT NULL,
            entitlement_key     VARCHAR(100) NOT NULL,
            entitlement_value   LONGTEXT NOT NULL,
            source              ENUM('plan','manual_override','add_on') NOT NULL DEFAULT 'plan',
            valid_from          DATETIME NOT NULL,
            valid_until         DATETIME NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY partner_account_entitlement (partner_account_id, entitlement_key),
            KEY valid_until (valid_until)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 9. COMMISSION RULES — canonical commission definitions per tier     //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_commission_rules (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_account_id  BIGINT(20) UNSIGNED NULL,
            commercial_mode     VARCHAR(20) NOT NULL DEFAULT 'bookable',
            partner_tier        ENUM('basis','premium','gold','__platform__') NOT NULL DEFAULT '__platform__',
            commission_type     ENUM('percentage','flat') NOT NULL DEFAULT 'percentage',
            commission_value    DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
            applies_from        DATETIME NOT NULL,
            applies_until       DATETIME NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY partner_tier (partner_tier),
            KEY partner_account_id (partner_account_id)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 10. SETTLEMENT BATCHES — monthly aggregation per payout cycle      //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_settlement_batches (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_reference     VARCHAR(50) NOT NULL,
            period_label        VARCHAR(100) NULL,
            period_start        DATE NOT NULL,
            period_end          DATE NOT NULL,
            batch_status        ENUM('draft','calculated','approved','paid','failed') NOT NULL DEFAULT 'draft',
            total_gross_eur     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_commission_eur DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_payout_eur    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            approved_by         BIGINT(20) UNSIGNED NULL,
            approved_at         DATETIME NULL,
            paid_at             DATETIME NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY batch_reference (batch_reference),
            KEY batch_status (batch_status),
            KEY period_start (period_start)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 11. SETTLEMENT ITEMS — per-booking line items in a batch           //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_settlement_items (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id            BIGINT(20) UNSIGNED NOT NULL,
            vendor_id           BIGINT(20) UNSIGNED NOT NULL,
            booking_master_id   BIGINT(20) UNSIGNED NOT NULL,
            gross_eur           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            commission_rate     DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            commission_eur      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payout_eur          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            item_status         ENUM('pending','approved','paid','held','disputed','in_review','cancelled') NOT NULL DEFAULT 'pending',
            hold_reason         TEXT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY batch_id (batch_id),
            KEY vendor_id (vendor_id),
            KEY booking_master_id (booking_master_id),
            KEY item_status (item_status)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 12. PAYOUT PROFILES — vendor bank / payout method config           //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_payout_profiles (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id           BIGINT(20) UNSIGNED NOT NULL,
            account_holder_name VARCHAR(200) NULL,
            payout_method       ENUM('bank_transfer','mollie','manual') NOT NULL DEFAULT 'manual',
            iban                VARCHAR(50) NULL,
            iban_verified       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            payout_email        VARCHAR(200) NULL,
            payout_schedule     ENUM('monthly','bi-monthly','weekly') NOT NULL DEFAULT 'monthly',
            minimum_payout_eur  DECIMAL(10,2) NOT NULL DEFAULT 25.00,
            notes               TEXT NULL,
            status              VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY vendor_id (vendor_id)
        ) {$c};");

        self::seedSubscriptionPlans($wpdb);
        self::seedCommissionRules($wpdb);
        self::migrateExistingVendors($wpdb);
        self::installExtendedSchema($wpdb, $p, $c);
        self::migrateEnumColumns($wpdb, $p);

        update_option(self::OPTION_SCHEMA_KEY, self::SCHEMA_VERSION);
    }

    private static function maybeNormalizePartnerPages(): void
    {
        if (get_option(self::OPTION_PAGE_CONFIG_KEY) === self::PAGE_CONFIG_VERSION) {
            return;
        }

        if (! function_exists('get_page_by_path') || ! function_exists('wp_update_post') || ! function_exists('update_post_meta')) {
            return;
        }

        $authorId = self::resolveCanonicalAuthorId();

        foreach (self::partnerPageDefinitions() as $slug => $definition) {
            self::normalizePartnerPage($slug, $definition, $authorId);
        }

        update_option(self::OPTION_PAGE_CONFIG_KEY, self::PAGE_CONFIG_VERSION);
    }

    /**
     * Keep partner pages flat in the tree so their public routes remain stable.
     * These pages are referenced by fixed URLs in claims, payout CTAs and portal flows.
     *
     * @return array<string, array<string, int|string|null>>
     */
    private static function partnerPageDefinitions(): array
    {
        return [
            'shop' => [
                'title' => 'Shop',
                'menu_order' => 0,
                'content' => null,
            ],
            'cart' => [
                'title' => 'Cart',
                'menu_order' => 0,
                'content' => null,
            ],
            'checkout' => [
                'title' => 'Checkout',
                'menu_order' => 0,
                'content' => null,
            ],
            'my-account' => [
                'title' => 'My account',
                'menu_order' => 0,
                'content' => null,
            ],
            'activiteiten' => [
                'title' => 'Activiteiten',
                'menu_order' => 0,
                'content' => '[ddb_activiteiten count=20]',
                'elementor_shortcode' => '[ddb_activiteiten count=20]',
                'force_elementor_document' => true,
            ],
            'plattegrond' => [
                'title' => 'Plattegrond',
                'menu_order' => 1,
                'content' => '[ddb_spots]',
                'elementor_shortcode' => '[ddb_spots]',
            ],
            'offerte' => [
                'title' => 'Offerte aanvragen',
                'menu_order' => 2,
                'content' => '<p>Offerte aanvragen voor jouw dag in Den Bosch.</p>',
                'elementor_html' => '<p>Offerte aanvragen voor jouw dag in Den Bosch.</p>',
            ],
            'partner-profile' => [
                'title' => 'Partner profiel',
                'menu_order' => 3,
                'content' => '[ddb_account_hub variant=partner]',
                'elementor_shortcode' => '[ddb_account_hub variant=partner]',
            ],
            'premium-members' => [
                'title' => 'Premium members',
                'menu_order' => 3,
                'content' => '[ddb_account_hub variant=premium]',
                'elementor_shortcode' => '[ddb_account_hub variant=premium]',
            ],
            'partner-portal' => [
                'title' => 'Partner Portal',
                'menu_order' => 5,
                'content' => '[bsp_vendor_portal]',
                'elementor_shortcode' => '[bsp_vendor_portal]',
            ],
            'dieet-opgave' => [
                'title' => 'Dieet Opgave',
                'menu_order' => 6,
                'content' => '[sbdp_dietary_intake]',
                'elementor_shortcode' => '[sbdp_dietary_intake]',
            ],
            'partner-claim' => [
                'title' => 'Partner Claim',
                'menu_order' => 7,
                'content' => '[bsp_partner_claim_form]',
                'elementor_shortcode' => '[bsp_partner_claim_form]',
            ],
            'partner-verify' => [
                'title' => 'Partner Verify',
                'menu_order' => 8,
                'content' => '[bsp_partner_verify]',
                'elementor_shortcode' => '[bsp_partner_verify]',
            ],
            'partner-dashboard' => [
                'title' => 'Partner Dashboard',
                'menu_order' => 9,
                'content' => '[bsp_partner_dashboard]',
                'elementor_shortcode' => '[bsp_partner_dashboard]',
            ],
            'partner-uitbetaling' => [
                'title' => 'Partner Uitbetaling',
                'menu_order' => 10,
                'content' => '[bsp_payout_profile]',
                'elementor_shortcode' => '[bsp_payout_profile]',
            ],
            'partner-onboarding' => [
                'title' => 'Partner Onboarding',
                'menu_order' => 11,
                'content' => "<h2>Partner onboarding</h2><p>Start met de juiste route voor jouw bedrijf.</p><p><a href='/partner-prijzen/'>Bekijk abonnementen</a></p><p><a href='/partner-claim/'>Claim je profiel</a></p><p><a href='/partner-dashboard/'>Ga naar partnerdashboard</a></p>",
                'elementor_html' => "<h2>Partner onboarding</h2><p>Start met de juiste route voor jouw bedrijf.</p><p><a href='/partner-prijzen/'>Bekijk abonnementen</a></p><p><a href='/partner-claim/'>Claim je profiel</a></p><p><a href='/partner-dashboard/'>Ga naar partnerdashboard</a></p>",
            ],
            'partner-prijzen' => [
                'title' => 'Partner Prijzen',
                'menu_order' => 12,
                'content' => '[bsp_partner_pricing]',
                'elementor_shortcode' => '[bsp_partner_pricing]',
            ],
        ];
    }

    /**
     * @param array<string, int|string|null> $definition
     */
    private static function normalizePartnerPage(string $slug, array $definition, int $authorId): void
    {
        $page = get_page_by_path($slug, OBJECT, 'page');
        $pageId = $page instanceof \WP_Post
            ? (int) $page->ID
            : self::createPartnerPage($slug, $definition, $authorId);

        if ($pageId <= 0) {
            return;
        }

        $post = get_post($pageId);
        if (! $post instanceof \WP_Post) {
            return;
        }

        $updates = ['ID' => $pageId];
        $hasUpdates = false;

        if ((string) $post->post_title !== (string) $definition['title']) {
            $updates['post_title'] = (string) $definition['title'];
            $hasUpdates = true;
        }

        if ((int) $post->menu_order !== (int) $definition['menu_order']) {
            $updates['menu_order'] = (int) $definition['menu_order'];
            $hasUpdates = true;
        }

        if ((int) $post->post_author === 0 && $authorId > 0) {
            $updates['post_author'] = $authorId;
            $hasUpdates = true;
        }

        if ((string) $post->post_status !== 'publish') {
            $updates['post_status'] = 'publish';
            $hasUpdates = true;
        }

        $expectedContent = $definition['content'] ?? null;
        if (is_string($expectedContent) && trim((string) $post->post_content) === '') {
            $updates['post_content'] = $expectedContent;
            $hasUpdates = true;
        }

        if ($hasUpdates) {
            wp_update_post($updates);
        }

        if ((string) get_post_meta($pageId, '_wp_page_template', true) === '') {
            update_post_meta($pageId, '_wp_page_template', 'default');
        }

        $forceElementorDocument = ! empty($definition['force_elementor_document']);

        $elementorShortcode = $definition['elementor_shortcode'] ?? null;
        if (is_string($elementorShortcode) && $elementorShortcode !== '') {
            self::ensureElementorShortcodeDocument($pageId, $slug, $elementorShortcode, $forceElementorDocument);
            return;
        }

        $elementorHtml = $definition['elementor_html'] ?? null;
        if (is_string($elementorHtml) && $elementorHtml !== '') {
            self::ensureElementorHtmlDocument($pageId, $slug, $elementorHtml, $forceElementorDocument);
        }
    }

    /**
     * @param array<string, int|string|null> $definition
     */
    private static function createPartnerPage(string $slug, array $definition, int $authorId): int
    {
        $pageId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => (string) $definition['title'],
            'post_name' => $slug,
            'post_author' => $authorId,
            'menu_order' => (int) $definition['menu_order'],
            'post_content' => is_string($definition['content'] ?? null) ? (string) $definition['content'] : '',
        ], true);

        if (is_wp_error($pageId)) {
            return 0;
        }

        return (int) $pageId;
    }

    private static function resolveCanonicalAuthorId(): int
    {
        if (! function_exists('get_users')) {
            return 1;
        }

        $admins = get_users([
            'role' => 'administrator',
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        if (is_array($admins) && isset($admins[0])) {
            return (int) $admins[0];
        }

        return 1;
    }

    private static function ensureElementorShortcodeDocument(int $pageId, string $slug, string $shortcode, bool $forceDocument = false): void
    {
        if ((string) get_post_meta($pageId, '_elementor_edit_mode', true) !== 'builder') {
            update_post_meta($pageId, '_elementor_edit_mode', 'builder');
        }

        if ((string) get_post_meta($pageId, '_elementor_template_type', true) === '') {
            update_post_meta($pageId, '_elementor_template_type', 'wp-page');
        }

        if ($forceDocument || (string) get_post_meta($pageId, '_elementor_data', true) === '') {
            update_post_meta($pageId, '_elementor_data', self::buildShortcodeElementorDocument($slug, $shortcode));
        }

        if (defined('ELEMENTOR_VERSION') && (string) get_post_meta($pageId, '_elementor_version', true) === '') {
            update_post_meta($pageId, '_elementor_version', (string) ELEMENTOR_VERSION);
        }
    }

    private static function ensureElementorHtmlDocument(int $pageId, string $slug, string $html, bool $forceDocument = false): void
    {
        if ((string) get_post_meta($pageId, '_elementor_edit_mode', true) !== 'builder') {
            update_post_meta($pageId, '_elementor_edit_mode', 'builder');
        }

        if ((string) get_post_meta($pageId, '_elementor_template_type', true) === '') {
            update_post_meta($pageId, '_elementor_template_type', 'wp-page');
        }

        if ($forceDocument || (string) get_post_meta($pageId, '_elementor_data', true) === '') {
            update_post_meta($pageId, '_elementor_data', self::buildHtmlElementorDocument($slug, $html));
        }

        if (defined('ELEMENTOR_VERSION') && (string) get_post_meta($pageId, '_elementor_version', true) === '') {
            update_post_meta($pageId, '_elementor_version', (string) ELEMENTOR_VERSION);
        }
    }

    private static function buildShortcodeElementorDocument(string $slug, string $shortcode): string
    {
        $containerId = substr(md5($slug . '-container'), 0, 7);
        $widgetId = substr(md5($slug . '-shortcode'), 0, 7);

        return (string) wp_json_encode([
            [
                'id' => $containerId,
                'elType' => 'container',
                'settings' => [
                    'flex_direction' => 'column',
                ],
                'elements' => [
                    [
                        'id' => $widgetId,
                        'elType' => 'widget',
                        'settings' => [
                            'shortcode' => $shortcode,
                        ],
                        'elements' => [],
                        'widgetType' => 'shortcode',
                    ],
                ],
                'isInner' => false,
            ],
        ]);
    }

    private static function buildHtmlElementorDocument(string $slug, string $html): string
    {
        $containerId = substr(md5($slug . '-container'), 0, 7);
        $widgetId = substr(md5($slug . '-html'), 0, 7);

        return (string) wp_json_encode([
            [
                'id' => $containerId,
                'elType' => 'container',
                'settings' => [
                    'flex_direction' => 'column',
                ],
                'elements' => [
                    [
                        'id' => $widgetId,
                        'elType' => 'widget',
                        'settings' => [
                            'html' => $html,
                        ],
                        'elements' => [],
                        'widgetType' => 'html',
                    ],
                ],
                'isInner' => false,
            ],
        ]);
    }

    // ---------------------------------------------------------------------- //
    // EXTENDED SCHEMA v1.2.0 — partner users, roles, placement, onboarding  //
    // ---------------------------------------------------------------------- //
    private static function installExtendedSchema(wpdb $wpdb, string $p, string $c): void
    {
        // ------------------------------------------------------------------ //
        // 13. PARTNER ROLES — role definitions per partner account           //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_partner_roles (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            role_slug       VARCHAR(50) NOT NULL,
            label           VARCHAR(100) NOT NULL,
            permissions     LONGTEXT NOT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY role_slug (role_slug)
        ) {$c};");

        // Seed canonical roles.
        $rolesTable = $p . 'bsp_partner_roles';
        $roles = [
            ['owner',   'Eigenaar',  '{"all":true}'],
            ['manager', 'Beheerder', '{"edit_offers":true,"edit_profile":true,"view_reports":true}'],
            ['viewer',  'Lezer',     '{"view_reports":true}'],
        ];
        foreach ($roles as [$slug, $label, $perms]) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$rolesTable} WHERE role_slug = %s LIMIT 1",
                $slug
            ));
            if (! $exists) {
                $wpdb->insert($rolesTable, ['role_slug' => $slug, 'label' => $label, 'permissions' => $perms]);
            }
        }

        // ------------------------------------------------------------------ //
        // 14. PARTNER USERS — multi-user access per partner account          //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_partner_users (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_account_id  BIGINT(20) UNSIGNED NOT NULL,
            wp_user_id          BIGINT(20) UNSIGNED NOT NULL,
            role_slug           VARCHAR(50) NOT NULL DEFAULT 'viewer',
            invited_by          BIGINT(20) UNSIGNED NULL,
            invited_at          DATETIME NULL,
            accepted_at         DATETIME NULL,
            status              ENUM('invited','active','suspended','removed') NOT NULL DEFAULT 'invited',
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY partner_user (partner_account_id, wp_user_id),
            KEY wp_user_id (wp_user_id),
            KEY status (status)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 15. PLACEMENT PACKAGES — featured boosts and campaign slots        //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_placement_packages (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_account_id  BIGINT(20) UNSIGNED NOT NULL,
            placement_type      ENUM('featured','campaign','boost','homepage_slot') NOT NULL DEFAULT 'featured',
            active_from         DATETIME NOT NULL,
            active_until        DATETIME NOT NULL,
            position_score      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            price_eur           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            source              ENUM('manual','purchased','tier_included') NOT NULL DEFAULT 'manual',
            note                TEXT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY partner_account_id (partner_account_id),
            KEY placement_type (placement_type),
            KEY active_until (active_until)
        ) {$c};");

        // ------------------------------------------------------------------ //
        // 16. ONBOARDING CHECKLISTS — per-partner step tracking              //
        // ------------------------------------------------------------------ //
        dbDelta("CREATE TABLE {$p}bsp_onboarding_checklists (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_account_id  BIGINT(20) UNSIGNED NOT NULL,
            step_key            VARCHAR(100) NOT NULL,
            completed_at        DATETIME NULL,
            skipped_at          DATETIME NULL,
            data                LONGTEXT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY partner_step (partner_account_id, step_key),
            KEY partner_account_id (partner_account_id)
        ) {$c};");
    }

    // ---------------------------------------------------------------------- //
    // SEED: Canonical subscription plans (Basis / Premium / Gold)            //
    // ---------------------------------------------------------------------- //
    private static function seedSubscriptionPlans(wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'bsp_subscription_plans';

        $plans = [
            [
                'plan_slug'     => 'basis',
                'plan_name'     => 'Basis',
                'billing_cycle' => 'monthly',
                'price_eur'     => 29.00,
                'setup_fee_eur' => 0.00,
                'entitlements'  => [
                    'max_offers'           => 1,
                    'max_users'            => 1,
                    'max_locations'        => 1,
                    'listing_visibility'   => 'standard',
                    'featured_eligible'    => false,
                    'ai_host_priority'     => 'low',
                    'lead_routing'         => false,
                    'booking_access'       => false,
                    'reporting_depth'      => 'basic',
                    'support_priority'     => 'email',
                    'campaign_eligible'    => false,
                    'settlement_frequency' => 'monthly',
                    'commission_rate_pct'  => 18.00,
                ],
            ],
            [
                'plan_slug'     => 'basis',
                'plan_name'     => 'Basis (jaarlijks)',
                'billing_cycle' => 'annual',
                'price_eur'     => 290.00,
                'setup_fee_eur' => 0.00,
                'entitlements'  => [
                    'max_offers'           => 1,
                    'max_users'            => 1,
                    'max_locations'        => 1,
                    'listing_visibility'   => 'standard',
                    'featured_eligible'    => false,
                    'ai_host_priority'     => 'low',
                    'lead_routing'         => false,
                    'booking_access'       => false,
                    'reporting_depth'      => 'basic',
                    'support_priority'     => 'email',
                    'campaign_eligible'    => false,
                    'settlement_frequency' => 'monthly',
                    'commission_rate_pct'  => 18.00,
                ],
            ],
            [
                'plan_slug'     => 'premium',
                'plan_name'     => 'Premium',
                'billing_cycle' => 'monthly',
                'price_eur'     => 79.00,
                'setup_fee_eur' => 0.00,
                'entitlements'  => [
                    'max_offers'           => 5,
                    'max_users'            => 2,
                    'max_locations'        => 2,
                    'listing_visibility'   => 'elevated',
                    'featured_eligible'    => true,
                    'ai_host_priority'     => 'medium',
                    'lead_routing'         => true,
                    'booking_access'       => false,
                    'reporting_depth'      => 'advanced',
                    'support_priority'     => 'priority_email',
                    'campaign_eligible'    => true,
                    'settlement_frequency' => 'monthly',
                    'commission_rate_pct'  => 14.00,
                ],
            ],
            [
                'plan_slug'     => 'premium',
                'plan_name'     => 'Premium (jaarlijks)',
                'billing_cycle' => 'annual',
                'price_eur'     => 790.00,
                'setup_fee_eur' => 0.00,
                'entitlements'  => [
                    'max_offers'           => 5,
                    'max_users'            => 2,
                    'max_locations'        => 2,
                    'listing_visibility'   => 'elevated',
                    'featured_eligible'    => true,
                    'ai_host_priority'     => 'medium',
                    'lead_routing'         => true,
                    'booking_access'       => false,
                    'reporting_depth'      => 'advanced',
                    'support_priority'     => 'priority_email',
                    'campaign_eligible'    => true,
                    'settlement_frequency' => 'monthly',
                    'commission_rate_pct'  => 14.00,
                ],
            ],
            [
                'plan_slug'     => 'gold',
                'plan_name'     => 'Gold',
                'billing_cycle' => 'monthly',
                'price_eur'     => 149.00,
                'setup_fee_eur' => 0.00,
                'entitlements'  => [
                    'max_offers'           => -1,
                    'max_users'            => 5,
                    'max_locations'        => -1,
                    'listing_visibility'   => 'priority',
                    'featured_eligible'    => true,
                    'featured_included'    => 1,
                    'ai_host_priority'     => 'high',
                    'lead_routing'         => true,
                    'lead_routing_priority'=> true,
                    'booking_access'       => true,
                    'reporting_depth'      => 'full',
                    'support_priority'     => 'dedicated',
                    'campaign_eligible'    => true,
                    'settlement_frequency' => 'bi-monthly',
                    'commission_rate_pct'  => 10.00,
                ],
            ],
            [
                'plan_slug'     => 'gold',
                'plan_name'     => 'Gold (jaarlijks)',
                'billing_cycle' => 'annual',
                'price_eur'     => 1490.00,
                'setup_fee_eur' => 0.00,
                'entitlements'  => [
                    'max_offers'           => -1,
                    'max_users'            => 5,
                    'max_locations'        => -1,
                    'listing_visibility'   => 'priority',
                    'featured_eligible'    => true,
                    'featured_included'    => 1,
                    'ai_host_priority'     => 'high',
                    'lead_routing'         => true,
                    'lead_routing_priority'=> true,
                    'booking_access'       => true,
                    'reporting_depth'      => 'full',
                    'support_priority'     => 'dedicated',
                    'campaign_eligible'    => true,
                    'settlement_frequency' => 'bi-monthly',
                    'commission_rate_pct'  => 10.00,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE plan_slug = %s AND billing_cycle = %s LIMIT 1",
                $plan['plan_slug'],
                $plan['billing_cycle']
            ));

            if ($exists) {
                continue;
            }

            $wpdb->insert($table, [
                'plan_slug'     => $plan['plan_slug'],
                'plan_name'     => $plan['plan_name'],
                'billing_cycle' => $plan['billing_cycle'],
                'price_eur'     => $plan['price_eur'],
                'setup_fee_eur' => $plan['setup_fee_eur'],
                'entitlements'  => (string) wp_json_encode($plan['entitlements']),
                'is_active'     => 1,
            ]);
        }
    }

    // ---------------------------------------------------------------------- //
    // SEED: Platform-default commission rules per tier                       //
    // ---------------------------------------------------------------------- //
    private static function seedCommissionRules(wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'bsp_commission_rules';

        $defaults = [
            ['__platform__', 'basis',   18.00],
            ['__platform__', 'premium', 14.00],
            ['__platform__', 'gold',    10.00],
        ];

        foreach ($defaults as [$tier, $tierSlug, $rate]) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE partner_account_id IS NULL AND partner_tier = %s AND commercial_mode = 'bookable' LIMIT 1",
                $tierSlug
            ));

            if ($exists) {
                continue;
            }

            $wpdb->insert($table, [
                'partner_account_id' => null,
                'commercial_mode'    => 'bookable',
                'partner_tier'       => $tierSlug,
                'commission_type'    => 'percentage',
                'commission_value'   => $rate,
                'applies_from'       => current_time('mysql'),
                'applies_until'      => null,
            ]);
        }
    }

    // ---------------------------------------------------------------------- //
    // MIGRATION: Ensure every existing bsp_vendor has a partner_account row  //
    // ---------------------------------------------------------------------- //
    private static function migrateExistingVendors(wpdb $wpdb): void
    {
        $vendors_table  = $wpdb->prefix . 'bsp_vendors';
        $accounts_table = $wpdb->prefix . 'bsp_partner_accounts';
        $entities_table = $wpdb->prefix . 'bsp_business_entities';

        // Only runs if tables exist (safety guard).
        $vendors_exist = $wpdb->get_var("SHOW TABLES LIKE '{$vendors_table}'");
        if (! $vendors_exist) {
            return;
        }

        $vendors = $wpdb->get_results("SELECT id, name, contact_email, metadata FROM {$vendors_table} WHERE status != 'archived'", ARRAY_A);

        foreach ($vendors as $vendor) {
            $vendor_id = (int) $vendor['id'];

            // Skip if already migrated.
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$accounts_table} WHERE vendor_id = %d LIMIT 1",
                $vendor_id
            ));
            if ($exists) {
                continue;
            }

            // Determine tier from legacy metadata JSON.
            $meta      = json_decode((string) ($vendor['metadata'] ?? '{}'), true);
            $legacyTier = (string) ($meta['tier'] ?? '');
            $tier      = in_array($legacyTier, ['premium', 'gold'], true) ? $legacyTier : 'basis';

            // Create a stub business entity (unverified) for each vendor.
            $entity_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$entities_table} WHERE contact_email = %s LIMIT 1",
                (string) ($vendor['contact_email'] ?? '')
            ));

            if (! $entity_id) {
                $wpdb->insert($entities_table, [
                    'legal_name'    => (string) ($vendor['name'] ?? ''),
                    'trade_name'    => (string) ($vendor['name'] ?? ''),
                    'contact_email' => (string) ($vendor['contact_email'] ?? ''),
                    'entity_status' => 'unverified',
                ]);
                $entity_id = $wpdb->insert_id;
            }

            // Find the WP user linked to this vendor via user meta.
            $wp_user_id = (int) ($wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_sbdp_vendor_id' AND meta_value = %s LIMIT 1",
                (string) $vendor_id
            )) ?? 0);

            $wpdb->insert($accounts_table, [
                'vendor_id'          => $vendor_id,
                'business_entity_id' => (int) $entity_id,
                'wp_user_id'         => $wp_user_id,
                'account_status'     => 'active',
                'partner_tier'       => $tier,
                'commercial_mode'    => 'listing',
                'booking_enabled'    => 0,
                'lead_enabled'       => 0,
            ]);
        }
    }

    // ---------------------------------------------------------------------- //
    // MIGRATION v1.3.0 — ALTER ENUM columns that dbDelta cannot modify       //
    // ---------------------------------------------------------------------- //
    private static function migrateEnumColumns(wpdb $wpdb, string $p): void
    {
        $itemsTable = $wpdb->prefix . 'bsp_settlement_items';
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$itemsTable}'");
        if (! $tableExists) {
            return;
        }

        // Extend item_status ENUM to include 'in_review' and 'cancelled'.
        // dbDelta cannot alter ENUM definitions; an explicit ALTER TABLE is required.
        $wpdb->query(
            "ALTER TABLE `{$itemsTable}`
             MODIFY COLUMN `item_status`
             ENUM('pending','approved','paid','held','disputed','in_review','cancelled')
             NOT NULL DEFAULT 'pending'"
        );
    }
}

<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use function admin_url;
use function function_exists;
use function get_post_meta;
use function get_posts;
use function in_array;
use function is_array;
use function str_contains;
use function strlen;
use function trim;

final class PageAuditService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getIssues(): array
    {
        if (! function_exists('get_posts')) {
            return [];
        }

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);

        if (! is_array($pages) || $pages === []) {
            return [];
        }

        $issues = [];

        foreach ($pages as $page) {
            if (! $page instanceof \WP_Post) {
                continue;
            }

            $content = trim((string) $page->post_content);
            $pageIssues = [];
            $isCustomCandidate = self::isCustomCandidate((string) $page->post_name, $content);
            $editMode = (string) get_post_meta($page->ID, '_elementor_edit_mode', true);
            $dataLen = strlen((string) get_post_meta($page->ID, '_elementor_data', true));
            $template = (string) get_post_meta($page->ID, '_wp_page_template', true);

            if ((int) $page->post_author === 0) {
                $pageIssues[] = 'Auteur staat op 0.';
            }

            if ($isCustomCandidate && $editMode === '' && $dataLen === 0) {
                $pageIssues[] = 'Custom pagina mist Elementor document/meta.';
            }

            if ($isCustomCandidate && $editMode === 'builder' && $dataLen === 0) {
                $pageIssues[] = 'Elementor builder staat aan zonder documentdata.';
            }

            if ($isCustomCandidate && $template === '') {
                $pageIssues[] = 'Paginatemplate-meta ontbreekt.';
            }

            if ($pageIssues === []) {
                continue;
            }

            $issues[] = [
                'page_id' => (int) $page->ID,
                'title' => (string) $page->post_title,
                'slug' => (string) $page->post_name,
                'issues' => $pageIssues,
                'edit_url' => admin_url('post.php?post=' . (int) $page->ID . '&action=edit'),
            ];
        }

        return $issues;
    }

    private static function isCustomCandidate(string $slug, string $content): bool
    {
        if ($slug === '' || in_array($slug, ['cart', 'checkout', 'my-account', 'shop', 'privacy-policy'], true)) {
            return false;
        }

        if (in_array($slug, [
            'plattegrond',
            'offerte',
            'partner-profile',
            'premium-members',
            'mijn-boekingen',
            'partner-portal',
            'dieet-opgave',
            'partner-claim',
            'partner-verify',
            'partner-dashboard',
            'partner-uitbetaling',
            'partner-onboarding',
            'partner-prijzen',
            'plan-je-dag',
            'activiteiten',
        ], true)) {
            return true;
        }

        return str_contains($content, '[ddb_')
            || str_contains($content, '[bsp_')
            || str_contains($content, '[sbdp_')
            || str_contains($content, 'ddb-');
    }
}
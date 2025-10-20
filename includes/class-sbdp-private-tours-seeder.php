<?php
/**
 * Seeds reference tours for DagjeDenBosch portal.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles demo data for private tours.
 */
class SBDP_Private_Tours_Seeder
{
    /**
     * Create the default tours when missing.
     */
    public static function seed_defaults(): void
    {
        $tours = array(
            'jeroen-bosch-audiovideo' => array(
                'title'    => __('Jeroen Bosch Audio/Video Tour', 'sbdp'),
                'summary'  => __('Interactieve tour langs het leven van Jheronimus Bosch met audio, video en stripgids.', 'sbdp'),
                'duration' => 75,
                'price'    => 19.95,
                'steps'    => array(
                    array(
                        'title'       => __('Welkom en introductie', 'sbdp'),
                        'content'     => __('Maak kennis met de stripgids en ontdek de planning van de dag.', 'sbdp'),
                        'type'        => 'video',
                        'media_url'   => 'https://example.com/media/jb-welcome.mp4',
                        'gamification'=> '{"challenge":"intro_quiz","questions":3}',
                        'points'      => 25,
                    ),
                    array(
                        'title'     => __('VR Duik in Het Laatste Oordeel', 'sbdp'),
                        'content'   => __('Stap virtueel in het meesterwerk en ontdek verborgen details.', 'sbdp'),
                        'type'      => 'vr',
                        'vr_asset'  => 'https://example.com/vr/jb-last-judgement',
                        'points'    => 40,
                    ),
                    array(
                        'title'       => __('Game: Vind de symbolen', 'sbdp'),
                        'content'     => __('Speel een mini-game en verzamel badges door symbolen te herkennen.', 'sbdp'),
                        'type'        => 'game',
                        'gamification'=> '{"mode":"collect","targets":5}',
                        'points'      => 35,
                    ),
                ),
            ),
            'den-bosch-1629' => array(
                'title'    => __('1629 Vestingstad Tour', 'sbdp'),
                'summary'  => __('Beleef het beleg van Den Bosch met historisch audiomateriaal en AR-slagenkaarten.', 'sbdp'),
                'duration' => 90,
                'price'    => 17.5,
                'steps'    => array(
                    array(
                        'title'     => __('Start bij het Sint-Jans Bolwerk', 'sbdp'),
                        'content'   => __('Luister naar de eerste aanloop van het beleg en plan je route.', 'sbdp'),
                        'type'      => 'audio',
                        'media_url' => 'https://example.com/audio/1629-bolwerk.mp3',
                        'points'    => 20,
                    ),
                    array(
                        'title'    => __('AR Kaart van de vesting', 'sbdp'),
                        'content'  => __('Bekijk de AR-kaart en zoom in op de verdedigingslinie.', 'sbdp'),
                        'type'     => 'vr',
                        'vr_asset' => 'https://example.com/ar/vestingkaart',
                        'points'   => 30,
                    ),
                    array(
                        'title'       => __('Strategie simulatie', 'sbdp'),
                        'content'     => __('Neem het commando over en verdedig de stad in een korte simulatie.', 'sbdp'),
                        'type'        => 'game',
                        'gamification'=> '{"mode":"simulation","duration":10}',
                        'points'      => 45,
                    ),
                ),
            ),
            'oeteldonk-immersive' => array(
                'title'    => __('Oeteldonk Carnavalsbeleving', 'sbdp'),
                'summary'  => __('Carnaval in full colour met AR, riddles en een dans-challenge.', 'sbdp'),
                'duration' => 60,
                'price'    => 14.5,
                'steps'    => array(
                    array(
                        'title'     => __('Verken de historie', 'sbdp'),
                        'content'   => __('Ontdek de oorsprong van Oeteldonk met animaties en audioverhalen.', 'sbdp'),
                        'type'      => 'video',
                        'media_url' => 'https://example.com/video/oeteldonk-history.mp4',
                        'points'    => 20,
                    ),
                    array(
                        'title'    => __('AR Kostuum pas-sessie', 'sbdp'),
                        'content'  => __('Pas virtueel verschillende kostuums en deel een snapshot.', 'sbdp'),
                        'type'     => 'vr',
                        'vr_asset' => 'https://example.com/ar/oeteldonk-outfits',
                        'points'   => 25,
                    ),
                    array(
                        'title'       => __('Dans battle gamification', 'sbdp'),
                        'content'     => __('Doe mee aan de dansuitdaging en verzamel XP.', 'sbdp'),
                        'type'        => 'game',
                        'gamification'=> '{"mode":"dance","levels":3}',
                        'points'      => 50,
                    ),
                ),
            ),
        );

        foreach ($tours as $slug => $config) {
            $existing = get_page_by_path($slug, OBJECT, 'sbdp_private_tour');
            if ($existing instanceof WP_Post) {
                continue;
            }

            $tour_id = wp_insert_post(
                array(
                    'post_type'    => 'sbdp_private_tour',
                    'post_status'  => 'publish',
                    'post_title'   => $config['title'],
                    'post_name'    => $slug,
                    'post_content' => $config['summary'],
                )
            );

            if (is_wp_error($tour_id) || ! $tour_id) {
                continue;
            }

            update_post_meta($tour_id, '_sbdp_tour_summary', $config['summary']);
            update_post_meta($tour_id, '_sbdp_tour_duration', (int) $config['duration']);
            update_post_meta($tour_id, '_sbdp_tour_chapter_count', count($config['steps']));

            $product_id = self::maybe_create_product($slug, $config);
            if ($product_id > 0) {
                update_post_meta($tour_id, '_sbdp_tour_product_id', $product_id);
            }

            foreach ($config['steps'] as $order => $step) {
                $step_id = wp_insert_post(
                    array(
                        'post_type'    => 'sbdp_private_tour_step',
                        'post_status'  => 'publish',
                        'post_title'   => $step['title'],
                        'post_content' => $step['content'],
                        'post_parent'  => $tour_id,
                        'menu_order'   => $order,
                    )
                );

                if (is_wp_error($step_id) || ! $step_id) {
                    continue;
                }

                update_post_meta($step_id, '_sbdp_step_type', SBDP_Private_Tours::sanitize_step_type($step['type'] ?? 'text'));

                if (! empty($step['media_url'])) {
                    update_post_meta($step_id, '_sbdp_step_media_url', esc_url_raw($step['media_url']));
                }

                if (! empty($step['vr_asset'])) {
                    update_post_meta($step_id, '_sbdp_step_vr_asset', esc_url_raw($step['vr_asset']));
                }

                if (! empty($step['gamification'])) {
                    update_post_meta($step_id, '_sbdp_step_gamification', SBDP_Private_Tours::sanitize_json_meta($step['gamification']));
                }

                if (! empty($step['points'])) {
                    update_post_meta($step_id, '_sbdp_step_points', absint($step['points']));
                }
            }
        }
    }

    /**
     * Create linked WooCommerce products when available.
     *
     * @param string               $slug   Product slug.
     * @param array<string, mixed> $config Tour config.
     *
     * @return int
     */
    private static function maybe_create_product(string $slug, array $config): int
    {
        if (! class_exists('WooCommerce')) {
            return 0;
        }

        $product = get_page_by_path($slug, OBJECT, 'product');
        if ($product instanceof WP_Post) {
            return (int) $product->ID;
        }

        $product_id = wp_insert_post(
            array(
                'post_type'    => 'product',
                'post_status'  => 'publish',
                'post_title'   => $config['title'],
                'post_name'    => $slug,
                'post_excerpt' => $config['summary'],
            )
        );

        if (is_wp_error($product_id) || ! $product_id) {
            return 0;
        }

        wp_set_object_terms($product_id, 'simple', 'product_type');
        update_post_meta($product_id, '_price', $config['price']);
        update_post_meta($product_id, '_regular_price', $config['price']);
        update_post_meta($product_id, '_virtual', 'yes');
        update_post_meta($product_id, '_downloadable', 'no');

        return (int) $product_id;
    }
}

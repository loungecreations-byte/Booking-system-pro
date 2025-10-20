<?php

declare(strict_types=1);

namespace BSP\Sales\Pricing;

use function apply_filters;
use function __;

final class PricingPresetRegistry
{
    /**
     * Return the list of predefined dynamic pricing presets.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        $presets = self::getPresets();

        return array_values($presets);
    }

    /**
     * Retrieve a preset by key.
     */
    public static function get(string $key): ?array
    {
        $presets = self::getPresets();

        return $presets[$key] ?? null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function getPresets(): array
    {
        $presets = array(
            'last_minute_softener' => array(
                'key'         => 'last_minute_softener',
                'name'        => __( 'Last-minute softener', 'sbdp' ),
                'description' => __( 'Verlaagt prijzen wanneer de bezetting laag is binnen 48 uur voor vertrek.', 'sbdp' ),
                'conditions'  => array(
                    'mode'       => 'all',
                    'conditions' => array(
                        array(
                            'metric'   => 'occupancy',
                            'operator' => '<=',
                            'value'    => 0.4,
                        ),
                    ),
                ),
                'adjustment'  => array(
                    'type'      => 'percentage',
                    'value'     => -12,
                    'rounding'  => 'down',
                    'precision' => 2,
                ),
                'priority'    => 80,
                'active'      => 1,
            ),
            'peak_weekend_surge' => array(
                'key'         => 'peak_weekend_surge',
                'name'        => __( 'Peak weekend uplift', 'sbdp' ),
                'description' => __( 'Voegt een toeslag toe tijdens populaire weekenddagen in het hoogseizoen.', 'sbdp' ),
                'conditions'  => array(
                    'mode'       => 'all',
                    'conditions' => array(
                        array(
                            'metric'   => 'season',
                            'operator' => 'equals',
                            'value'    => 'summer',
                        ),
                        array(
                            'metric'   => 'weekday',
                            'operator' => 'in',
                            'value'    => array( 'friday', 'saturday' ),
                        ),
                    ),
                ),
                'adjustment'  => array(
                    'type'      => 'percentage',
                    'value'     => 15,
                    'rounding'  => 'up',
                    'precision' => 2,
                ),
                'priority'    => 120,
                'active'      => 1,
            ),
            'weather_relief' => array(
                'key'         => 'weather_relief',
                'name'        => __( 'Bad-weather relief', 'sbdp' ),
                'description' => __( 'Stimuleert boekingen wanneer de weersverwachting ongunstig is.', 'sbdp' ),
                'conditions'  => array(
                    'mode'       => 'any',
                    'conditions' => array(
                        array(
                            'metric'   => 'weather',
                            'operator' => 'in',
                            'value'    => array( 'rain', 'storm' ),
                        ),
                    ),
                ),
                'adjustment'  => array(
                    'type'      => 'percentage',
                    'value'     => -5,
                    'rounding'  => 'down',
                    'precision' => 2,
                ),
                'priority'    => 70,
                'active'      => 1,
            ),
            'high_occupancy_surge' => array(
                'key'         => 'high_occupancy_surge',
                'name'        => __( 'High-occupancy surge', 'sbdp' ),
                'description' => __( 'Verhoogt prijzen automatisch zodra de capaciteit grotendeels gevuld is.', 'sbdp' ),
                'conditions'  => array(
                    'mode'       => 'all',
                    'conditions' => array(
                        array(
                            'metric'   => 'occupancy',
                            'operator' => '>=',
                            'value'    => 0.85,
                        ),
                    ),
                ),
                'adjustment'  => array(
                    'type'      => 'percentage',
                    'value'     => 12,
                    'rounding'  => 'up',
                    'precision' => 2,
                ),
                'priority'    => 110,
                'active'      => 1,
            ),
        );

        /**
         * Allow third parties to modify available presets.
         */
        return apply_filters( 'bsp/sales/pricing/presets', $presets );
    }
}

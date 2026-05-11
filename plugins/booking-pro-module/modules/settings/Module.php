<?php

declare(strict_types=1);

namespace BSP\Settings;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;

/**
 * Booking settings module responsible for exposing configuration defaults.
 */
final class Module implements ModuleInterface
{
    private SettingsRegistry $registry;

    private SettingsExporter $exporter;

    public function __construct(
        ?SettingsRegistry $registry = null,
        ?SettingsExporter $exporter = null
    ) {
        $this->registry = $registry ?? new SettingsRegistry();
        $this->exporter = $exporter ?? new SettingsExporter(CoreServiceProvider::logger());
    }

    public function init(): void
    {
        $this->registerDefinitions();

        $this->exporter->export(
            $this->registry,
            array(
                'booking_duration',
                'duration_unit',
                'max_bookings_per_unit',
                'requires_confirmation',
                'allow_cancellation',
                'cancellation_days_limit',
                'buffer_between_bookings',
                'duration_price_adjustment',
            ),
            'modules/settings/config.json',
            true
        );
    }

    private function registerDefinitions(): void
    {
        $this->registry->register(
            array(
                'key'         => 'booking_duration',
                'type'        => 'select',
                'label'       => 'Boekingsduur',
                'options'     => array('vast', 'variabel'),
                'default'     => 'vast',
                'description' => 'Stel vaste of variabele duur in (bijv. 1 dag of 30 minuten).',
            )
        );

        $this->registry->register(
            array(
                'key'         => 'duration_unit',
                'type'        => 'select',
                'label'       => 'Eenheidstype',
                'options'     => array('per_dag', 'per_nacht', 'per_uur'),
                'default'     => 'per_dag',
                'description' => "Kies tussen 'per dag', 'per nacht' of 'per uur'.",
            )
        );

        $this->registry->register(
            array(
                'key'         => 'max_bookings_per_unit',
                'type'        => 'number',
                'label'       => 'Max. aantal boekingen per tijdseenheid',
                'min'         => 1,
                'max'         => 100,
                'default'     => 1,
                'description' => 'Beperk het aantal gelijktijdige reserveringen voor capaciteitsbeheer.',
            )
        );

        $this->registry->register(
            array(
                'key'         => 'requires_confirmation',
                'type'        => 'toggle',
                'label'       => 'Bevestiging vereisen',
                'default'     => false,
                'description' => 'Optie: klant boekt en admin bevestigt handmatig voor meer controle.',
            )
        );

        $this->registry->register(
            array(
                'key'         => 'allow_cancellation',
                'type'        => 'toggle',
                'label'       => 'Annulering toestaan',
                'default'     => true,
                'description' => 'Sta klanten toe hun boeking te annuleren binnen een bepaalde periode.',
            )
        );

        $this->registry->register(
            array(
                'key'         => 'cancellation_days_limit',
                'type'        => 'number',
                'label'       => 'Annuleringstermijn (dagen)',
                'min'         => 0,
                'max'         => 30,
                'default'     => 2,
                'description' => 'Instellen hoeveel dagen op voorhand geannuleerd mag worden.',
            )
        );

        $this->registry->register(
            array(
                'key'         => 'buffer_between_bookings',
                'type'        => 'number',
                'label'       => 'Buffer tussen boekingen (uren)',
                'min'         => 0,
                'max'         => 24,
                'default'     => 1,
                'description' => 'Pauze tussen boekingen voor schoonmaak of voorbereiding.',
            )
        );

        $this->registry->register(
            array(
                'key'         => 'duration_price_adjustment',
                'type'        => 'slider',
                'label'       => 'Prijsaanpassing per duur',
                'min'         => -50,
                'max'         => 50,
                'step'        => 5,
                'unit'        => '%',
                'default'     => 0,
                'description' => 'Stel prijsaanpassing in op basis van boekingsduur (korting of toeslag).',
            )
        );
    }
}

if (! \class_exists('BSPModule\\Settings\\Module', false)) {
    \class_alias(Module::class, 'BSPModule\\Settings\\Module');
}

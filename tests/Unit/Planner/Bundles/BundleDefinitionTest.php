<?php
declare(strict_types=1);

namespace BSP\Tests\Unit\Planner\Bundles;

use BSP\Planner\Bundles\BundleDefinition;
use PHPUnit\Framework\TestCase;

final class BundleDefinitionTest extends TestCase
{
    public function testToPayloadMergesOverrides(): void
    {
        $definition = BundleDefinition::fromArray(array(
            'id'      => 'BND-1',
            'label'   => 'Morning Bundle',
            'items'   => array(
                array('product_id' => 42),
            ),
            'meta'    => array(
                'description' => 'Base description',
            ),
            'payload' => array(
                'mode'         => 'pay',
                'participants' => 4,
                'meta'         => array(
                    'note' => 'Prefer early slot',
                ),
            ),
        ));

        $payload = $definition->toPayload();

        $this->assertSame('pay', $payload['mode']);
        $this->assertSame('BND-1', $payload['bundle_id']);
        $this->assertSame(4, $payload['participants']);
        $this->assertSame(array(
            array('product_id' => 42),
        ), $payload['items']);

        $this->assertSame('Morning Bundle', $payload['meta']['bundle_label']);
        $this->assertSame('Base description', $payload['meta']['description']);
        $this->assertSame('Prefer early slot', $payload['meta']['note']);

        $array = $definition->toArray();
        $this->assertArrayHasKey('payload', $array);
        $this->assertSame($payload, $array['payload']);
    }

    public function testBundleLabelAndIdCannotBeOverridden(): void
    {
        $definition = BundleDefinition::fromArray(array(
            'id'      => 'LOCKED',
            'label'   => 'Canonical Label',
            'payload' => array(
                'bundle_id' => 'IGNORED',
                'meta'      => array(
                    'bundle_label' => 'Override Label',
                ),
            ),
        ));

        $payload = $definition->toPayload();

        $this->assertSame('LOCKED', $payload['bundle_id']);
        $this->assertSame('Canonical Label', $payload['meta']['bundle_label']);
        $this->assertSame(array(
            'bundle_id' => 'IGNORED',
            'meta'      => array(
                'bundle_label' => 'Override Label',
            ),
        ), $definition->getPayloadOverrides());
    }
}


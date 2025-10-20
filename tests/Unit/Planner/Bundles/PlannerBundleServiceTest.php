<?php
declare(strict_types=1);

namespace BSP\Tests\Unit\Planner\Bundles;

use BSP\Planner\Bundles\BundleRegistry;
use BSP\Planner\Bundles\PlannerBundleService;
use PHPUnit\Framework\TestCase;

final class PlannerBundleServiceTest extends TestCase
{
    public function testExposeFrontendBundlesMergesRegistryBundles(): void
    {
        $registry = new BundleRegistry();
        $service  = new PlannerBundleService($registry);

        $registry->registerFromArray(array(
            'id'      => 'BND-1',
            'label'   => 'Registered Bundle',
            'meta'    => array(
                'description' => 'From registry',
            ),
            'payload' => array(
                'mode' => 'pay',
            ),
        ));

        $existing = array(
            array(
                'id'    => 'LEGACY',
                'label' => 'Legacy Bundle',
                'items' => array(),
                'meta'  => array(),
            ),
            array(
                'id'    => 'BND-1',
                'label' => 'Stub should be replaced',
                'items' => array(),
                'meta'  => array(
                    'description' => 'Stub',
                ),
            ),
        );

        $bundles = $service->exposeFrontendBundles($existing);

        $this->assertCount(2, $bundles);

        $indexed = array();
        foreach ($bundles as $bundle) {
            $indexed[$bundle['id']] = $bundle;
        }

        $this->assertArrayHasKey('LEGACY', $indexed);
        $this->assertArrayHasKey('BND-1', $indexed);
        $this->assertSame('Legacy Bundle', $indexed['LEGACY']['label']);
        $this->assertSame('Registered Bundle', $indexed['BND-1']['label']);
        $this->assertSame('From registry', $indexed['BND-1']['meta']['description']);
        $this->assertSame('pay', $indexed['BND-1']['payload']['mode']);

        $bundlesOnlyRegistry = $service->exposeFrontendBundles(null);
        $this->assertCount(1, $bundlesOnlyRegistry);
        $this->assertSame('BND-1', $bundlesOnlyRegistry[0]['id']);
    }
}


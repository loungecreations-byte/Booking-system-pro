<?php

declare(strict_types=1);

namespace BSP\Tests\Unit\Pricing;

use BSP\Sales\Pricing\YieldEngine;
use PHPUnit\Framework\TestCase;

final class YieldEngineTest extends TestCase {

	public function testGetMatchedRulesDefaultsToEmptyArray(): void {
		$this->assertSame( array(), YieldEngine::getMatchedRules() );
	}
}

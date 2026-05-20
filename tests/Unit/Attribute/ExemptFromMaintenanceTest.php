<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Attribute\ExemptFromMaintenance;
use Attribute;
use ReflectionClass;

final class ExemptFromMaintenanceTest extends TestCase
{
    public function testInstantiationWithoutArgs(): void
    {
        $attr = new ExemptFromMaintenance();
        self::assertNull($attr->id);
    }

    public function testInstantiationWithId(): void
    {
        $attr = new ExemptFromMaintenance(id: 'order-webhook');
        self::assertSame('order-webhook', $attr->id);
    }

    public function testAttributeTargetsClassAndMethod(): void
    {
        $ref = new ReflectionClass(ExemptFromMaintenance::class);
        $attr = $ref->getAttributes(Attribute::class)[0]->newInstance();
        self::assertSame(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD, $attr->flags);
    }
}

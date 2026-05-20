<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;

final class ActivationContextTest extends TestCase
{
    private function fakeStorage(): ContextStorageInterface
    {
        return new class implements ContextStorageInterface {
            /** @var array{reason: ?string, retry_after: ?int} */
            private array $state = ['reason' => null, 'retry_after' => null];

            public function load(): array
            {
                return $this->state;
            }

            public function save(?string $reason, ?int $retryAfter): void
            {
                $this->state = ['reason' => $reason, 'retry_after' => $retryAfter];
            }

            public function clear(): void
            {
                $this->state = ['reason' => null, 'retry_after' => null];
            }
        };
    }

    public function testDefaultsAreNull(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());

        self::assertNull($ctx->getReason());
        self::assertNull($ctx->getRetryAfter());
    }

    public function testSetReadsBack(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());

        $ctx->set('DB migration', 600);

        self::assertSame('DB migration', $ctx->getReason());
        self::assertSame(600, $ctx->getRetryAfter());
    }

    public function testClearWipesState(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        $ctx->set('x', 10);

        $ctx->clear();

        self::assertNull($ctx->getReason());
        self::assertNull($ctx->getRetryAfter());
    }
}

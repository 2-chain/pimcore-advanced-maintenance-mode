<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\HeartbeatCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;
use DateTimeImmutable;
use DateTimeInterface;

final class HeartbeatCommandTest extends TestCase
{
    private function fakeStorage(array $overrides = []): object
    {
        return new class ($overrides) implements ContextStorageInterface {
            public array $state;
            public ?array $lastUpdateExpiry = null;

            public function __construct(array $overrides)
            {
                $this->state = array_merge([
                    'reason'                            => null,
                    'retry_after'                       => null,
                    'activated_by_schedule_window_id'   => null,
                    'expected_end_at'                   => null,
                    'activated_by_health_check_failure' => false,
                    'activated_by_history_record_id'    => null,
                    'expires_at'                        => null,
                    'original_ttl_minutes'              => null,
                    'warning_emitted_at'                => null,
                ], $overrides);
            }

            public function load(): array
            {
                return $this->state;
            }

            public function save(
                ?string $reason,
                ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null,
                ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false,
                ?int $activatedByHistoryRecordId = null,
                ?string $expiresAt = null,
                ?int $originalTtlMinutes = null,
                ?string $warningEmittedAt = null,
            ): void {}

            public function updateExpiry(
                ?string $expiresAt,
                ?int $originalTtlMinutes,
                ?string $warningEmittedAt,
            ): void {
                $this->lastUpdateExpiry = [
                    'expires_at'           => $expiresAt,
                    'original_ttl_minutes' => $originalTtlMinutes,
                    'warning_emitted_at'   => $warningEmittedAt,
                ];
                $this->state['expires_at']           = $expiresAt;
                $this->state['original_ttl_minutes'] = $originalTtlMinutes;
                $this->state['warning_emitted_at']   = $warningEmittedAt;
            }

            public function saveScope(?array $scopeRaw): void {}

            public function clear(): void {}
        };
    }

    private function makeCommand(bool $isActive, array $storageOverrides = []): array
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn($isActive);

        $storage = $this->fakeStorage($storageOverrides);
        $context = new ActivationContext($storage);
        $command = new HeartbeatCommand($helper, $context, $this->createStub(LoggerInterface::class));

        return [$command, $storage];
    }

    public function testFailsWhenModeOff(): void
    {
        [$command] = $this->makeCommand(isActive: false);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Maintenance mode is not active.', $tester->getDisplay());
    }

    public function testFailsWhenActivatedByScheduleWindow(): void
    {
        [$command] = $this->makeCommand(isActive: true, storageOverrides: [
            'activated_by_schedule_window_id' => 'window-nightly',
            'expires_at' => (new DateTimeImmutable('+60 minutes UTC'))->format(DateTimeInterface::ATOM),
        ]);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Heartbeat is not applicable to schedule-activated maintenance.', $tester->getDisplay());
    }

    public function testFailsWhenNoTtlAndNoFlag(): void
    {
        [$command] = $this->makeCommand(isActive: true, storageOverrides: [
            'expires_at'           => null,
            'original_ttl_minutes' => null,
        ]);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No TTL is set on the current activation.', $tester->getDisplay());
    }

    public function testRenewsExistingTtl(): void
    {
        $before = new DateTimeImmutable('now UTC');

        [$command, $storage] = $this->makeCommand(isActive: true, storageOverrides: [
            'expires_at'           => (new DateTimeImmutable('+5 minutes UTC'))->format(DateTimeInterface::ATOM),
            'original_ttl_minutes' => 60,
        ]);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertNotNull($storage->lastUpdateExpiry);
        self::assertSame(60, $storage->lastUpdateExpiry['original_ttl_minutes']);
        self::assertNull($storage->lastUpdateExpiry['warning_emitted_at']);

        $newExpiry = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $storage->lastUpdateExpiry['expires_at']);
        self::assertNotFalse($newExpiry);
        $expectedMin = $before->modify('+60 minutes')->getTimestamp();
        self::assertGreaterThanOrEqual($expectedMin - 5, $newExpiry->getTimestamp());
        self::assertLessThanOrEqual($expectedMin + 5, $newExpiry->getTimestamp());

        $out = $tester->getDisplay();
        self::assertStringContainsString('Heartbeat recorded.', $out);
        self::assertStringContainsString('New expiry:', $out);
        self::assertStringContainsString('60 min', $out);
    }

    public function testExpiresInFlagOverridesTtl(): void
    {
        [$command, $storage] = $this->makeCommand(isActive: true, storageOverrides: [
            'expires_at'           => (new DateTimeImmutable('+5 minutes UTC'))->format(DateTimeInterface::ATOM),
            'original_ttl_minutes' => 60,
        ]);
        $tester = new CommandTester($command);

        $tester->execute(['--expires-in' => '90']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(90, $storage->lastUpdateExpiry['original_ttl_minutes']);
    }

    public function testExpiresInFlagAddsTtlWhenNoneSet(): void
    {
        [$command, $storage] = $this->makeCommand(isActive: true, storageOverrides: [
            'expires_at'           => null,
            'original_ttl_minutes' => null,
        ]);
        $tester = new CommandTester($command);

        $tester->execute(['--expires-in' => '30']);

        $tester->assertCommandIsSuccessful();
        self::assertNotNull($storage->lastUpdateExpiry);
        self::assertSame(30, $storage->lastUpdateExpiry['original_ttl_minutes']);
        self::assertNull($storage->lastUpdateExpiry['warning_emitted_at']);
    }

    public function testExpiresInMustBePositive(): void
    {
        [$command] = $this->makeCommand(isActive: true, storageOverrides: [
            'expires_at'           => (new DateTimeImmutable('+5 minutes UTC'))->format(DateTimeInterface::ATOM),
            'original_ttl_minutes' => 60,
        ]);
        $tester = new CommandTester($command);

        $tester->execute(['--expires-in' => '0']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--expires-in must be a positive integer', $tester->getDisplay());
    }
}

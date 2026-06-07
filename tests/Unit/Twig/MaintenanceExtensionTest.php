<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceBannerRenderer;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\PreAnnounceBannerProvider;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Twig\MaintenanceExtension;

final class MaintenanceExtensionTest extends TestCase
{
    private function context(?string $reason): ActivationContext
    {
        $storage = new class ($reason) implements ContextStorageInterface {
            public function __construct(private readonly ?string $reason) {}
            public function load(): array
            {
                return ['reason' => $this->reason, 'retry_after' => null, 'activated_by_schedule_window_id' => null, 'expected_end_at' => null, 'activated_by_health_check_failure' => false, 'activated_by_history_record_id' => null, 'expires_at' => null, 'original_ttl_minutes' => null, 'warning_emitted_at' => null];
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
            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}
            public function saveScope(?array $scopeRaw): void {}
            public function clear(): void {}
        };
        return new ActivationContext($storage);
    }

    private function contextWithScope(?array $scopeRaw): ActivationContext
    {
        $storage = new class ($scopeRaw) implements ContextStorageInterface {
            public function __construct(private readonly ?array $scopeRaw) {}
            public function load(): array
            {
                return [
                    'reason'                          => null,
                    'retry_after'                     => null,
                    'activated_by_schedule_window_id' => null,
                    'expected_end_at'                 => null,
                    'activated_by_health_check_failure' => false,
                    'activated_by_history_record_id'  => null,
                    'expires_at'                      => null,
                    'original_ttl_minutes'            => null,
                    'warning_emitted_at'              => null,
                    'scope'                           => $this->scopeRaw,
                ];
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
            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}
            public function saveScope(?array $scopeRaw): void {}
            public function clear(): void {}
        };
        return new ActivationContext($storage);
    }

    private function makeConfig(): BundleConfiguration
    {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            defaultTtl: null,
            expiryWarningThreshold: null,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: 60,
            urgencyOrangeMinutes: 30,
            urgencyRedMinutes: 10,
            dismissPersistence: 'session',
            mailOnPreAnnounce: false,
            mailOnMaintenanceStart: false,
            mailOnMaintenanceEnd: false,
            mailRecipients: [],
            mailOnPreAnnounceRecipients: [],
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            mailPreAnnounceTemplate: null,
            mailMaintenanceStartTemplate: null,
            mailMaintenanceEndTemplate: null,
            notificationWebhooks: [],
        );
    }

    private function makeExtension(?string $reason): MaintenanceExtension
    {
        return new MaintenanceExtension(
            $this->context($reason),
            $this->createStub(PreAnnounceBannerProvider::class),
            $this->createStub(PreAnnounceBannerRenderer::class),
            $this->makeConfig(),
        );
    }

    private function makeExtensionWithScopeAndProvider(
        ActivationContext $context,
        PreAnnounceBannerProvider $provider,
    ): MaintenanceExtension {
        return new MaintenanceExtension(
            $context,
            $provider,
            $this->createStub(PreAnnounceBannerRenderer::class),
            $this->makeConfig(),
        );
    }

    public function testMaintenanceReasonFunctionReturnsValue(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => "{{ maintenance_reason() ?? 'no reason' }}"]));
        $twig->addExtension($this->makeExtension('DB migration v3.5'));

        self::assertSame('DB migration v3.5', $twig->render('t'));
    }

    public function testMaintenanceReasonReturnsNullWhenUnset(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => "{{ maintenance_reason() ?? 'no reason' }}"]));
        $twig->addExtension($this->makeExtension(null));

        self::assertSame('no reason', $twig->render('t'));
    }

    public function testMaintenanceCountdownDataIncludesScope(): void
    {
        $scopeRaw = ['path_prefixes' => ['/shop'], 'site_ids' => [2]];
        $context = $this->contextWithScope($scopeRaw);

        $futureAt = new \DateTimeImmutable('+2 hours', new \DateTimeZone('UTC'));
        $preAnnounceData = new PreAnnounceData(
            at: $futureAt,
            timezone: 'UTC',
            reason: null,
            announceBeforeMinutes: null,
        );

        $provider = $this->createMock(PreAnnounceBannerProvider::class);
        $provider->method('provide')->willReturn($preAnnounceData);

        $ext = $this->makeExtensionWithScopeAndProvider($context, $provider);
        $data = $ext->maintenanceCountdownData();

        self::assertNotNull($data);
        self::assertFalse($data['scope']['global']);
        self::assertSame(['/shop'], $data['scope']['pathPrefixes']);
        self::assertSame([2], $data['scope']['siteIds']);
    }

    public function testMaintenanceCountdownDataGlobalScopeWhenNull(): void
    {
        $context = $this->contextWithScope(null);

        $futureAt = new \DateTimeImmutable('+2 hours', new \DateTimeZone('UTC'));
        $preAnnounceData = new PreAnnounceData(
            at: $futureAt,
            timezone: 'UTC',
            reason: null,
            announceBeforeMinutes: null,
        );

        $provider = $this->createMock(PreAnnounceBannerProvider::class);
        $provider->method('provide')->willReturn($preAnnounceData);

        $ext = $this->makeExtensionWithScopeAndProvider($context, $provider);
        $data = $ext->maintenanceCountdownData();

        self::assertNotNull($data);
        self::assertTrue($data['scope']['global']);
        self::assertSame([], $data['scope']['pathPrefixes']);
        self::assertSame([], $data['scope']['siteIds']);
    }
}

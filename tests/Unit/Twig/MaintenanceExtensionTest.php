<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceBannerProvider;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceBannerRenderer;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Twig\MaintenanceExtension;

final class MaintenanceExtensionTest extends TestCase
{
    private function context(?string $reason): ActivationContext
    {
        $storage = new class ($reason) implements ContextStorageInterface {
            public function __construct(private readonly ?string $reason) {}
            public function load(): array
            {
                return ['reason' => $this->reason, 'retry_after' => null];
            }
            public function save(
                ?string $reason,
                ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null,
                ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false,
                ?int $activatedByHistoryRecordId = null,
            ): void {}
            public function clear(): void {}
        };
        return new ActivationContext($storage);
    }

    private function makeExtension(?string $reason): MaintenanceExtension
    {
        return new MaintenanceExtension(
            $this->context($reason),
            $this->createStub(PreAnnounceBannerProvider::class),
            $this->createStub(PreAnnounceBannerRenderer::class),
            new BundleConfiguration(
                bypassAuthenticatedAdmins: false,
                defaultRetryAfter: null,
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
                notificationWebhooks: [],
            ),
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
}

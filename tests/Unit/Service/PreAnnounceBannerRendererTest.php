<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceBannerRenderer;

final class PreAnnounceBannerRendererTest extends TestCase
{
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

    private function makeData(string $reason = 'DB migration'): PreAnnounceData
    {
        return new PreAnnounceData(
            at: new \DateTimeImmutable('2026-07-01T10:00:00Z'),
            timezone: 'Europe/Berlin',
            reason: $reason,
            announceBeforeMinutes: null,
        );
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $renderer = new PreAnnounceBannerRenderer($this->makeConfig());
        $html = $renderer->render($this->makeData());
        self::assertNotEmpty($html);
    }

    public function testRenderContainsBannerDivId(): void
    {
        $renderer = new PreAnnounceBannerRenderer($this->makeConfig());
        $html = $renderer->render($this->makeData());
        self::assertStringContainsString('id="amm-banner"', $html);
    }

    public function testRenderContainsDataTargetAttribute(): void
    {
        $renderer = new PreAnnounceBannerRenderer($this->makeConfig());
        $html = $renderer->render($this->makeData());
        self::assertStringContainsString('data-target="2026-07-01T10:00:00+00:00"', $html);
    }

    public function testRenderContainsDataReasonAttribute(): void
    {
        $renderer = new PreAnnounceBannerRenderer($this->makeConfig());
        $html = $renderer->render($this->makeData('DB migration'));
        self::assertStringContainsString('data-reason="DB migration"', $html);
    }

    public function testRenderEscapesReasonHtml(): void
    {
        $renderer = new PreAnnounceBannerRenderer($this->makeConfig());
        $html = $renderer->render($this->makeData('<script>alert(1)</script>'));
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderContainsDismissKey(): void
    {
        $renderer = new PreAnnounceBannerRenderer($this->makeConfig());
        $at = new \DateTimeImmutable('2026-07-01T10:00:00Z');
        $html = $renderer->render(new PreAnnounceData($at, 'UTC', null, null));
        self::assertStringContainsString('data-dismiss-key="amm_dismissed_' . $at->getTimestamp() . '"', $html);
    }

    public function testRenderContainsScriptTag(): void
    {
        $renderer = new PreAnnounceBannerRenderer($this->makeConfig());
        $html = $renderer->render($this->makeData());
        self::assertStringContainsString('<script>', $html);
        self::assertStringContainsString('setInterval', $html);
    }

    public function testRenderContainsUrgencyDataAttributes(): void
    {
        $renderer = new PreAnnounceBannerRenderer($this->makeConfig());
        $html = $renderer->render($this->makeData());
        self::assertStringContainsString('data-orange-minutes="30"', $html);
        self::assertStringContainsString('data-red-minutes="10"', $html);
    }
}

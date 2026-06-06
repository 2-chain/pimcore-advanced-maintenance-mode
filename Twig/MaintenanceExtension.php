<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Twig;

use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceBannerRenderer;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\PreAnnounceBannerProvider;

final class MaintenanceExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActivationContext $context,
        private readonly PreAnnounceBannerProvider $provider,
        private readonly PreAnnounceBannerRenderer $renderer,
        private readonly BundleConfiguration $config,
    ) {}

    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('maintenance_reason', $this->maintenanceReason(...)),
            new TwigFunction('maintenance_retry_after', $this->maintenanceRetryAfter(...)),
            new TwigFunction('maintenance_countdown', $this->maintenanceCountdown(...), ['is_safe' => ['html']]),
            new TwigFunction('maintenance_pre_announce_at', $this->maintenancePreAnnounceAt(...)),
            new TwigFunction('maintenance_countdown_data', $this->maintenanceCountdownData(...)),
        ];
    }

    public function maintenanceReason(): ?string
    {
        return $this->context->getReason();
    }

    public function maintenanceRetryAfter(): ?int
    {
        return $this->context->getRetryAfter();
    }

    public function maintenanceCountdown(): ?string
    {
        $data = $this->provider->provide();
        if ($data === null) {
            return null;
        }
        $html = $this->renderer->render($data);
        $this->provider->markRendered();
        return $html;
    }

    public function maintenancePreAnnounceAt(): ?\DateTimeImmutable
    {
        return $this->provider->provide()?->at;
    }

    public function maintenanceCountdownData(): ?array
    {
        $data = $this->provider->provide();
        if ($data === null) {
            return null;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $seconds = $data->at->getTimestamp() - $now->getTimestamp();
        $seconds = \max(0, $seconds);

        $orange  = $this->config->urgencyOrangeMinutes * 60;
        $red     = $this->config->urgencyRedMinutes * 60;
        $urgency = match (true) {
            $seconds <= $red    => 'red',
            $seconds <= $orange => 'orange',
            default             => 'normal',
        };

        $atInTz = $data->at->setTimezone(new \DateTimeZone($data->timezone));

        return [
            'at'               => $data->at,
            'atFormatted'      => $atInTz->format('Y-m-d H:i:s') . ' ' . $data->timezone,
            'reason'           => $data->reason,
            'secondsRemaining' => $seconds,
            'urgencyLevel'     => $urgency,
            'dismissKey'       => 'amm_dismissed_' . $data->at->getTimestamp(),
            'dismissStorage'   => $this->config->dismissPersistence === 'local' ? 'local' : 'session',
            'scope'            => $this->scopeToArray($this->context->getScope()),
        ];
    }

    /** @return array{global: bool, pathPrefixes: string[], siteIds: int[]} */
    private function scopeToArray(?MaintenanceScope $scope): array
    {
        if ($scope === null || $scope->isGlobal()) {
            return ['global' => true, 'pathPrefixes' => [], 'siteIds' => []];
        }
        return [
            'global'       => false,
            'pathPrefixes' => $scope->pathPrefixes,
            'siteIds'      => $scope->siteIds,
        ];
    }
}

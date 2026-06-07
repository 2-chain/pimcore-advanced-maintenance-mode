<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;

final class BundleConfiguration
{
    public readonly ?MaintenanceScope $defaultScope;

    /**
     * @param list<string> $mailRecipients
     * @param list<string> $mailOnPreAnnounceRecipients
     * @param list<string> $mailOnMaintenanceStartRecipients
     * @param list<string> $mailOnMaintenanceEndRecipients
     * @param list<string> $notificationWebhooks
     * @param ?array{path_prefixes: array<string>, site_ids: array<int>} $defaultScopeData
     */
    public function __construct(
        public readonly bool $bypassAuthenticatedAdmins,
        public readonly ?int $defaultRetryAfter,
        public readonly ?int $defaultTtl,
        public readonly ?int $expiryWarningThreshold,
        public readonly bool $publicStatusEnabled,
        public readonly ?string $publicStatusToken,
        // Banner
        public readonly bool $autoInjectBanner,
        public readonly ?int $defaultThresholdMinutes,
        public readonly int $urgencyOrangeMinutes,
        public readonly int $urgencyRedMinutes,
        public readonly string $dismissPersistence,
        // Mail
        public readonly bool $mailOnPreAnnounce,
        public readonly bool $mailOnMaintenanceStart,
        public readonly bool $mailOnMaintenanceEnd,
        public readonly array $mailRecipients,
        public readonly array $mailOnPreAnnounceRecipients,
        public readonly array $mailOnMaintenanceStartRecipients,
        public readonly array $mailOnMaintenanceEndRecipients,
        public readonly ?string $mailTemplate,
        public readonly ?string $mailPreAnnounceTemplate,
        public readonly ?string $mailMaintenanceStartTemplate,
        public readonly ?string $mailMaintenanceEndTemplate,
        // Webhooks
        public readonly array $notificationWebhooks,
        ?array $defaultScopeData = null,
    ) {
        $this->defaultScope = $defaultScopeData !== null && (!empty($defaultScopeData['path_prefixes']) || !empty($defaultScopeData['site_ids']))
            ? new MaintenanceScope($defaultScopeData['path_prefixes'], $defaultScopeData['site_ids'])
            : null;
    }
}

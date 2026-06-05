<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

final readonly class BundleConfiguration
{
    /**
     * @param list<string> $mailRecipients
     * @param list<string> $mailOnPreAnnounceRecipients
     * @param list<string> $mailOnMaintenanceStartRecipients
     * @param list<string> $mailOnMaintenanceEndRecipients
     * @param list<string> $notificationWebhooks
     */
    public function __construct(
        public bool $bypassAuthenticatedAdmins,
        public ?int $defaultRetryAfter,
        public bool $publicStatusEnabled,
        public ?string $publicStatusToken,
        // Banner
        public bool $autoInjectBanner,
        public ?int $defaultThresholdMinutes,
        public int $urgencyOrangeMinutes,
        public int $urgencyRedMinutes,
        public string $dismissPersistence,
        // Mail
        public bool $mailOnPreAnnounce,
        public bool $mailOnMaintenanceStart,
        public bool $mailOnMaintenanceEnd,
        public array $mailRecipients,
        public array $mailOnPreAnnounceRecipients,
        public array $mailOnMaintenanceStartRecipients,
        public array $mailOnMaintenanceEndRecipients,
        public ?string $mailTemplate,
        // Webhooks
        public array $notificationWebhooks,
    ) {}
}

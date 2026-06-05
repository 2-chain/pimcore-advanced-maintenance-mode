<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use JsonException;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleHistoryRepository;

#[ORM\Entity(repositoryClass: ScheduleHistoryRepository::class)]
#[ORM\Table(name: 'advanced_maintenance_schedule_history')]
#[ORM\HasLifecycleCallbacks]
class ScheduleHistoryRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'schedule_window_id', type: 'string', length: 36)]
    private string $scheduleWindowId;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'duration_minutes', type: 'integer', nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(name: 'configured_duration_minutes', type: 'integer', nullable: true)]
    private ?int $configuredDurationMinutes = null;

    #[ORM\Column(name: 'type', type: 'string', length: 16, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(name: 'reason', type: 'string', length: 500, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'scope_path_prefixes', type: 'text', nullable: true)]
    private ?string $scopePathPrefixesRaw = null;

    #[ORM\Column(name: 'scope_site_ids', type: 'text', nullable: true)]
    private ?string $scopeSiteIdsRaw = null;

    #[ORM\Column(name: 'ended_reason', type: 'string', length: 64, nullable: true)]
    private ?string $endedReason = null;

    private function __construct(
        string $scheduleWindowId,
        DateTimeImmutable $startedAt,
        ?int $configuredDurationMinutes,
        ?string $type,
        ?string $reason,
        ?string $scopePathPrefixesRaw,
        ?string $scopeSiteIdsRaw,
    ) {
        $this->scheduleWindowId        = $scheduleWindowId;
        $this->startedAt               = $startedAt;
        $this->configuredDurationMinutes = $configuredDurationMinutes;
        $this->type                    = $type;
        $this->reason                  = $reason;
        $this->scopePathPrefixesRaw    = $scopePathPrefixesRaw;
        $this->scopeSiteIdsRaw         = $scopeSiteIdsRaw;
    }

    /**
     * @param array<string>|null $scopePathPrefixes
     * @param array<int>|null    $scopeSiteIds
     *
     * @throws JsonException
     */
    public static function create(
        string $scheduleWindowId,
        DateTimeImmutable $startedAt,
        string $type,
        ?string $reason,
        ?int $configuredDurationMinutes,
        ?array $scopePathPrefixes = null,
        ?array $scopeSiteIds = null,
    ): self {
        return new self(
            scheduleWindowId:          $scheduleWindowId,
            startedAt:                 $startedAt,
            configuredDurationMinutes: $configuredDurationMinutes,
            type:                      $type,
            reason:                    $reason,
            scopePathPrefixesRaw:      $scopePathPrefixes !== null
                ? json_encode($scopePathPrefixes, JSON_THROW_ON_ERROR)
                : null,
            scopeSiteIdsRaw:           $scopeSiteIds !== null
                ? json_encode($scopeSiteIds, JSON_THROW_ON_ERROR)
                : null,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScheduleWindowId(): string
    {
        return $this->scheduleWindowId;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?DateTimeImmutable $endedAt): void
    {
        $this->endedAt = $endedAt;
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): void
    {
        $this->durationMinutes = $durationMinutes;
    }

    public function getConfiguredDurationMinutes(): ?int
    {
        return $this->configuredDurationMinutes;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** @return array<string>|null */
    public function getScopePathPrefixes(): ?array
    {
        if ($this->scopePathPrefixesRaw === null) {
            return null;
        }
        return json_decode($this->scopePathPrefixesRaw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<int>|null */
    public function getScopeIds(): ?array
    {
        if ($this->scopeSiteIdsRaw === null) {
            return null;
        }
        return json_decode($this->scopeSiteIdsRaw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function getEndedReason(): ?string
    {
        return $this->endedReason;
    }

    public function setEndedReason(?string $endedReason): void
    {
        $this->endedReason = $endedReason;
    }

    public function isInProgress(): bool
    {
        return $this->endedAt === null;
    }

    /**
     * Doctrine reads datetime columns using PHP's default timezone, but we always
     * store UTC values. Re-interpret the loaded datetimes as UTC so that duration
     * calculations and API responses are correct regardless of server timezone.
     */
    #[ORM\PostLoad]
    public function forceUtcTimezones(): void
    {
        $utc = new DateTimeZone('UTC');
        $this->startedAt = new DateTimeImmutable($this->startedAt->format('Y-m-d H:i:s'), $utc);
        if ($this->endedAt !== null) {
            $this->endedAt = new DateTimeImmutable($this->endedAt->format('Y-m-d H:i:s'), $utc);
        }
    }
}

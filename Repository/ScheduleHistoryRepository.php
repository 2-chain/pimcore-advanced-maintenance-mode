<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Override;
use RuntimeException;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Entity\ScheduleHistoryRecord;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ScheduleHistoryRepositoryInterface;

final class ScheduleHistoryRepository implements ScheduleHistoryRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * @throws JsonException
     */
    #[Override]
    public function insertStart(
        string $scheduleWindowId,
        DateTimeImmutable $startedAt,
        string $type,
        ?string $reason,
        ?int $configuredDurationMinutes,
        ?array $scopePathPrefixes = null,
        ?array $scopeSiteIds = null,
    ): int {
        $record = ScheduleHistoryRecord::create(
            scheduleWindowId:          $scheduleWindowId,
            startedAt:                 $startedAt,
            type:                      $type,
            reason:                    $reason,
            configuredDurationMinutes: $configuredDurationMinutes,
            scopePathPrefixes:         $scopePathPrefixes,
            scopeSiteIds:              $scopeSiteIds,
        );

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return (int) $record->getId();
    }

    #[Override]
    public function updateEnd(int $historyId, DateTimeImmutable $endedAt, ?string $endedReason = null): void
    {
        $record = $this->entityManager->find(ScheduleHistoryRecord::class, $historyId);

        if ($record === null) {
            throw new RuntimeException(sprintf('No history record with id %d found.', $historyId));
        }

        $startedAt       = $record->getStartedAt()->setTimezone(new DateTimeZone('UTC'));
        $durationMinutes = (int) floor(($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 60);

        $record->setEndedAt($endedAt);
        $record->setDurationMinutes($durationMinutes);
        $record->setEndedReason($endedReason);

        $this->entityManager->flush();
    }

    #[Override]
    public function findInProgressIdByWindowId(string $windowId): ?int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(ScheduleHistoryRecord::class, 'r')
            ->where('r.scheduleWindowId = :windowId')
            ->andWhere('r.endedAt IS NULL')
            ->setParameter('windowId', $windowId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null ? (int) $result['id'] : null;
    }

    /** @return ScheduleHistoryRecord[] */
    #[Override]
    public function findPaginated(
        int $page,
        int $pageSize,
        ?string $scheduleWindowId = null,
        ?DateTimeImmutable $startedAfter = null,
        ?DateTimeImmutable $startedBefore = null,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ScheduleHistoryRecord::class, 'r')
            ->orderBy('r.startedAt', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        if ($scheduleWindowId !== null) {
            $qb->andWhere('r.scheduleWindowId = :swid')->setParameter('swid', $scheduleWindowId);
        }
        if ($startedAfter !== null) {
            $qb->andWhere('r.startedAt >= :after')->setParameter('after', $startedAfter);
        }
        if ($startedBefore !== null) {
            $qb->andWhere('r.startedAt <= :before')->setParameter('before', $startedBefore);
        }

        return $qb->getQuery()->getResult();
    }

    #[Override]
    public function count(?string $scheduleWindowId = null): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ScheduleHistoryRecord::class, 'r');

        if ($scheduleWindowId !== null) {
            $qb->andWhere('r.scheduleWindowId = :swid')->setParameter('swid', $scheduleWindowId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    #[Override]
    public function isInProgress(int $id): bool
    {
        $record = $this->entityManager->find(ScheduleHistoryRecord::class, $id);

        return $record !== null && $record->isInProgress();
    }
}

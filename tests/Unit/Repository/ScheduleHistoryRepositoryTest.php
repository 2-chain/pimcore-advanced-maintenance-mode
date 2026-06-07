<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Entity\ScheduleHistoryRecord;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleHistoryRepository;
use Override;
use RuntimeException;

final class ScheduleHistoryRepositoryTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;
    private ScheduleHistoryRepository $repo;

    #[Override]
    protected function setUp(): void
    {
        $this->em   = $this->createMock(EntityManagerInterface::class);
        $this->repo = new ScheduleHistoryRepository($this->em);
    }

    public function testInsertStartPersistsAndFlushesAndReturnsId(): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(ScheduleHistoryRecord::class));
        $this->em->expects($this->once())->method('flush');

        $id = $this->repo->insertStart(
            scheduleWindowId: 'win-1',
            startedAt: new DateTimeImmutable('2026-06-02T10:00:00Z'),
            type: 'one-time',
            reason: 'Test',
            configuredDurationMinutes: 30,
        );

        // id is null because the mock EntityManager doesn't set it via Doctrine identity map
        self::assertSame(0, $id);
    }

    public function testUpdateEndFindRecordAndFlushes(): void
    {
        $started = new DateTimeImmutable('2026-06-02T10:00:00Z', new DateTimeZone('UTC'));
        $ended   = new DateTimeImmutable('2026-06-02T11:00:00Z', new DateTimeZone('UTC'));

        $record = ScheduleHistoryRecord::create('win-2', $started, 'one-time', null, 60);

        $this->em->expects($this->once())->method('find')
            ->with(ScheduleHistoryRecord::class, 42)
            ->willReturn($record);

        $this->em->expects($this->once())->method('flush');

        $this->repo->updateEnd(42, $ended);

        self::assertSame($ended, $record->getEndedAt());
        self::assertSame(60, $record->getDurationMinutes());
    }

    public function testUpdateEndThrowsWhenRecordNotFound(): void
    {
        $this->em->expects($this->once())->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No history record with id 99 found.');

        $this->repo->updateEnd(99, new DateTimeImmutable('2026-06-02T11:00:00Z'));
    }

    public function testFindPaginatedBuildsQueryWithFilters(): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->expects($this->once())->method('createQueryBuilder')->willReturn($qb);

        $result = $this->repo->findPaginated(1, 25, 'win-a');
        self::assertSame([], $result);
    }

    public function testCountReturnsScalarResult(): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getSingleScalarResult')->willReturn(7);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->expects($this->once())->method('createQueryBuilder')->willReturn($qb);

        self::assertSame(7, $this->repo->count('win-x'));
    }
}

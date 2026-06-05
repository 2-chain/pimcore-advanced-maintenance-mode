<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use UnexpectedValueException;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;

class ScheduleStorage
{
    private const KEY = 'advanced_maintenance_schedule_windows';

    /** @return ScheduleWindow[] */
    public function findAll(): array
    {
        $raw = $this->tmpStoreGet(self::KEY);
        if ($raw === null) {
            return [];
        }

        return array_values(array_map($this->hydrate(...), $raw));
    }

    public function findById(string $id): ?ScheduleWindow
    {
        foreach ($this->findAll() as $w) {
            if ($w->id === $id) {
                return $w;
            }
        }
        return null;
    }

    public function add(ScheduleWindow $window): void
    {
        if ($this->findById($window->id) !== null) {
            throw new InvalidArgumentException(sprintf('Schedule window with id "%s" already exists.', $window->id));
        }

        $all = $this->findAll();
        $all[] = $window;
        $this->persist($all);
    }

    public function remove(string $id): void
    {
        $all = array_filter($this->findAll(), static fn(ScheduleWindow $w) => $w->id !== $id);
        $this->persist(array_values($all));
    }

    /** @param ScheduleWindow[] $windows */
    public function replaceAll(array $windows): void
    {
        $this->persist($windows);
    }

    /** @param ScheduleWindow[] $windows */
    private function persist(array $windows): void
    {
        $this->tmpStoreSet(self::KEY, array_map($this->serialize(...), $windows));
    }

    private function serialize(ScheduleWindow $w): array
    {
        return [
            'id'                      => $w->id,
            'timezone'                => $w->timezone,
            'reason'                  => $w->reason,
            'from'                    => $w->from?->format(DateTimeInterface::ATOM),
            'to'                      => $w->to?->format(DateTimeInterface::ATOM),
            'cron_expression'         => $w->cronExpression,
            'duration_minutes'        => $w->durationMinutes,
            'announce_before_minutes' => $w->announceBeforeMinutes,
            'created_by_user_id'      => $w->createdByUserId,
            'created_by_username'     => $w->createdByUsername,
        ];
    }

    private function hydrate(array $row): ScheduleWindow
    {
        return new ScheduleWindow(
            id: $row['id'],
            timezone: $row['timezone'],
            reason: $row['reason'] ?? null,
            from: $this->parseDateTime($row['from'] ?? null),
            to: $this->parseDateTime($row['to'] ?? null),
            cronExpression: $row['cron_expression'] ?? null,
            durationMinutes: $row['duration_minutes'] ?? null,
            announceBeforeMinutes: (int) ($row['announce_before_minutes'] ?? 0),
            createdByUserId:       (int) ($row['created_by_user_id'] ?? 0),
            createdByUsername:     (string) ($row['created_by_username'] ?? ''),
        );
    }

    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
        if ($dt === false) {
            throw new UnexpectedValueException(sprintf('Invalid datetime in schedule storage: "%s"', $value));
        }
        return $dt;
    }

    protected function tmpStoreAvailable(): bool
    {
        return class_exists(\Pimcore\Model\Tool\TmpStore::class);
    }

    protected function tmpStoreGet(string $key): ?array
    {
        if (!$this->tmpStoreAvailable()) {
            return null;
        }
        $entry = \Pimcore\Model\Tool\TmpStore::get($key);
        if ($entry === null) {
            return null;
        }
        $data = $entry->getData();
        return is_array($data) ? $data : null;
    }

    protected function tmpStoreSet(string $key, array $data): void
    {
        if (!$this->tmpStoreAvailable()) {
            return;
        }
        \Pimcore\Model\Tool\TmpStore::set($key, $data);
    }
}

<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Override;

final class PimcoreTmpStoreContextStorage implements ContextStorageInterface
{
    private const KEY = 'advanced_maintenance_mode_context';

    #[Override]
    public function load(): array
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return ['reason' => null, 'retry_after' => null];
        }

        $entry = \Pimcore\Model\Tool\TmpStore::get(self::KEY);
        if ($entry === null) {
            return ['reason' => null, 'retry_after' => null];
        }

        $data = $entry->getData();
        if (!\is_array($data)) {
            return ['reason' => null, 'retry_after' => null];
        }

        return [
            'reason'      => isset($data['reason'])      && \is_string($data['reason']) ? $data['reason'] : null,
            'retry_after' => isset($data['retry_after']) && \is_int($data['retry_after']) ? $data['retry_after'] : null,
        ];
    }

    #[Override]
    public function save(?string $reason, ?int $retryAfter): void
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        \Pimcore\Model\Tool\TmpStore::set(
            self::KEY,
            ['reason' => $reason, 'retry_after' => $retryAfter],
        );
    }

    #[Override]
    public function clear(): void
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        \Pimcore\Model\Tool\TmpStore::delete(self::KEY);
    }
}

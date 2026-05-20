<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures;

use Pimcore\Tool\MaintenanceModeHelperInterface;

final class InMemoryMaintenanceModeHelper implements MaintenanceModeHelperInterface
{
    public bool $active = false;
    public ?string $activatorSessionId = null;

    public function activate(string $sessionId): void
    {
        $this->active = true;
        $this->activatorSessionId = $sessionId;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->activatorSessionId = null;
    }

    public function isActive(?string $matchSessionId = null): bool
    {
        if (!$this->active) {
            return false;
        }
        if ($matchSessionId !== null && $matchSessionId === $this->activatorSessionId) {
            return false;
        }
        return true;
    }
}

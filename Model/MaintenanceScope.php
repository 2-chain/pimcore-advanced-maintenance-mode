<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model;

use Symfony\Component\HttpFoundation\Request;

final class MaintenanceScope
{
    /**
     * @param string[] $pathPrefixes
     * @param int[]    $siteIds
     */
    public function __construct(
        public readonly array $pathPrefixes,
        public readonly array $siteIds,
    ) {}

    public function isGlobal(): bool
    {
        return empty($this->pathPrefixes) && empty($this->siteIds);
    }

    public function matchesRequest(Request $request, ?int $currentSiteId): bool
    {
        if ($this->isGlobal()) {
            return true;
        }

        foreach ($this->pathPrefixes as $prefix) {
            if (\str_starts_with($request->getPathInfo(), $prefix)) {
                return true;
            }
        }

        if ($currentSiteId !== null && \in_array($currentSiteId, $this->siteIds, true)) {
            return true;
        }

        return false;
    }
}

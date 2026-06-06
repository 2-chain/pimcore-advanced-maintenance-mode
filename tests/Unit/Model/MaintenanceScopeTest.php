<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;

final class MaintenanceScopeTest extends TestCase
{
    // --- isGlobal() ---

    public function testIsGlobalWhenBothListsEmpty(): void
    {
        $scope = new MaintenanceScope([], []);
        self::assertTrue($scope->isGlobal());
    }

    public function testNotGlobalWithPathPrefix(): void
    {
        $scope = new MaintenanceScope(['/shop'], []);
        self::assertFalse($scope->isGlobal());
    }

    public function testNotGlobalWithSiteId(): void
    {
        $scope = new MaintenanceScope([], [2]);
        self::assertFalse($scope->isGlobal());
    }

    public function testNotGlobalWithBoth(): void
    {
        $scope = new MaintenanceScope(['/shop'], [2]);
        self::assertFalse($scope->isGlobal());
    }

    // --- matchesRequest(): global scope ---

    public function testGlobalScopeAlwaysMatches(): void
    {
        $scope = new MaintenanceScope([], []);
        $request = Request::create('/any/path');
        self::assertTrue($scope->matchesRequest($request, null));
        self::assertTrue($scope->matchesRequest($request, 42));
    }

    // --- matchesRequest(): path prefixes ---

    public function testPathPrefixMatchReturnsTrue(): void
    {
        $scope = new MaintenanceScope(['/shop'], []);
        $request = Request::create('/shop/product/123');
        self::assertTrue($scope->matchesRequest($request, null));
    }

    public function testPathPrefixExactMatchReturnsTrue(): void
    {
        $scope = new MaintenanceScope(['/shop'], []);
        $request = Request::create('/shop');
        self::assertTrue($scope->matchesRequest($request, null));
    }

    public function testPathPrefixNoMatchReturnsFalse(): void
    {
        $scope = new MaintenanceScope(['/shop'], []);
        $request = Request::create('/blog/post');
        self::assertFalse($scope->matchesRequest($request, null));
    }

    public function testMultiplePrefixesFirstMatches(): void
    {
        $scope = new MaintenanceScope(['/shop', '/api'], []);
        $request = Request::create('/shop/x');
        self::assertTrue($scope->matchesRequest($request, null));
    }

    public function testMultiplePrefixesSecondMatches(): void
    {
        $scope = new MaintenanceScope(['/shop', '/api'], []);
        $request = Request::create('/api/v1/products');
        self::assertTrue($scope->matchesRequest($request, null));
    }

    public function testMultiplePrefixesNoneMatch(): void
    {
        $scope = new MaintenanceScope(['/shop', '/api'], []);
        $request = Request::create('/blog');
        self::assertFalse($scope->matchesRequest($request, null));
    }

    // --- matchesRequest(): site IDs ---

    public function testSiteIdMatchReturnsTrue(): void
    {
        $scope = new MaintenanceScope([], [2, 5]);
        self::assertTrue($scope->matchesRequest(Request::create('/any'), 2));
    }

    public function testSiteIdNoMatchReturnsFalse(): void
    {
        $scope = new MaintenanceScope([], [2]);
        self::assertFalse($scope->matchesRequest(Request::create('/any'), 3));
    }

    public function testNullSiteIdWithSiteScope(): void
    {
        $scope = new MaintenanceScope([], [2]);
        // null currentSiteId (single-site install) → no site match
        self::assertFalse($scope->matchesRequest(Request::create('/any'), null));
    }

    // --- matchesRequest(): mixed prefix + site ---

    public function testPrefixMatchWinsEvenWhenSiteIdNoMatch(): void
    {
        $scope = new MaintenanceScope(['/shop'], [2]);
        // wrong site ID but path matches
        self::assertTrue($scope->matchesRequest(Request::create('/shop/x'), 99));
    }

    public function testSiteIdMatchWinsEvenWhenPrefixNoMatch(): void
    {
        $scope = new MaintenanceScope(['/shop'], [2]);
        // wrong path but site ID matches
        self::assertTrue($scope->matchesRequest(Request::create('/blog'), 2));
    }

    public function testBothMismatchReturnsFalse(): void
    {
        $scope = new MaintenanceScope(['/shop'], [2]);
        self::assertFalse($scope->matchesRequest(Request::create('/blog'), 3));
    }
}

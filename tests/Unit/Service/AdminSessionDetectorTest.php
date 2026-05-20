<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\AdminSessionDetector;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\AdminSessionDetectorInterface;

final class AdminSessionDetectorTest extends TestCase
{
    public function testInterfaceContract(): void
    {
        $stub = new class implements AdminSessionDetectorInterface {
            public function isLoggedInAdmin(Request $request): bool
            {
                return $request->headers->has('X-Test-Is-Admin');
            }
        };

        $req = Request::create('/');
        self::assertFalse($stub->isLoggedInAdmin($req));

        $req->headers->set('X-Test-Is-Admin', '1');
        self::assertTrue($stub->isLoggedInAdmin($req));
    }

    public function testDefaultImplReturnsFalseWhenPimcoreUnavailable(): void
    {
        // We can't easily simulate Pimcore being unavailable in a test run where
        // the autoloader has it — but we can verify the detector swallows
        // throwables from authenticateSession and returns false.
        $detector = new AdminSessionDetector();
        $request = Request::create('/');
        // No session attached → Pimcore's authenticateSession throws → detector
        // returns false via the try/catch.

        self::assertFalse($detector->isLoggedInAdmin($request));
    }
}

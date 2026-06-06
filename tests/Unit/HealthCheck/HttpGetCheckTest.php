<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\HealthCheck;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HttpGetCheck;

final class HttpGetCheckTest extends TestCase
{
    public function testGetNameReturnsConfiguredUrl(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $check = new HttpGetCheck(
            url: 'https://example.com/health',
            expectedStatus: 200,
            timeoutSeconds: 10,
            httpClient: $httpClient,
        );

        self::assertSame('http_get:https://example.com/health', $check->getName());
    }

    public function testPassesWhenStatusMatchesExpected(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $check = new HttpGetCheck(
            url: 'https://example.com/health',
            expectedStatus: 200,
            timeoutSeconds: 10,
            httpClient: $httpClient,
        );

        $result = $check->run();

        self::assertTrue($result->passed);
        self::assertNull($result->errorMessage);
        self::assertSame('http_get:https://example.com/health', $result->checkName);
    }

    public function testFailsWhenStatusDoesNotMatch(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $check = new HttpGetCheck(
            url: 'https://example.com/health',
            expectedStatus: 200,
            timeoutSeconds: 10,
            httpClient: $httpClient,
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('503', $result->errorMessage ?? '');
        self::assertStringContainsString('200', $result->errorMessage ?? '');
    }

    public function testFailsWhenHttpClientIsNull(): void
    {
        // Null client simulates the soft-dep being absent
        $check = new HttpGetCheck(
            url: 'https://example.com/health',
            expectedStatus: 200,
            timeoutSeconds: 10,
            httpClient: null,
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('symfony/http-client', $result->errorMessage ?? '');
    }
}

<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckInterface;

final class HttpGetCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly string $url,
        private readonly int $expectedStatus,
        private readonly int $timeoutSeconds,
        private readonly ?HttpClientInterface $httpClient,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'http_get:' . $this->url;
    }

    #[\Override]
    public function run(): HealthCheckResult
    {
        if ($this->httpClient === null) {
            return new HealthCheckResult(
                passed: false,
                checkName: $this->getName(),
                errorMessage: 'symfony/http-client is not available — cannot run http_get check',
            );
        }

        try {
            $response = $this->httpClient->request('GET', $this->url, [
                'timeout' => $this->timeoutSeconds,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            return new HealthCheckResult(
                passed: false,
                checkName: $this->getName(),
                errorMessage: 'HTTP request failed: ' . $e->getMessage(),
            );
        }

        if ($status !== $this->expectedStatus) {
            return new HealthCheckResult(
                passed: false,
                checkName: $this->getName(),
                errorMessage: \sprintf(
                    'Expected HTTP %d but got %d from %s',
                    $this->expectedStatus,
                    $status,
                    $this->url,
                ),
            );
        }

        return new HealthCheckResult(
            passed: true,
            checkName: $this->getName(),
        );
    }
}

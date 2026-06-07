<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Psr\Log\LoggerInterface;
use DateTimeInterface;
use Throwable;

class MaintenanceWebhookNotifier
{
    public function __construct(
        private readonly BundleConfiguration $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function notifyPreAnnounce(PreAnnounceData $data): void
    {
        $this->fire('pre_announce', [
            'reason' => $data->reason,
            'at'     => $data->at->format(DateTimeInterface::ATOM),
        ]);
    }

    public function notifyMaintenanceStart(?string $reason, ?int $retryAfter, ?string $activatedBy): void
    {
        $payload = [];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }
        if ($retryAfter !== null) {
            $payload['retryAfter'] = $retryAfter;
        }
        if ($activatedBy !== null) {
            $payload['activatedBy'] = $activatedBy;
        }
        $this->fire('maintenance_start', $payload);
    }

    public function notifyMaintenanceEnd(string $trigger): void
    {
        $this->fire('maintenance_end', ['trigger' => $trigger]);
    }

    /** @param array<string,mixed> $extra */
    private function fire(string $event, array $extra = []): void
    {
        $webhooks = $this->config->notificationWebhooks;
        if ($webhooks === []) {
            $this->logger->debug('[MaintenanceWebhookNotifier] No webhooks configured — skipping.', [
                'event' => $event,
            ]);
            return;
        }

        if (!\class_exists('Symfony\Component\HttpClient\HttpClient')) {
            $this->logger->debug('[MaintenanceWebhookNotifier] symfony/http-client not available — skipping.', [
                'event' => $event,
            ]);
            return;
        }

        $payload = \array_merge(['event' => $event], $extra);
        $client  = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 5]);

        foreach ($webhooks as $url) {
            try {
                $response = $client->request('POST', $url, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => \json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'timeout' => 5,
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    $this->logger->warning('[MaintenanceWebhookNotifier] Non-2xx response from webhook.', [
                        'url'        => $url,
                        'statusCode' => $status,
                        'event'      => $event,
                    ]);
                }
            } catch (Throwable $e) {
                $this->logger->warning('[MaintenanceWebhookNotifier] Webhook request failed.', [
                    'url'       => $url,
                    'event'     => $event,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}

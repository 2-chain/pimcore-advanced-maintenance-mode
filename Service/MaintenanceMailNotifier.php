<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Psr\Log\LoggerInterface;

class MaintenanceMailNotifier
{
    public function __construct(
        private readonly BundleConfiguration $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function notifyPreAnnounce(PreAnnounceData $data): void
    {
        if (!$this->config->mailOnPreAnnounce) {
            return;
        }
        $recipients = $this->config->mailOnPreAnnounceRecipients !== []
            ? $this->config->mailOnPreAnnounceRecipients
            : $this->config->mailRecipients;
        $template = $this->config->mailPreAnnounceTemplate ?? $this->config->mailTemplate;

        $subject = 'Maintenance pre-announcement: ' . $data->at->format('Y-m-d H:i') . ' UTC';
        $body = \sprintf(
            "Scheduled maintenance window\n\nStarts at: %s UTC\nTimezone: %s\nReason: %s",
            $data->at->format('Y-m-d H:i:s'),
            $data->timezone,
            $data->reason ?? '(not specified)',
        );
        $this->send($recipients, $subject, $body, $template);
    }

    public function notifyMaintenanceStart(?string $reason, ?int $retryAfter, ?string $activatedBy): void
    {
        if (!$this->config->mailOnMaintenanceStart) {
            return;
        }
        $recipients = $this->config->mailOnMaintenanceStartRecipients !== []
            ? $this->config->mailOnMaintenanceStartRecipients
            : $this->config->mailRecipients;
        $template = $this->config->mailMaintenanceStartTemplate ?? $this->config->mailTemplate;

        $subject = 'Maintenance mode ENABLED';
        $body = \sprintf(
            "Maintenance mode has been enabled.\n\nReason: %s\nRetry-After: %s\nActivated by: %s",
            $reason ?? '(not specified)',
            $retryAfter !== null ? $retryAfter . 's' : '(default)',
            $activatedBy ?? '(unknown)',
        );
        $this->send($recipients, $subject, $body, $template);
    }

    public function notifyMaintenanceEnd(string $trigger): void
    {
        if (!$this->config->mailOnMaintenanceEnd) {
            return;
        }
        $recipients = $this->config->mailOnMaintenanceEndRecipients !== []
            ? $this->config->mailOnMaintenanceEndRecipients
            : $this->config->mailRecipients;
        $template = $this->config->mailMaintenanceEndTemplate ?? $this->config->mailTemplate;

        $subject = 'Maintenance mode DISABLED';
        $body = \sprintf(
            "Maintenance mode has ended.\n\nTrigger: %s\nTime: %s UTC",
            $trigger,
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        );
        $this->send($recipients, $subject, $body, $template);
    }

    /** @param list<string> $recipients */
    private function send(array $recipients, string $subject, string $body, ?string $template = null): void
    {
        if ($recipients === []) {
            $this->logger->debug('[MaintenanceMailNotifier] No recipients configured — skipping notification.', [
                'subject' => $subject,
            ]);
            return;
        }

        if (!\class_exists('Pimcore\\Mail')) {
            $this->logger->debug('[MaintenanceMailNotifier] Pimcore\\Mail not available — skipping.', [
                'subject' => $subject,
            ]);
            return;
        }

        try {
            $mail = new \Pimcore\Mail();
            if ($template !== null) {
                $mail->setDocument($template);
            } else {
                $mail->subject($subject);
                $mail->text($body);
            }
            foreach ($recipients as $recipient) {
                $mail->addTo($recipient);
            }
            $mail->send();
        } catch (\Throwable $e) {
            $this->logger->warning('[MaintenanceMailNotifier] Failed to send notification.', [
                'subject'   => $subject,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

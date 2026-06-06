<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener\ConsoleExemptionListener;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\CommandRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\HttpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\IpRuleMatcher;

final class ConsoleExemptionListenerTest extends TestCase
{
    private function fakeContext(?string $reason = null): ActivationContext
    {
        $storage = new class ($reason) implements ContextStorageInterface {
            public function __construct(private readonly ?string $reason) {}
            public function load(): array
            {
                return ['reason' => $this->reason, 'retry_after' => null, 'activated_by_schedule_window_id' => null, 'expected_end_at' => null, 'activated_by_health_check_failure' => false, 'activated_by_history_record_id' => null, 'expires_at' => null, 'original_ttl_minutes' => null, 'warning_emitted_at' => null];
            }
            public function save(
                ?string $reason,
                ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null,
                ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false,
                ?int $activatedByHistoryRecordId = null,
                ?string $expiresAt = null,
                ?int $originalTtlMinutes = null,
                ?string $warningEmittedAt = null,
            ): void {}
            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}
            public function saveScope(?array $scopeRaw): void {}
            public function clear(): void {}
        };
        return new ActivationContext($storage);
    }

    private function fakeContextFromStorage(array $state): ActivationContext
    {
        $storage = new class ($state) implements ContextStorageInterface {
            public function __construct(private readonly array $state) {}
            public function load(): array { return $this->state; }
            public function save(
                ?string $reason, ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null, ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false, ?int $activatedByHistoryRecordId = null,
                ?string $expiresAt = null, ?int $originalTtlMinutes = null, ?string $warningEmittedAt = null,
            ): void {}
            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}
            public function saveScope(?array $scopeRaw): void {}
            public function clear(): void {}
        };
        return new ActivationContext($storage);
    }

    private function makeListener(bool $isActive, array $rules, ?string $reason = null): ConsoleExemptionListener
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn($isActive);

        $evaluator = new ExemptionEvaluator(
            new HttpRuleMatcher(new IpRuleMatcher(), $this->createStub(RequestMatcherInterface::class)),
            new CommandRuleMatcher(),
            $rules,
        );

        return new ConsoleExemptionListener($helper, $evaluator, $this->fakeContext($reason));
    }

    private function makeEvent(string $commandName, bool $ignoreFlag = false): ConsoleCommandEvent
    {
        $command = new class extends Command {};
        $command->setName($commandName);
        $def = new InputDefinition([
            new InputOption('ignore-maintenance-mode', null, InputOption::VALUE_NONE),
        ]);
        $command->setDefinition($def);

        $input = new ArrayInput($ignoreFlag ? ['--ignore-maintenance-mode' => true] : [], $def);

        return new ConsoleCommandEvent($command, $input, new BufferedOutput());
    }

    public function testNoOpWhenMaintenanceModeOff(): void
    {
        $listener = $this->makeListener(isActive: false, rules: [
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
        ]);
        $event = $this->makeEvent('messenger:consume');

        $listener->onConsoleCommand($event);

        self::assertFalse($event->getInput()->getOption('ignore-maintenance-mode'));
    }

    public function testNoOpWhenIgnoreFlagAlreadySet(): void
    {
        $listener = $this->makeListener(isActive: true, rules: []);
        $event = $this->makeEvent('any:cmd', ignoreFlag: true);

        $listener->onConsoleCommand($event);
        self::assertTrue($event->getInput()->getOption('ignore-maintenance-mode'));
    }

    public function testExemptCommandFlipsIgnoreFlagAndPrintsBanner(): void
    {
        $listener = $this->makeListener(isActive: true, rules: [
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
        ]);
        $event = $this->makeEvent('messenger:consume');

        $listener->onConsoleCommand($event);

        self::assertTrue($event->getInput()->getOption('ignore-maintenance-mode'));
        $output = $event->getOutput();
        self::assertInstanceOf(BufferedOutput::class, $output);
        $text = $output->fetch();
        self::assertStringContainsString('[maintenance bypass]', $text);
        self::assertStringContainsString('msg', $text);
    }

    public function testReasonPrintedWhenSet(): void
    {
        $listener = $this->makeListener(isActive: true, rules: [
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
        ], reason: 'DB migration');
        $event = $this->makeEvent('messenger:consume');

        $listener->onConsoleCommand($event);

        $text = $event->getOutput()->fetch();
        self::assertStringContainsString('Reason: DB migration', $text);
    }

    public function testNoMatchThrowsWithGenericMessageWhenNoReason(): void
    {
        $listener = $this->makeListener(isActive: true, rules: [
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
        ]);
        $event = $this->makeEvent('app:other');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('In maintenance mode — set --ignore-maintenance-mode to force execution.');

        $listener->onConsoleCommand($event);
    }

    public function testNoMatchThrowsWithReasonInMessage(): void
    {
        $listener = $this->makeListener(
            isActive: true,
            rules: [new CommandRule('msg', 'messenger:*', RuleSource::Yaml)],
            reason: 'DB migration v3.5',
        );
        $event = $this->makeEvent('app:other');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('In maintenance mode (reason: DB migration v3.5) — set --ignore-maintenance-mode to force execution.');

        $listener->onConsoleCommand($event);
    }

    public function testTtlExpiryLineAppendedWhenTtlActive(): void
    {
        $expiresAt = (new \DateTimeImmutable('now UTC'))->modify('+47 minutes');
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);
        $evaluator = new ExemptionEvaluator(
            new HttpRuleMatcher(new IpRuleMatcher(), $this->createStub(RequestMatcherInterface::class)),
            new CommandRuleMatcher(),
            [new CommandRule('msg', 'messenger:*', RuleSource::Yaml)],
        );
        $context = $this->fakeContextFromStorage([
            'reason'                            => null,
            'retry_after'                       => null,
            'activated_by_schedule_window_id'   => null,
            'expected_end_at'                   => null,
            'activated_by_health_check_failure' => false,
            'activated_by_history_record_id'    => null,
            'expires_at'                        => $expiresAt->format(\DateTimeInterface::ATOM),
            'original_ttl_minutes'              => 60,
            'warning_emitted_at'                => null,
        ]);
        $listener = new ConsoleExemptionListener($helper, $evaluator, $context);
        $event = $this->makeEvent('messenger:consume');

        $listener->onConsoleCommand($event);

        self::assertStringContainsString('Expires in:', $event->getOutput()->fetch());
    }

    public function testTtlExpiryLineNotAppendedWhenScheduleManaged(): void
    {
        $expiresAt = (new \DateTimeImmutable('now UTC'))->modify('+30 minutes');
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);
        $evaluator = new ExemptionEvaluator(
            new HttpRuleMatcher(new IpRuleMatcher(), $this->createStub(RequestMatcherInterface::class)),
            new CommandRuleMatcher(),
            [new CommandRule('msg', 'messenger:*', RuleSource::Yaml)],
        );
        $context = $this->fakeContextFromStorage([
            'reason'                            => null,
            'retry_after'                       => null,
            'activated_by_schedule_window_id'   => 'window-nightly',
            'expected_end_at'                   => null,
            'activated_by_health_check_failure' => false,
            'activated_by_history_record_id'    => null,
            'expires_at'                        => $expiresAt->format(\DateTimeInterface::ATOM),
            'original_ttl_minutes'              => 30,
            'warning_emitted_at'                => null,
        ]);
        $listener = new ConsoleExemptionListener($helper, $evaluator, $context);
        $event = $this->makeEvent('messenger:consume');

        $listener->onConsoleCommand($event);

        self::assertStringNotContainsString('Expires in:', $event->getOutput()->fetch());
    }
}

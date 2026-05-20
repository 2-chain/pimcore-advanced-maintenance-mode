<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
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
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\CommandRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\HttpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\IpRuleMatcher;

final class ConsoleExemptionListenerTest extends TestCase
{
    private function fakeContext(?string $reason = null): ActivationContext
    {
        $storage = new class($reason) implements ContextStorageInterface {
            public function __construct(private readonly ?string $reason) {}
            public function load(): array { return ['reason' => $this->reason, 'retry_after' => null]; }
            public function save(?string $reason, ?int $retryAfter): void {}
            public function clear(): void {}
        };
        return new ActivationContext($storage);
    }

    private function makeListener(bool $isActive, array $rules, ?string $reason = null): ConsoleExemptionListener
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
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

        $this->expectException(\RuntimeException::class);
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('In maintenance mode (reason: DB migration v3.5) — set --ignore-maintenance-mode to force execution.');

        $listener->onConsoleCommand($event);
    }
}

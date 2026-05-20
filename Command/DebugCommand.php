<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\CompiledRulesProvider;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:debug',
    description: 'Show maintenance state, exemption rules, and optionally simulate a match',
)]
final class DebugCommand extends Command
{
    /**
     * @param list<HttpRule|CommandRule|IpRule> $compiledRules
     */
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ExemptionEvaluator $evaluator,
        private readonly ActivationContext $context,
        private readonly BundleConfiguration $config,
        private readonly array $compiledRules,
    ) {
        parent::__construct();
    }

    public static function create(
        MaintenanceModeHelperInterface $helper,
        ExemptionEvaluator $evaluator,
        ActivationContext $context,
        BundleConfiguration $config,
        CompiledRulesProvider $rulesProvider,
    ): self {
        return new self(
            helper: $helper,
            evaluator: $evaluator,
            context: $context,
            config: $config,
            compiledRules: $rulesProvider->getRules(),
        );
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Simulate an HTTP request path (e.g. /api/health)')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method for --route (default GET)', 'GET')
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Client IP to use with --route')
            ->addOption('command', null, InputOption::VALUE_REQUIRED, 'Simulate a console command name (e.g. messenger:consume)');
    }

    #[Override]
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasOption('ignore-maintenance-mode')) {
            $input->setOption('ignore-maintenance-mode', true);
        }
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('route') !== null || $input->getOption('command') !== null) {
            return $this->simulate($input, $output);
        }

        $this->printStatus($output);
        $this->printRules($output);

        return Command::SUCCESS;
    }

    private function printStatus(OutputInterface $output): void
    {
        $active = $this->helper->isActive();
        $state = $active ? '<info>ACTIVE</info>' : '<comment>OFF</comment>';

        $output->writeln(\sprintf('Maintenance mode:      %s', $state));

        if ($active) {
            $reason = $this->context->getReason();
            if ($reason !== null) {
                $output->writeln(\sprintf('Activation reason:     %s', $reason));
            }
        }

        $retry = $this->context->getRetryAfter() ?? $this->config->defaultRetryAfter;
        $output->writeln(\sprintf('Default Retry-After:   %s', $retry === null ? '(none)' : $retry . 's'));
        $output->writeln(\sprintf('Admin bypass enabled:  %s', $this->config->bypassAuthenticatedAdmins ? 'yes' : 'no'));
        $output->writeln('');
    }

    private function printRules(OutputInterface $output): void
    {
        $http = []; $cmd = []; $ip = [];
        foreach ($this->compiledRules as $r) {
            match (true) {
                $r instanceof HttpRule    => $http[] = $r,
                $r instanceof CommandRule => $cmd[] = $r,
                $r instanceof IpRule      => $ip[] = $r,
            };
        }

        $output->writeln(\sprintf('HTTP rules (%d):', \count($http)));
        foreach ($http as $r) {
            $output->writeln('  ' . $this->describeHttp($r));
        }
        $output->writeln('');

        $output->writeln(\sprintf('Command rules (%d):', \count($cmd)));
        foreach ($cmd as $r) {
            $output->writeln(\sprintf('  %s/%s            %s', $r->source->value, $r->id, $r->namePattern));
        }
        $output->writeln('');

        $output->writeln(\sprintf('IP rules (%d):', \count($ip)));
        foreach ($ip as $r) {
            $output->writeln(\sprintf('  %s/%s            %s', $r->source->value, $r->id, $r->ipOrCidr));
        }
    }

    private function describeHttp(HttpRule $r): string
    {
        $bits = [];
        if ($r->pathGlob !== null)  { $bits[] = $r->pathGlob; }
        if ($r->routeName !== null) { $bits[] = 'route=' . $r->routeName; }
        if ($r->host !== null)      { $bits[] = 'host=' . $r->host; }
        if ($r->methods !== [])     { $bits[] = \implode(',', $r->methods) . ' only'; }

        return \sprintf('%s/%s            %s', $r->source->value, $r->id, \implode(' / ', $bits));
    }

    private function simulate(InputInterface $input, OutputInterface $output): int
    {
        $route = $input->getOption('route');
        $command = $input->getOption('command');

        if (\is_string($route) && $route !== '') {
            $rawMethod = $input->getOption('method');
            $method = \is_scalar($rawMethod) ? (string) $rawMethod : 'GET';
            $ip = $input->getOption('ip');

            $request = Request::create($route, $method);
            if (\is_string($ip) && $ip !== '') {
                $request->server->set('REMOTE_ADDR', $ip);
            }

            $match = $this->evaluator->evaluateRequest($request);

            if ($match === null) {
                $output->writeln(\sprintf('No exemption rule matches %s %s.', $method, $route));
                return Command::SUCCESS;
            }

            $output->writeln(\sprintf(
                '<info>Would be exempted by rule:</info>  %s/%s  (%s)',
                $match->source->value,
                $match->ruleId,
                $match->description,
            ));
            return Command::SUCCESS;
        }

        if (\is_string($command) && $command !== '') {
            $match = $this->evaluator->evaluateCommand($command);

            if ($match === null) {
                $output->writeln(\sprintf('No exemption rule matches command "%s".', $command));
                return Command::SUCCESS;
            }

            $output->writeln(\sprintf(
                '<info>Would be exempted by rule:</info>  %s/%s  (%s)',
                $match->source->value,
                $match->ruleId,
                $match->description,
            ));
            return Command::SUCCESS;
        }

        $output->writeln('<error>Provide --route or --command to simulate.</error>');
        return Command::FAILURE;
    }
}

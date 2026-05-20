<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler\Fixtures;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Attribute\ExemptFromMaintenance;

#[AsCommand(name: 'app:nightly-report')]
#[ExemptFromMaintenance(id: 'nightly-report')]
final class ExemptCommand extends Command {}

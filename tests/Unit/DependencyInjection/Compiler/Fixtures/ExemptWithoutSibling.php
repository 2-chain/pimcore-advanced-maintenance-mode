<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler\Fixtures;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Attribute\ExemptFromMaintenance;

#[ExemptFromMaintenance(id: 'bad')]
final class ExemptWithoutSibling {}

<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['bypass_authenticated_admins']);
        self::assertSame(300, $config['default_retry_after']);
        self::assertTrue($config['builtin_exemptions']['bundle_own_commands']);
        self::assertTrue($config['builtin_exemptions']['symfony_info_commands']);
        self::assertTrue($config['builtin_exemptions']['loopback']);
    }

    public function testDefaultRetryAfterAcceptsNull(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [
            ['default_retry_after' => null],
        ]);

        self::assertNull($config['default_retry_after']);
    }

    public function testDefaultRetryAfterRejectsNegative(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['default_retry_after' => -1],
        ]);
    }

    public function testExemptionsDefaultEmpty(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame([], $config['exemptions']['commands']);
        self::assertSame([], $config['exemptions']['routes']);
        self::assertSame([], $config['exemptions']['ips']);
        self::assertFalse($config['exemptions']['messenger_workers']);
        self::assertFalse($config['exemptions']['scheduled_tasks']);
    }

    public function testCommandsAcceptStringShorthand(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'exemptions' => ['commands' => ['messenger:*']],
        ]]);

        self::assertSame([['pattern' => 'messenger:*', 'id' => null]], $config['exemptions']['commands']);
    }

    public function testCommandsAcceptObjectForm(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'exemptions' => ['commands' => [['pattern' => 'doctrine:migrations:*', 'id' => 'doctrine-migrations']]],
        ]]);

        self::assertSame([['pattern' => 'doctrine:migrations:*', 'id' => 'doctrine-migrations']], $config['exemptions']['commands']);
    }

    public function testRoutesRequireAtLeastOneOfPathOrRoute(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'exemptions' => ['routes' => [['methods' => ['POST']]]],
        ]]);
    }

    public function testRoutesAcceptFullShape(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'exemptions' => ['routes' => [[
                'path' => '/api/*',
                'route' => 'app_api',
                'host' => 'api.example.com',
                'methods' => ['POST', 'PUT'],
                'id' => 'api-rule',
            ]]],
        ]]);

        self::assertSame([[
            'path' => '/api/*',
            'route' => 'app_api',
            'host' => 'api.example.com',
            'methods' => ['POST', 'PUT'],
            'id' => 'api-rule',
        ]], $config['exemptions']['routes']);
    }

    public function testInvalidHttpMethodRejected(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'exemptions' => ['routes' => [['path' => '/x', 'methods' => ['INVALID']]]],
        ]]);
    }

    public function testIpsAcceptStrings(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'exemptions' => ['ips' => ['10.0.0.0/8', '192.168.1.42']],
        ]]);

        self::assertSame(['10.0.0.0/8', '192.168.1.42'], $config['exemptions']['ips']);
    }
}

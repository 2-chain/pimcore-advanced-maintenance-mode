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
        self::assertNull($config['default_ttl']);
        self::assertNull($config['expiry_warning_threshold']);
        self::assertTrue($config['builtin_exemptions']['pimcore_maintenance']);
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

    public function testPreAnnounceDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['pre_announce']['auto_inject_banner']);
        self::assertNull($config['pre_announce']['default_threshold_minutes']);
        self::assertSame(30, $config['pre_announce']['urgency_orange_minutes']);
        self::assertSame(10, $config['pre_announce']['urgency_red_minutes']);
        self::assertSame('session', $config['pre_announce']['dismiss_persistence']);
        self::assertSame([], $config['notifications']['webhooks']);
    }

    public function testMailDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertFalse($config['mail']['on_pre_announce']);
        self::assertFalse($config['mail']['on_maintenance_start']);
        self::assertFalse($config['mail']['on_maintenance_end']);
        self::assertNull($config['mail']['template']);
        self::assertNull($config['mail']['pre_announce_template']);
        self::assertNull($config['mail']['maintenance_start_template']);
        self::assertNull($config['mail']['maintenance_end_template']);
        self::assertSame([], $config['mail']['recipients']);
        self::assertSame([], $config['mail']['on_pre_announce_recipients']);
        self::assertSame([], $config['mail']['on_maintenance_start_recipients']);
        self::assertSame([], $config['mail']['on_maintenance_end_recipients']);
    }

    public function testDismissPersistenceRejectsInvalidValue(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['pre_announce' => ['dismiss_persistence' => 'cookie']],
        ]);
    }

    public function testSelectiveMaintenanceDefaultsToEmptyLists(): void
    {
        $config = (new \Symfony\Component\Config\Definition\Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame([], $config['selective_maintenance']['default_scope']['path_prefixes']);
        self::assertSame([], $config['selective_maintenance']['default_scope']['site_ids']);
    }

    public function testSelectiveMaintenanceAcceptsPathPrefixes(): void
    {
        $config = (new \Symfony\Component\Config\Definition\Processor())->processConfiguration(new Configuration(), [[
            'selective_maintenance' => [
                'default_scope' => ['path_prefixes' => ['/shop', '/api'], 'site_ids' => []],
            ],
        ]]);

        self::assertSame(['/shop', '/api'], $config['selective_maintenance']['default_scope']['path_prefixes']);
        self::assertSame([], $config['selective_maintenance']['default_scope']['site_ids']);
    }

    public function testSelectiveMaintenanceAcceptsSiteIds(): void
    {
        $config = (new \Symfony\Component\Config\Definition\Processor())->processConfiguration(new Configuration(), [[
            'selective_maintenance' => [
                'default_scope' => ['path_prefixes' => [], 'site_ids' => [2, 5]],
            ],
        ]]);

        self::assertSame([2, 5], $config['selective_maintenance']['default_scope']['site_ids']);
    }

    public function testHealthChecksDefaultDisabled(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertFalse($config['health_checks']['enabled']);
        self::assertSame(30, $config['health_checks']['retry_delay_seconds']);
        self::assertSame([], $config['health_checks']['checks']);
    }

    public function testHealthChecksAcceptsHttpGetCheck(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'health_checks' => [
                'enabled' => true,
                'checks' => [[
                    'type' => 'http_get',
                    'url' => 'https://example.com/health',
                    'expected_status' => 200,
                    'timeout_seconds' => 10,
                ]],
            ],
        ]]);

        self::assertTrue($config['health_checks']['enabled']);
        self::assertCount(1, $config['health_checks']['checks']);
        self::assertSame('http_get', $config['health_checks']['checks'][0]['type']);
        self::assertSame('https://example.com/health', $config['health_checks']['checks'][0]['url']);
        self::assertSame(200, $config['health_checks']['checks'][0]['expected_status']);
        self::assertSame(10, $config['health_checks']['checks'][0]['timeout_seconds']);
    }

    public function testHealthChecksAcceptsDatabasePingCheck(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'health_checks' => [
                'enabled' => true,
                'checks' => [[
                    'type' => 'database_ping',
                    'connection' => 'default',
                ]],
            ],
        ]]);

        self::assertSame('database_ping', $config['health_checks']['checks'][0]['type']);
        self::assertSame('default', $config['health_checks']['checks'][0]['connection']);
    }

    public function testHealthChecksAcceptsConsoleCommandCheck(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'health_checks' => [
                'enabled' => true,
                'checks' => [[
                    'type' => 'console_command',
                    'command' => 'app:health-check',
                    'timeout_seconds' => 60,
                ]],
            ],
        ]]);

        self::assertSame('console_command', $config['health_checks']['checks'][0]['type']);
        self::assertSame('app:health-check', $config['health_checks']['checks'][0]['command']);
        self::assertSame(60, $config['health_checks']['checks'][0]['timeout_seconds']);
    }

    public function testHealthChecksRejectsUnknownType(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'health_checks' => [
                'enabled' => true,
                'checks' => [['type' => 'unknown_type']],
            ],
        ]]);
    }
}

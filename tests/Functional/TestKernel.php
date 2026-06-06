<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional;

use Override;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\PimcoreAdvancedMaintenanceModeBundle;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\AdminSessionDetectorInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures\InMemoryContextStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures\InMemoryMaintenanceModeHelper;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures\StubAdminSessionDetector;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures\TestController;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var array<string, mixed> */
    private array $bundleConfig;

    public function __construct(string $env = 'test', bool $debug = false, array $bundleConfig = [])
    {
        $this->bundleConfig = $bundleConfig;
        parent::__construct($env, $debug);
    }

    #[Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new PimcoreAdvancedMaintenanceModeBundle()];
    }

    /**
     * Called by MicroKernelTrait::registerContainerConfiguration.
     * Configures framework, our bundle, and all test stubs.
     */
    private function configureContainer(
        ContainerConfigurator $container,
        \Symfony\Component\Config\Loader\LoaderInterface $loader,
        ContainerBuilder $builder,
    ): void {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // Disable trusted-proxy env-var parameters to avoid unresolved placeholder issues.
            'trusted_proxies' => null,
            'trusted_headers' => [],
            // Allow all hosts (no restriction) in tests.
            'trusted_hosts' => [],
        ]);
        // Disable loopback builtin exemption so test requests from 127.0.0.1 aren't bypassed.
        // Request::create('/path') uses 127.0.0.1 as source IP by default.
        $bundleConfig = array_replace_recursive(
            ['builtin_exemptions' => ['loopback' => false]],
            $this->bundleConfig,
        );
        $container->extension('two_chain_advanced_maintenance_mode', $bundleConfig);

        // Alias RequestMatcherInterface → router.default (FrameworkBundle doesn't expose this by default).
        $builder->setAlias(\Symfony\Component\Routing\Matcher\RequestMatcherInterface::class, 'router.default');

        // Override Pimcore's MaintenanceModeHelperInterface with our in-memory stub.
        $builder->setDefinition(
            InMemoryMaintenanceModeHelper::class,
            (new Definition(InMemoryMaintenanceModeHelper::class))->setPublic(true)
        );
        $builder->setAlias(MaintenanceModeHelperInterface::class, InMemoryMaintenanceModeHelper::class)->setPublic(true);

        // Override admin detector.
        $builder->setDefinition(
            StubAdminSessionDetector::class,
            (new Definition(StubAdminSessionDetector::class))->setPublic(true)
        );
        $builder->setAlias(AdminSessionDetectorInterface::class, StubAdminSessionDetector::class)->setPublic(true);

        // Override storage with in-memory variant.
        $builder->setDefinition(
            InMemoryContextStorage::class,
            (new Definition(InMemoryContextStorage::class))->setPublic(true)
        );
        $builder->setAlias(ContextStorageInterface::class, InMemoryContextStorage::class)->setPublic(true);

        // Make ActivationContext public so tests can set reason/retry-after.
        $builder->setDefinition(
            ActivationContext::class,
            (new Definition(ActivationContext::class))
                ->setPublic(true)
                ->setAutowired(true)
        );

        // Pimcore-mimicking stub listener — returns 503 if maintenance active.
        $builder->register('test.pimcore_maintenance_stub', PimcoreMaintenancePageStub::class)
            ->addArgument(new Reference(MaintenanceModeHelperInterface::class))
            ->addTag('kernel.event_subscriber')
            ->setPublic(true);

        // Test controller.
        $builder->setDefinition(TestController::class, (new Definition(TestController::class))
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setAutowired(true)
            ->addTag('controller.service_arguments'));

        // Add a compiler pass to make certain FrameworkBundle services public before inlining,
        // so FrameworkBundle::boot() can access them via get() on the ContainerBuilder.
        $builder->addCompilerPass(
            new \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\MakeServicesPublicPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_REMOVING,
            -32,
        );
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(TestController::class, 'attribute');
    }

    /**
     * Override initializeContainer to bypass the PHP container dump.
     *
     * The compiled_rules parameter contains PHP objects (CommandRule, HttpRule, IpRule)
     * which cannot be serialized by the PhpDumper. For tests we use the ContainerBuilder
     * directly without any file caching.
     *
     * To make this work with the live ContainerBuilder:
     * - We neutralize env-var placeholders for trusted-proxy params before compile.
     * - MakeServicesPublicPass ensures framework services needed by bundle::boot() stay public.
     */
    #[Override]
    protected function initializeContainer(): void
    {
        $container = $this->buildContainer();

        // Neutralize env-var placeholders before compile() freezes the bag.
        // FrameworkExtension sets these to env-var references like "%env(default::SYMFONY_TRUSTED_HOSTS)%"
        // by default. When the container is not dumped, these stay as unresolved strings and confuse
        // Kernel::boot() which tries to use them as regex patterns for host/proxy validation.
        // We set them to null/empty so Kernel::boot() skips the trusted-proxy/host setup entirely.
        $container->setParameter('kernel.trusted_proxies', null);
        $container->setParameter('kernel.trusted_headers', null);
        $container->setParameter('kernel.trusted_hosts', null);

        // container.build_id is normally injected by PhpDumper but not present in
        // a raw ContainerBuilder; set a placeholder so services referencing it don't fail.
        if (!$container->hasParameter('container.build_id')) {
            $container->setParameter('container.build_id', 'test_' . \substr(\md5(\uniqid('', true)), 0, 8));
        }

        $container->compile();
        $container->set('kernel', $this);
        $this->container = $container;
    }

    #[Override]
    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/two-chain-amm-test/cache/' . spl_object_id($this);
    }

    #[Override]
    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/two-chain-amm-test/logs/' . spl_object_id($this);
    }
}

/**
 * Makes certain framework services public so FrameworkBundle::boot() can access them
 * via $container->get() when using a ContainerBuilder directly (no PHP container dump).
 */
final class MakeServicesPublicPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (['mime_types'] as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $container->getDefinition($serviceId)->setPublic(true);
            }
        }
    }
}

/**
 * Mimics Pimcore's MaintenancePageListener at priority 126.
 */
final class PimcoreMaintenancePageStub implements EventSubscriberInterface
{
    public function __construct(private readonly MaintenanceModeHelperInterface $helper) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 126]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $sid = $event->getRequest()->hasSession() ? $event->getRequest()->getSession()->getId() : null;
        if ($this->helper->isActive($sid)) {
            $event->setResponse(new Response('Service Unavailable', 503));
        }
    }
}

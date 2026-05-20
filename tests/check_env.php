<?php

declare(strict_types=1);
require "vendor/autoload.php";
echo "Symfony version: " . \Symfony\Component\HttpKernel\Kernel::VERSION . PHP_EOL;
echo "FrameworkBundle: " . (class_exists('Symfony\Bundle\FrameworkBundle\FrameworkBundle') ? 'yes' : 'no') . PHP_EOL;
echo "Route attr: " . (class_exists('Symfony\Component\Routing\Attribute\Route') ? 'yes' : 'no') . PHP_EOL;
echo "HttpKernelBrowser: " . (class_exists('Symfony\Component\HttpKernel\HttpKernelBrowser') ? 'yes' : 'no') . PHP_EOL;
echo "MaintenanceModeHelperInterface: " . (interface_exists('Pimcore\Tool\MaintenanceModeHelperInterface') ? 'yes' : 'no') . PHP_EOL;
echo "AbstractPimcoreBundle: " . (class_exists('Pimcore\Extension\Bundle\AbstractPimcoreBundle') ? 'yes' : 'no') . PHP_EOL;

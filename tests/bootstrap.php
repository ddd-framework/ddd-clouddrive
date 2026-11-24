<?php

declare(strict_types=1);

/**
 * Framework objects (value objects, entities) assume a booted kernel: DefaultObject's lazy-load reflection reaches for
 * the container. The suite installs a minimal container that answers a service by instantiating the requested class.
 */

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

require __DIR__ . '/../vendor/autoload.php';

// the browser's file sink port (ddd-agent-browser ≥ 0.2.0): stand-ins when the package is not installed
if (!interface_exists('DDD\\Domain\\AI\\Services\\Browser\\BrowserFileSink')) {
    foreach (['BrowserFileSinkContext', 'BrowserFileToStore', 'BrowserStoredFile', 'BrowserFileSink', 'BrowserFileSinksService'] as $stubClassName) {
        require __DIR__ . '/Support/AgentBrowser/' . $stubClassName . '.php';
    }
}

class CloudDriveTestContainer extends Container
{
    /** @var object[] Instances by class */
    protected array $testInstances = [];

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if ($id === 'kernel') {
            return new class {
                public function getKernelPrefix(): string
                {
                    return '';
                }
            };
        }
        if ($id === 'logger') {
            return new Psr\Log\NullLogger();
        }
        if (!isset($this->testInstances[$id])) {
            if (!class_exists($id)) {
                return null;
            }
            $reflectionClass = new ReflectionClass($id);
            $this->testInstances[$id] = $reflectionClass->getConstructor()
            && $reflectionClass->getConstructor()->getNumberOfRequiredParameters() > 0
                ? $reflectionClass->newInstanceWithoutConstructor()
                : new $id();
        }
        return $this->testInstances[$id];
    }

    public function has(string $id): bool
    {
        return class_exists($id) || parent::has($id);
    }
}

$testCacheDirectory = sys_get_temp_dir() . '/ddd_clouddrive_phpunit_cache';
@mkdir($testCacheDirectory, 0777, true);
@touch($testCacheDirectory . '/service_class_map.php');

(new ReflectionProperty(DDD\DDDBundle::class, 'defaultContainer'))->setValue(null, new CloudDriveTestContainer(new ParameterBag([
    'kernel.cache_dir' => $testCacheDirectory,
    'kernel.project_dir' => dirname(__DIR__),
    'kernel.environment' => 'test',
])));

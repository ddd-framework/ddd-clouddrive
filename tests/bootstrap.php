<?php

declare(strict_types=1);

/**
 * Framework objects (value objects, entities) assume a booted kernel: DefaultObject's lazy-load reflection reaches for
 * the container. The suite installs a minimal container that answers a service by instantiating the requested class.
 */

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

require __DIR__ . '/../vendor/autoload.php';

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

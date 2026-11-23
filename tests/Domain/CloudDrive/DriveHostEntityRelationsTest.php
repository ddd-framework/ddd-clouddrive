<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A lazy relation of a drive entity to an entity outside the drive (the host's Account) is never serialized and never
 * part of the API schema: the id stays, the host's entity shape is no part of the drive's contract.
 */
class DriveHostEntityRelationsTest extends TestCase
{
    public function testEveryLazyRelationToANonDriveEntityIsHiddenAndIgnored(): void
    {
        $entityDirectory = dirname(__DIR__, 3) . '/src/Domain/CloudDrive/Entities';
        $checkedRelationCount = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($entityDirectory)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relativeClassPath = substr($file->getPathname(), strlen($entityDirectory) + 1, -4);
            $className = 'DDD\\Domain\\CloudDrive\\Entities\\' . str_replace('/', '\\', $relativeClassPath);
            if (!class_exists($className)) {
                continue;
            }
            $reflectionClass = new ReflectionClass($className);
            foreach ($reflectionClass->getProperties() as $reflectionProperty) {
                if ($reflectionProperty->getDeclaringClass()->getName() !== $className || $reflectionProperty->getAttributes(LazyLoad::class) === []) {
                    continue;
                }
                $propertyType = $reflectionProperty->getType();
                if (!$propertyType instanceof ReflectionNamedType || $propertyType->isBuiltin()) {
                    continue;
                }
                $relatedClassName = $propertyType->getName();
                if (str_starts_with($relatedClassName, 'DDD\\Domain\\CloudDrive\\') || !is_subclass_of($relatedClassName, Entity::class)) {
                    continue;
                }
                $checkedRelationCount++;
                $propertyLabel = $className . '::$' . $reflectionProperty->getName();
                self::assertNotSame([], $reflectionProperty->getAttributes(HideProperty::class), $propertyLabel . ' lacks HideProperty');
                self::assertNotSame([], $reflectionProperty->getAttributes(Ignore::class), $propertyLabel . ' lacks Ignore');
            }
        }
        self::assertGreaterThan(0, $checkedRelationCount, 'DriveSpace::$account is expected among the checked relations');
    }
}

<?php

declare(strict_types=1);

namespace Oro\Rules\Types;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * Provides UnionType(DriverStatement, DriverResultStatement) return type for the executequery method
 */
class DoctrineConnectionReturnTypeExtension implements \PHPStan\Type\DynamicMethodReturnTypeExtension
{
    /**
     * {@inheritDoc}
     */
    public function getClass(): string
    {
        return 'Doctrine\DBAL\Connection';
    }

    /**
     * {@inheritDoc}
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        $methodName = strtolower($methodReflection->getName());

        return $methodName === 'executequery';
    }

    /**
     * {@inheritDoc}
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        return new UnionType([
            new ObjectType('Doctrine\DBAL\ForwardCompatibility\DriverStatement'),
            new ObjectType('Doctrine\DBAL\ForwardCompatibility\DriverResultStatement'),
        ]);
    }
}

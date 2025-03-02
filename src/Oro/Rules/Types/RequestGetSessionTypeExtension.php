<?php

declare(strict_types=1);

namespace Oro\Rules\Types;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Provides Session return type for the getSession method
 */
class RequestGetSessionTypeExtension implements \PHPStan\Type\DynamicMethodReturnTypeExtension
{
    /**
     * {@inheritDoc}
     */
    public function getClass(): string
    {
        return 'Symfony\Component\HttpFoundation\Request';
    }

    /**
     * {@inheritDoc}
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'getSession';
    }

    /**
     * {@inheritDoc}
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        return new ObjectType('Symfony\Component\HttpFoundation\Session\Session');
    }
}

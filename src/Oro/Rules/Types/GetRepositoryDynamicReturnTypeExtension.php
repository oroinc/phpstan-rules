<?php

namespace Oro\Rules\Types;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Doctrine\GetRepositoryDynamicReturnTypeExtension as BasenameExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Provides EntityRepository return type
 */
class GetRepositoryDynamicReturnTypeExtension extends BasenameExtension
{
    /**
     * {@inheritDoc}
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        $type = parent::getTypeFromMethodCall($methodReflection, $methodCall, $scope);
        if (
            $type instanceof MixedType
            || ($type instanceof GenericObjectType && $type->getClassName() === 'Doctrine\Persistence\ObjectRepository')
        ) {
            return new ObjectType('Doctrine\ORM\EntityRepository');
        }

        return $type;
    }
}

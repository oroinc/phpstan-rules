<?php declare(strict_types=1);

namespace Oro\Rules\Types;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

class ObjectRepositoryCreateQueryBuilderTypeExtension implements \PHPStan\Type\DynamicMethodReturnTypeExtension
{
    /**
     * @var string
     */
    private $supportedClass;

    public function __construct($supportedClass)
    {
        $this->supportedClass = $supportedClass;
    }

    public function getClass(): string
    {
        return $this->supportedClass;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'createQueryBuilder';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        return new ObjectType('Doctrine\ORM\QueryBuilder');
    }
}

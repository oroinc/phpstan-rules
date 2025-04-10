<?php

declare(strict_types=1);

namespace Oro\Rules\Types;

use Oro\Component\Math\BigDecimal;
use Oro\Component\Math\BigInteger;
use Oro\Component\Math\BigNumber;
use Oro\Component\Math\BigRational;
use Oro\Rules\Types\MathTypes\OroMathFloatType;
use Oro\Rules\Types\MathTypes\OroMathIntegerType;
use Oro\Rules\Types\MathTypes\OroMathStringType;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;

/**
 * Provides custom OroMath return types for the toFloat, toInt, toString methods
 */
class OroMathComponentReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const SUPPORTED_CLASSES = [
        BigDecimal::class,
        BigInteger::class,
        BigRational::class,
        BigNumber::class,
    ];

    public function getClass(): string
    {
        return BigNumber::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        $declaringClass = $methodReflection->getDeclaringClass()->getName();

        if (!in_array($declaringClass, self::SUPPORTED_CLASSES, true)) {
            return false;
        }

        return in_array($methodReflection->getName(), ['toFloat', 'toInteger', '__toString'], true);
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        Node\Expr\MethodCall $methodCall,
        Scope $scope
    ): ?\PHPStan\Type\Type {
        switch ($methodReflection->getName()) {
            case 'toFloat':
                return new OroMathFloatType();
            case 'toInteger':
                return new OroMathIntegerType();
            case '__toString':
                return new OroMathStringType();
            default:
                throw new \LogicException('Unsupported method: ' . $methodReflection->getName());
        }
    }
}

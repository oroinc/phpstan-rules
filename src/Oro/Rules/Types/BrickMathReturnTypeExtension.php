<?php

declare(strict_types=1);

namespace Oro\Rules\Types;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Oro\Rules\Types\MathTypes\BrickMathFloatType;
use Oro\Rules\Types\MathTypes\BrickMathIntegerType;
use Oro\Rules\Types\MathTypes\BrickMathStringType;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;

/**
 * Provides custom BrickMath return types for the toFloat, toInt, toString methods
 */
class BrickMathReturnTypeExtension implements DynamicMethodReturnTypeExtension
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

        return in_array($methodReflection->getName(), ['toFloat', 'toInt', '__toString'], true);
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        Node\Expr\MethodCall $methodCall,
        Scope $scope
    ): ?\PHPStan\Type\Type {
        switch ($methodReflection->getName()) {
            case 'toFloat':
                return new BrickMathFloatType();
            case 'toInt':
                return new BrickMathIntegerType();
            case '__toString':
                return new BrickMathStringType();
            default:
                throw new \LogicException('Unsupported method: ' . $methodReflection->getName());
        }
    }
}

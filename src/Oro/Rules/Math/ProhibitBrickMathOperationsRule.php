<?php

declare(strict_types=1);

namespace Oro\Rules\Math;

use Oro\Rules\Types\MathTypes\BrickMathFloatType;
use Oro\Rules\Types\MathTypes\BrickMathIntegerType;
use Oro\Rules\Types\MathTypes\BrickMathStringType;
use PhpParser\Node;
use PHPStan\Rules\Rule;

/**
 * Rule prohibiting mathematical operations on BrickMath objects
 *
 * @implements Rule<Node>
 */
class ProhibitBrickMathOperationsRule extends MathTypeOperationsRule
{
    public function __construct()
    {
        $this->restrictedTypes = [
            BrickMathFloatType::class,
            BrickMathIntegerType::class,
            BrickMathStringType::class
        ];

        $this->componentName = 'BrickMath';
        $this->alternativeMethod = 'Brick\\Math';
    }
}

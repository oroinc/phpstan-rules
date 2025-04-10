<?php

declare(strict_types=1);

namespace Oro\Rules\Math;

use Oro\Rules\Types\MathTypes\OroMathFloatType;
use Oro\Rules\Types\MathTypes\OroMathIntegerType;
use Oro\Rules\Types\MathTypes\OroMathStringType;
use PhpParser\Node;
use PHPStan\Rules\Rule;

/**
 * Rule prohibiting mathematical operations on OroMath objects
 *
 * @implements Rule<Node>
 */
class ProhibitOroMathOperationsRule extends MathTypeOperationsRule
{
    public function __construct()
    {
        $this->restrictedTypes = [
            OroMathFloatType::class,
            OroMathIntegerType::class,
            OroMathStringType::class
        ];

        $this->componentName = 'OroMath';
        $this->alternativeMethod = 'Oro\\Component\\Math';
    }
}

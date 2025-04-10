<?php

declare(strict_types=1);

namespace Oro\Rules\Types\MathTypes;

use PHPStan\Type\IntegerType;
use PHPStan\Type\VerbosityLevel;

/**
 * Extended Integer type
 * Provides to detect values returned by the Brick\Math classes
 */
class BrickMathIntegerType extends IntegerType
{
    public function describe(VerbosityLevel $level): string
    {
        return 'BrickMathInteger';
    }
}

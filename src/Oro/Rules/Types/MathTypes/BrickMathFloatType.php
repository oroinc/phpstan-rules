<?php

declare(strict_types=1);

namespace Oro\Rules\Types\MathTypes;

use PHPStan\Type\FloatType;
use PHPStan\Type\VerbosityLevel;

/**
 * Extended Float type
 * Provides to detect values returned by the Brick\Math classes
 */
class BrickMathFloatType extends FloatType
{
    public function describe(VerbosityLevel $level): string
    {
        return 'BrickMathFloat';
    }
}

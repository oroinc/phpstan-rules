<?php

declare(strict_types=1);

namespace Oro\Rules\Types\MathTypes;

use PHPStan\Type\StringType;
use PHPStan\Type\VerbosityLevel;

/**
 * Extended String type
 * Provides to detect values returned by the Brick\Math classes
 */
class BrickMathStringType extends StringType
{
    public function describe(VerbosityLevel $level): string
    {
        return 'BrickMathString';
    }
}

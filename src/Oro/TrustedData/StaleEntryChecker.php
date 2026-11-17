<?php

declare(strict_types=1);

namespace Oro\TrustedData;

/**
 * Reports trusted data entries that no longer match the code: a missing class, method, variable, property,
 * static modifier or argument position.
 */
class StaleEntryChecker
{
    private const METHOD_SECTIONS = [
        'safe_methods' => false,
        'safe_static_methods' => true,
        'check_methods_safety' => false,
        'check_static_methods_safety' => true,
        'check_methods' => false,
        'clear_methods' => false,
        'clear_static_methods' => true,
    ];
    private const USAGE_SECTIONS = [
        'variables' => false,
        'properties' => true,
    ];
    private const ALL_METHODS = '__all__';

    /**
     * @param array $sources [file => trusted data, ...]
     *
     * @return array [file => [stale entry description, ...], ...]
     */
    public function check(array $sources): array
    {
        $stale = [];
        foreach ($sources as $file => $trustedData) {
            $entries = array_merge($this->checkUsages($trustedData), $this->checkMethods($trustedData));
            if ($entries) {
                $stale[$file] = $entries;
            }
        }

        return $stale;
    }

    private function checkUsages(array $trustedData): array
    {
        $entries = [];
        foreach (self::USAGE_SECTIONS as $section => $isProperty) {
            foreach ($trustedData[$section] ?? [] as $class => $methods) {
                foreach ($methods as $method => $names) {
                    foreach (array_keys($names) as $name) {
                        $reason = $this->checkMethodUsage($class, $method, (string)$name, $isProperty);
                        if ($reason) {
                            $entries[] = sprintf('%s: %s::%s() $%s - %s', $section, $class, $method, $name, $reason);
                        }
                    }
                }
            }
        }

        return $entries;
    }

    private function checkMethods(array $trustedData): array
    {
        $entries = [];
        foreach (self::METHOD_SECTIONS as $section => $isStatic) {
            foreach ($trustedData[$section] ?? [] as $class => $methods) {
                foreach ($methods as $method => $positions) {
                    $reason = $this->checkMethod($class, (string)$method, $isStatic, $positions);
                    if ($reason) {
                        $entries[] = sprintf('%s: %s::%s() - %s', $section, $class, $method, $reason);
                    }
                }
            }
        }

        return $entries;
    }

    private function checkMethod(string $class, string $method, bool $isStatic, mixed $positions): ?string
    {
        if (!$this->classExists($class)) {
            return 'class not found';
        }

        if ($method === self::ALL_METHODS) {
            return null;
        }

        $classReflection = new \ReflectionClass($class);
        if (!$classReflection->hasMethod($method)) {
            return 'method not found';
        }

        $methodReflection = $classReflection->getMethod($method);
        if ($methodReflection->isStatic() !== $isStatic) {
            return $isStatic ? 'method is not static' : 'method is static';
        }

        if (!is_array($positions)) {
            return null;
        }

        if ($methodReflection->isVariadic() || $this->usesFuncGetArgs($methodReflection)) {
            return null;
        }

        foreach ($positions as $position) {
            // take the argument number from positions like "1:keys"
            if ((int)$position >= $methodReflection->getNumberOfParameters()) {
                return sprintf('argument #%d does not exist', (int)$position);
            }
        }

        return null;
    }

    /**
     * Checks that the method, declared in the class or its traits, uses the variable or property.
     */
    private function checkMethodUsage(string $class, string $method, string $name, bool $isProperty): ?string
    {
        if (!$this->classExists($class)) {
            return 'class not found';
        }

        $classReflection = new \ReflectionClass($class);
        if (!$classReflection->hasMethod($method)) {
            return 'method not found';
        }

        $methodReflection = $classReflection->getMethod($method);
        $declaringClass = $methodReflection->getDeclaringClass()->getName();
        if (strcasecmp($declaringClass, $class) !== 0) {
            return sprintf('method is declared in %s', $declaringClass);
        }

        $tokens = $this->getMethodTokens($methodReflection);
        if ($isProperty) {
            return $this->isPropertyRead($tokens, $name) ? null : 'property is not read in the method';
        }

        return $this->isVariableUsed($tokens, $name) ? null : 'variable is not used in the method';
    }

    private function classExists(string $class): bool
    {
        return class_exists($class) || interface_exists($class) || trait_exists($class);
    }

    /**
     * @return \PhpToken[]
     */
    private function getMethodTokens(\ReflectionMethod $method): array
    {
        $file = $method->getFileName();
        if (!$file) {
            return [];
        }

        $length = $method->getEndLine() - $method->getStartLine() + 1;
        $lines = array_slice(file($file), $method->getStartLine() - 1, $length);

        return \PhpToken::tokenize('<?php ' . implode('', $lines));
    }

    private function usesFuncGetArgs(\ReflectionMethod $method): bool
    {
        foreach ($this->getMethodTokens($method) as $token) {
            if ($token->is(T_STRING) && strcasecmp($token->text, 'func_get_args') === 0) {
                return true;
            }
        }

        return false;
    }

    private function isVariableUsed(array $tokens, string $name): bool
    {
        foreach ($tokens as $token) {
            if ($token->is(T_VARIABLE) && strcasecmp($token->text, '$' . $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isPropertyRead(array $tokens, string $name): bool
    {
        $afterArrow = false;
        foreach ($tokens as $token) {
            if ($token->isIgnorable()) {
                continue;
            }

            if ($afterArrow && $token->is(T_STRING) && strcasecmp($token->text, $name) === 0) {
                return true;
            }

            $afterArrow = $token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
        }

        return false;
    }
}

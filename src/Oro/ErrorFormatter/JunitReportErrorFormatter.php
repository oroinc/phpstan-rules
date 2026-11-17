<?php

declare(strict_types=1);

namespace Oro\ErrorFormatter;

use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;

/**
 * Prints errors as a table and writes them as JUnit test cases with tips to the file in PHPSTAN_JUNIT_REPORT.
 */
class JunitReportErrorFormatter implements ErrorFormatter
{
    private const REPORT_FILE_ENV = 'PHPSTAN_JUNIT_REPORT';
    private const PACKAGE_DIR = '/package/';
    private const SOURCE_DIR = '/src/';

    public function __construct(
        private ErrorFormatter $tableErrorFormatter
    ) {
    }

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $reportFile = getenv(self::REPORT_FILE_ENV);
        if ($reportFile) {
            file_put_contents($reportFile, $this->createReport($analysisResult));
        }

        return $this->tableErrorFormatter->formatErrors($analysisResult, $output);
    }

    private function createReport(AnalysisResult $analysisResult): string
    {
        $testCases = [];
        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            $testCases[] = $this->createErrorTestCase($error);
        }
        foreach ($analysisResult->getNotFileSpecificErrors() as $message) {
            $testCases[] = $this->createTestCase('phpstan', 'General error', $message, '');
        }
        foreach ($analysisResult->getWarnings() as $message) {
            $testCases[] = $this->createTestCase('phpstan', 'Warning', $message, '');
        }
        if (!$testCases) {
            $testCases[] = $this->createTestCase('phpstan', 'No errors');
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>%s<testsuite name="phpstan" tests="%d" failures="%d">%s%s%s</testsuite>%s',
            PHP_EOL,
            count($testCases),
            $analysisResult->getTotalErrorsCount() + count($analysisResult->getWarnings()),
            PHP_EOL,
            implode(PHP_EOL, $testCases),
            PHP_EOL,
            PHP_EOL
        );
    }

    private function createErrorTestCase(Error $error): string
    {
        $path = $this->getPackagePath($error->getFilePath());
        $location = sprintf('%s:%d', $path, (int)$error->getLine());

        $details = $error->getMessage();
        if ($error->getTip()) {
            $details .= PHP_EOL . $error->getTip();
        }

        return $this->createTestCase(
            $this->getClassName($path),
            sprintf('Line %d: %s', (int)$error->getLine(), strtok($error->getMessage(), "\n")),
            $details,
            $location
        );
    }

    private function createTestCase(
        string $className,
        string $name,
        ?string $details = null,
        ?string $location = null
    ): string {
        $testCase = sprintf('<testcase classname="%s" name="%s"', $this->escape($className), $this->escape($name));
        if ($details === null) {
            return $testCase . '/>';
        }

        return sprintf(
            '%s><failure type="ERROR" message="%s">%s</failure></testcase>',
            $testCase,
            $this->escape($details),
            $this->escape((string)$location)
        );
    }

    private function getPackagePath(string $file): string
    {
        $position = strrpos($file, self::PACKAGE_DIR);
        if ($position === false) {
            return $file;
        }

        return substr($file, $position + strlen(self::PACKAGE_DIR));
    }

    /**
     * Converts the path of a class file to a dotted class name.
     */
    private function getClassName(string $path): string
    {
        $position = strpos($path, self::SOURCE_DIR);
        if ($position !== false) {
            $path = substr($path, $position + strlen(self::SOURCE_DIR));
        }

        return str_replace('/', '.', preg_replace('/\.php$/', '', $path));
    }

    private function escape(string $value): string
    {
        return str_replace(
            ["\r\n", "\n"],
            '&#10;',
            htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }
}

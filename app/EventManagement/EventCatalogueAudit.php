<?php

declare(strict_types=1);

namespace App\EventManagement;

use App\EventManagement\DTO\CatalogueAuditReport;
use App\EventManagement\DTO\EventMetadata;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * Compares the documented inventory with event classes and observes real code references.
 * It checks structure with reflection and reports unreferenced definitions without failing them.
 */
final readonly class EventCatalogueAudit
{
    /**
     * Audits the catalogue document, application source and tests under one Core checkout.
     * The repository root must contain docs/event-management-catalogue.md, app/ and tests/.
     */
    public function audit(string $repositoryRoot): CatalogueAuditReport
    {
        $cataloguePath = $repositoryRoot . '/docs/event-management-catalogue.md';
        $catalogue = file_get_contents($cataloguePath);
        if ($catalogue === false) {
            throw new RuntimeException('Could not read the EventManagement catalogue document.');
        }

        $matches = [];
        preg_match_all('/`(App\\\\EventManagement\\\\Events\\\\[A-Za-z]+\\\\[A-Za-z]+)`/', $catalogue, $matches);
        $expectedEntries = $matches[1];
        $expected = array_values(array_unique($expectedEntries));
        $violations = [];
        if (count($expectedEntries) !== count($expected)) {
            $violations[] = 'The catalogue document contains duplicate event entries.';
        }

        $eventsRoot = $repositoryRoot . '/app/EventManagement/Events';
        $actual = [];
        foreach ($this->phpFiles($eventsRoot) as $path) {
            $relative = substr($path, strlen($eventsRoot) + 1);
            $actual[] = 'App\\EventManagement\\Events\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
        }

        sort($expected);
        sort($actual);
        $coverage = [];
        if (count($actual) !== count(array_unique($actual))) {
            $violations[] = 'The EventManagement/Events tree contains duplicate class paths.';
        }

        $applicationFiles = $this->phpFiles($repositoryRoot . '/app');
        $testFiles = $this->phpFiles($repositoryRoot . '/tests');
        $source = $this->readFiles($applicationFiles, excludePrefix: $eventsRoot);
        $tests = $this->readFiles($testFiles);
        foreach ($actual as $class) {
            if (! class_exists($class)) {
                $violations[] = "{$class} cannot be autoloaded.";
                continue;
            }

            $parts = explode('\\', $class);
            $shortName = array_pop($parts);
            $reflection = new ReflectionClass($class);
            $expectedNamespace = implode('\\', $parts);
            if ($reflection->getNamespaceName() !== $expectedNamespace) {
                $violations[] = "{$class} is declared in the wrong namespace.";
            }

            if (! $reflection->isFinal() || ! $reflection->isReadOnly()) {
                $violations[] = "{$class} must be final and readonly.";
            }

            $constructor = $reflection->getConstructor();
            $parameters = $constructor?->getParameters() ?? [];
            foreach ($parameters as $parameter) {
                if ($parameter->getType() !== null) {
                    continue;
                }

                $violations[] = "{$class} constructor parameter {$parameter->getName()} must be typed.";
            }

            $metadata = $parameters === [] ? null : $parameters[array_key_last($parameters)];
            if (
                $metadata === null
                || (string) $metadata->getType() !== EventMetadata::class
                || ! $metadata->isDefaultValueAvailable()
                || ! $metadata->getDefaultValue() instanceof EventMetadata
            ) {
                $violations[] = "{$class} must end its typed constructor with default EventMetadata.";
            }

            $dispatchPattern = '/(?:\\bTempest\\\\event|\\bevent|->dispatch)\s*\(\s*new\s+(?:\\\\[A-Za-z_][A-Za-z0-9_\\\\]*\\\\)?' . preg_quote($shortName, '/') . '\\b/';
            $emitted = preg_match($dispatchPattern, $source) === 1;
            $verified =
                preg_match(
                    '/assertDispatched\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_\\\\]*::class|new\s+(?:\\\\[A-Za-z_][A-Za-z0-9_\\\\]*\\\\)?' . preg_quote($shortName, '/') . '\\b)/',
                    $tests,
                ) === 1
                && preg_match(
                    '/assertNotDispatched\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_\\\\]*::class|new\s+(?:\\\\[A-Za-z_][A-Za-z0-9_\\\\]*\\\\)?' . preg_quote($shortName, '/') . '\\b)/',
                    $tests,
                ) === 1;
            $consumed = preg_match('/#\[EventHandler[^]]*\][\s\S]{0,700}\\b' . preg_quote($shortName, '/') . '\\s+\$event/', $source) === 1;

            $coverage[$class] = [
                'defined' => true,
                'emitted' => $emitted,
                'verified' => $verified,
                'consumed' => $consumed,
            ];
        }

        return new CatalogueAuditReport(
            missingClasses: array_values(array_diff($expected, $actual)),
            unexpectedClasses: array_values(array_diff($actual, $expected)),
            contractViolations: $violations,
            coverage: $coverage,
        );
    }

    /**
     * Lists PHP files below a directory for catalogue and reference scanning.
     * A missing directory produces an empty list, which lets the audit detect removals.
     *
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $path => $_) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * Reads a bounded set of source files into one searchable string.
     * Event class declarations can be excluded so they do not appear self-referenced.
     *
     * @param list<string> $files
     */
    private function readFiles(array $files, ?string $excludePrefix = null): string
    {
        $contents = '';
        foreach ($files as $file) {
            if ($excludePrefix !== null && str_starts_with($file, $excludePrefix . '/')) {
                continue;
            }

            $contents .= "\n" . (file_get_contents($file) ?: '');
        }

        return $contents;
    }
}

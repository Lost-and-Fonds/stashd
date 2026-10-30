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
 * Compares the documented inventory with reflected event contracts.
 * Emission, acceptance coverage and consumption remain unverified until backed by explicit integration evidence.
 */
final readonly class EventCatalogueAudit
{
    /**
     * Audits the catalogue document, application source and tests under one Core checkout.
     * The repository root must contain docs/event-management-catalogue.md and app/.
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

        foreach ($actual as $class) {
            if (! class_exists($class)) {
                $violations[] = "{$class} cannot be autoloaded.";
                continue;
            }

            $parts = explode('\\', $class);
            array_pop($parts);
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

            // Source references alone cannot prove emission timing, acceptance coverage or consumption.
            $emitted = null;
            $verified = null;
            $consumed = null;

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
}

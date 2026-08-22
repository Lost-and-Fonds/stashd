<?php

declare(strict_types=1);

/**
 * Machine-checks the M0 inventory against the production baseline files.
 *
 * This is design verification only. It is not loaded by Stashd and does not
 * implement a native runtime.
 */
$root = dirname(__DIR__);
$inventoryPath = __DIR__.'/native-plugin-compatibility.json';
$inventory = json_decode((string) file_get_contents($inventoryPath), true, flags: JSON_THROW_ON_ERROR);

if (($inventory['baseline'] ?? null) !== 'b3c393a') {
    throw new RuntimeException('Inventory baseline must remain b3c393a.');
}

/** @param array<string, mixed> $value */
function requireString(array $value, string $key): string
{
    $result = $value[$key] ?? null;
    if (! is_string($result) || $result === '') {
        throw new RuntimeException("Missing string inventory field: {$key}");
    }

    return $result;
}

function readRepoFile(string $root, string $relative): string
{
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        throw new RuntimeException("Missing repository file: {$relative}");
    }

    return (string) file_get_contents($path);
}

/** @param list<string> $needles */
function requireAll(string $content, array $needles, string $label): void
{
    foreach ($needles as $needle) {
        if (! str_contains($content, $needle)) {
            throw new RuntimeException("{$label} is missing: {$needle}");
        }
    }
}

$contract = $inventory['contract']['active_wit'] ?? null;
if (! is_array($contract) || count($contract) !== 2) {
    throw new RuntimeException('Expected exactly two active WIT contracts.');
}

foreach ($contract as $definition) {
    if (! is_array($definition)) {
        throw new RuntimeException('Malformed WIT inventory entry.');
    }

    $content = readRepoFile($root, requireString($definition, 'file'));
    requireAll($content, [
        'package '.requireString($definition, 'package').';',
        'world '.requireString($definition, 'world'),
    ], 'WIT contract '.$definition['file']);

    foreach (['imports', 'exports', 'operations'] as $field) {
        $values = $definition[$field] ?? null;
        if (! is_array($values)) {
            throw new RuntimeException("Malformed {$field} inventory for {$definition['file']}.");
        }
        requireAll($content, array_map(static fn (mixed $value): string => (string) $value, $values), 'WIT contract '.$definition['file']);
    }
}

$implementations = $inventory['implementations'] ?? null;
if (! is_array($implementations)) {
    throw new RuntimeException('Missing implementation inventory.');
}

foreach ($implementations as $manifestPath => $definition) {
    if (! is_array($definition)) {
        throw new RuntimeException("Malformed implementation inventory: {$manifestPath}");
    }

    $manifest = json_decode(readRepoFile($root, $manifestPath), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($manifest)) {
        throw new RuntimeException("Manifest is not an object: {$manifestPath}");
    }

    $key = requireString($definition, 'logical_key_field');
    if (($manifest[$key] ?? null) !== ($definition['logical_key'] ?? null)) {
        throw new RuntimeException("Logical key mismatch in {$manifestPath}");
    }

    foreach (['id', 'name', 'version', 'component'] as $required) {
        if (! is_string($manifest[$required] ?? null) || $manifest[$required] === '') {
            if ($required === 'component' && is_string($manifest['component_environment'] ?? null)) {
                continue;
            }
            throw new RuntimeException("{$manifestPath} lacks manifest field {$required}");
        }
    }
}

$hostClient = readRepoFile($root, 'app/Plugins/PluginHostClient.php');
$hostInventory = $inventory['host_adapters']['app/Plugins/PluginHostClient.php'] ?? [];
foreach (['input_invocations', 'broadcast_invocations', 'events', 'authority'] as $field) {
    $values = $hostInventory[$field] ?? null;
    if (! is_array($values)) {
        throw new RuntimeException("Missing host inventory field {$field}.");
    }
    requireAll($hostClient, array_map(static fn (mixed $value): string => (string) $value, $values), 'PluginHostClient');
}

$example = readRepoFile($root, 'plugins/example/src/lib.rs');
if (! str_contains($example, 'plugin-api/spike-wit')) {
    throw new RuntimeException('Example fixture no longer declares its quarantined spike contract explicitly.');
}

if (($inventory['review']['semantic_gaps'] ?? null) !== []) {
    throw new RuntimeException('M0 inventory contains an unresolved semantic gap.');
}

echo "M0 compatibility inventory: PASS\n";
echo 'Active WIT contracts: '.count($contract)."\n";
echo 'Manifest implementations: '.count($implementations)."\n";
echo "Semantic gaps: 0\n";

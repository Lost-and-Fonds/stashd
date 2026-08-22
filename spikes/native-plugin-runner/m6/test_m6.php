<?php

declare(strict_types=1);

use Stashd\NativeCapabilities\HelperGrant;
use Stashd\NativeCapabilities\Invocation;
use Stashd\NativePackages\PackageManager;
use Stashd\NativePackages\PackageValidationError;
use Stashd\NativePackages\PackageStateError;

require_once __DIR__ . '/../m4/sdk/Sdk.php';
require_once __DIR__ . '/../m5/Capabilities.php';
require_once __DIR__ . '/PackageManager.php';

function m6Temp(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700, true)) { throw new RuntimeException('temporary directory could not be created'); }
    return $path;
}

function m6Remove(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { m6Remove($path . '/' . $entry); } }
    @rmdir($path);
}

function m6Expect(Closure $operation, string $class): void
{
    try { $operation(); throw new RuntimeException('expected ' . $class); } catch (Throwable $exception) { if (!$exception instanceof $class) { throw $exception; } }
}

function manifest(string $id, string $version, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id, 'name' => 'Fixture ' . $id, 'version' => $version, 'runtime' => 'php', 'api_version' => '0.1',
        'entrypoint' => 'plugin.php', 'requires' => ['php' => '>=8.5', 'extensions' => []], 'architectures' => ['amd64', 'arm64'],
    ], $overrides);
}

function makeSource(string $root, string $id, string $version, array $overrides = []): string
{
    $source = $root . '/' . $id . '-' . $version;
    mkdir($source, 0700, true);
    file_put_contents($source . '/plugin.json', json_encode(manifest($id, $version, $overrides), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($source . '/plugin.php', "<?php\n");
    return $source;
}

function archiveDirectory(string $source, string $archive): void
{
    $pipes = [];
    $process = proc_open(['tar', '-czf', $archive, '-C', $source, '.'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) { throw new RuntimeException('fixture archive failed'); }
}

/** @param list<array{path:string,type:string,data?:string,link?:string}> $entries */
function rawArchive(string $archive, array $entries): void
{
    $output = gzopen($archive, 'wb9');
    if ($output === false) { throw new RuntimeException('raw archive could not open'); }
    foreach ($entries as $entry) {
        $header = str_repeat("\0", 512);
        $put = static function (string &$header, int $offset, int $length, string $value): void { $header = substr_replace($header, str_pad(substr($value, 0, $length), $length, "\0"), $offset, $length); };
        $put($header, 0, 100, $entry['path']);
        $size = strlen($entry['data'] ?? '');
        $put($header, 100, 8, sprintf('%07o', 0644) . "\0");
        $put($header, 108, 8, sprintf('%07o', 0) . "\0");
        $put($header, 116, 8, sprintf('%07o', 0) . "\0");
        $put($header, 124, 12, sprintf('%011o', $size) . "\0");
        $put($header, 136, 12, sprintf('%011o', time()) . "\0");
        $put($header, 148, 8, '        ');
        $put($header, 156, 1, $entry['type'] === 'file' ? "\0" : $entry['type']);
        $put($header, 157, 100, $entry['link'] ?? '');
        $put($header, 257, 6, "ustar\0");
        $checksum = array_sum(unpack('C*', $header));
        $put($header, 148, 8, sprintf('%06o', $checksum) . "\0 ");
        gzwrite($output, $header);
        if ($size > 0) { gzwrite($output, $entry['data']); gzwrite($output, str_repeat("\0", (512 - ($size % 512)) % 512)); }
    }
    gzwrite($output, str_repeat("\0", 1024));
    gzclose($output);
}

$root = m6Temp('stashd-m6');
$sourceRoot = $root . '/sources';
$archiveRoot = $root . '/archives';
mkdir($sourceRoot, 0700, true);
mkdir($archiveRoot, 0700, true);
$manager = new PackageManager($root . '/plugins', '0.1', 'amd64');

$v1Source = makeSource($sourceRoot, 'example-plugin', '1.0.0');
$v1Archive = $archiveRoot . '/example-1.0.0.tar.gz';
archiveDirectory($v1Source, $v1Archive);
$v1 = $manager->install($v1Archive, hash_file('sha256', $v1Archive));
assert($v1->version === '1.0.0' && $manager->activeVersion('example-plugin') === null);
assert(is_dir($root . '/plugins/packages/example-plugin/1.0.0'));
assert(!is_writable($root . '/plugins/packages/example-plugin/1.0.0/plugin.json'));
if (@file_put_contents($root . '/plugins/packages/example-plugin/1.0.0/plugin.json', 'mutated') !== false) { throw new RuntimeException('installed package is writable'); }
$v2Source = makeSource($sourceRoot, 'example-plugin', '1.1.0');
$v2Archive = $archiveRoot . '/example-1.1.0.tar.gz';
archiveDirectory($v2Source, $v2Archive);
$manager->activate('example-plugin', '1.0.0');
$manager->install($v2Archive, hash_file('sha256', $v2Archive));
assert($manager->activeVersion('example-plugin') === '1.0.0');
$manager->activate('example-plugin', '1.1.0');
assert($manager->activeVersion('example-plugin') === '1.1.0');
m6Expect(static fn () => $manager->activate('example-plugin', '9.9.9'), PackageStateError::class);
assert($manager->activeVersion('example-plugin') === '1.1.0');
$manager->rollback('example-plugin', '1.0.0');
assert($manager->activeVersion('example-plugin') === '1.0.0');
m6Expect(static fn () => $manager->remove('example-plugin', '1.0.0'), PackageStateError::class);
$manager->disable('example-plugin');
assert($manager->activeVersion('example-plugin') === null && is_dir($root . '/plugins/packages/example-plugin/1.0.0'));
$manager->remove('example-plugin', '1.1.0');
$manager->remove('example-plugin', '1.0.0');
assert($manager->activeVersion('example-plugin') === null);

$otherSource = makeSource($sourceRoot, 'other-plugin', '1.0.0');
$otherArchive = $archiveRoot . '/other.tar.gz';
archiveDirectory($otherSource, $otherArchive);
$manager->install($otherArchive, hash_file('sha256', $otherArchive));
assert(is_dir($root . '/plugins/packages/other-plugin/1.0.0'));

$checksumArchive = $archiveRoot . '/checksum.tar.gz';
archiveDirectory($v1Source, $checksumArchive);
m6Expect(static fn () => $manager->install($checksumArchive, str_repeat('0', 64)), PackageValidationError::class);
$corrupt = $archiveRoot . '/corrupt.tar.gz';
file_put_contents($corrupt, 'not an archive');
m6Expect(static fn () => $manager->install($corrupt, hash_file('sha256', $corrupt)), PackageValidationError::class);
$invalid = $archiveRoot . '/invalid.tar.gz';
rawArchive($invalid, [['path' => 'plugin.php', 'type' => 'file', 'data' => '<?php']]);
m6Expect(static fn () => $manager->install($invalid, hash_file('sha256', $invalid)), PackageValidationError::class);
$traversal = $archiveRoot . '/traversal.tar.gz';
rawArchive($traversal, [['path' => '../escape.txt', 'type' => 'file', 'data' => 'bad']]);
m6Expect(static fn () => $manager->install($traversal, hash_file('sha256', $traversal)), PackageValidationError::class);
$symlink = $archiveRoot . '/symlink.tar.gz';
rawArchive($symlink, [['path' => 'plugin.json', 'type' => 'file', 'data' => '{}'], ['path' => 'escape', 'type' => '2', 'link' => '/etc/passwd']]);
m6Expect(static fn () => $manager->install($symlink, hash_file('sha256', $symlink)), PackageValidationError::class);
$hardlink = $archiveRoot . '/hardlink.tar.gz';
rawArchive($hardlink, [['path' => 'plugin.json', 'type' => 'file', 'data' => '{}'], ['path' => 'escape', 'type' => '1', 'link' => 'plugin.json']]);
m6Expect(static fn () => $manager->install($hardlink, hash_file('sha256', $hardlink)), PackageValidationError::class);

foreach ([
    ['bad-id', '1.0.0', ['id' => 'Bad_ID']],
    ['bad-api', '1.0.0', ['api_version' => '9.0']],
    ['bad-runtime', '1.0.0', ['runtime' => 'python']],
    ['bad-arch', '1.0.0', ['architectures' => ['sparc']]],
    ['bad-php', '1.0.0', ['requires' => ['php' => '>=99.0']]],
    ['bad-extension', '1.0.0', ['requires' => ['extensions' => ['extension_that_does_not_exist']]]],
] as [$id, $version, $overrides]) {
    $source = makeSource($sourceRoot, $id, $version, $overrides);
    $archive = $archiveRoot . '/' . $id . '.tar.gz';
    archiveDirectory($source, $archive);
    m6Expect(static fn () => $manager->install($archive, hash_file('sha256', $archive)), PackageValidationError::class);
}

$linkedSource = realpath(__DIR__ . '/linked-package');
$manager->link('linked-example', $linkedSource);
assert($manager->activePath('linked-example') === $linkedSource);
$linkedStage = m6Temp('stashd-m6-linked-stage');
$linkedInvocation = new Invocation($linkedSource, $linkedStage, [], [], null, [new HelperGrant('probe', 'helpers/probe.php')]);
$linkedResult = $linkedInvocation->runHelper('probe');
assert($linkedResult->exitCode === 0);
$linkedReport = json_decode((string) file_get_contents($linkedStage . '/linked-report.json'), true, 512, JSON_THROW_ON_ERROR);
assert($linkedReport === ['vault' => 'denied', 'network' => 'denied']);
assert(!file_exists($linkedSource . '/LINKED_SOURCE_MUTATION'));
$linkedInvocation->close();
$manager->unlink('linked-example');
assert($manager->activeVersion('linked-example') === null && is_dir($root . '/plugins/packages/other-plugin/1.0.0'));

m6Remove($root);
echo "M6 package lifecycle conformance: PASS\n";

# Core PHPDoc declaration audit

Audited on 2026-10-21 against starting `dev` commit
`5d82d3792a80639d84ba4c48be8eb5625c74c215`.

## Scope and results

All 61 maintained PHP source files were inspected: 60 tracked `*.php` files and
the extensionless `tempest` PHP launcher. This includes configuration returns,
templates, entry points, test helpers and the crash-process fixture. Dependencies
in `vendor/` and generated or untracked files are not maintained Core source.

| Source area | Files | Types | Methods | Named functions |
| --- | ---: | ---: | ---: | ---: |
| Administration | 3 | 1 | 1 | 0 |
| Records & Archives | 17 | 18 | 98 | 0 |
| Vault browser | 7 | 6 | 19 | 0 |
| Acceptance tests | 17 | 2 | 18 | 6 |
| Test support | 10 | 9 | 39 | 0 |
| Other tests (`TestCase.php`, `Pest.php`, Unit, Feature) | 5 | 1 | 0 | 0 |
| Public entry point and CLI launcher | 2 | 0 | 0 | 0 |
| **Total** | **61** | **37** | **175** | **6** |

All **212 applicable type and method declarations** have multiline PHPDoc with
English descriptions. The six standalone named test helper functions are also
documented, giving **218 documented declarations** in the broader audit.
Existing useful descriptions and correct type annotations were retained; several
annotation-only and inherited-placeholder blocks now explain local behavior.

The audit uses the already installed `nikic/php-parser` AST, including attributes
and nested statements, rather than regex declaration matching. It visits
`ClassLike` (classes, interfaces, enums and traits), `ClassMethod` and `Function_`
nodes. Both anonymous classes and all eight of their methods are included.
A negative fixture confirmed detection of missing, one-line and annotation-only
blocks, including attributed and nested anonymous declarations. Descriptions were
reviewed against implementation, relevant dependency code and
acceptance assertions; presence alone cannot establish useful or accurate prose.

There are **no missing-documentation exceptions**. Anonymous class PHPDoc sits
between `new` and `readonly class`, where the parser attaches it to the type.
Mago keeps the closing comment and class keyword on the same physical line;
the block itself is multiline. Closures, arrow functions, properties, enum cases,
trait-use statements and inherited vendor declarations are not local type or
method declarations. No local trait declarations exist in this snapshot.

## Validation

| Check | Result |
| --- | --- |
| Parser declaration audit | 218/218 documented, including 212/212 types and methods; no single-line or annotation-only blocks |
| PHP `-l` | All 61 maintained PHP files passed |
| Significant-token comparison with starting `dev` | All 61 files identical after excluding comments and whitespace |
| `composer fmt`, then `mago fmt --check` through Lerd | Passed; formatting adjustments were confined to the new anonymous-class comments |
| `composer lint` through Lerd | Failed: 1 error, 4 warnings, 6 help messages; same findings on untouched starting `dev` |
| `composer analyse` through Lerd | Failed: 1,037 errors, 142 warnings, 9 help messages; starting `dev` has the same totals |
| `./bin/test.sh` | Exit 0: 184 passed, 33 incomplete, 2,779 assertions; four parallel processes |
| `git diff --check` | Passed |

The complete existing suite includes real CLI, HTTP, archival publication,
concurrency and crash-boundary scenarios. Incomplete acceptance operations remain
explicitly marked by the existing driver; no test was disabled or rewritten.
No new runtime feature was introduced, so feature mutation testing is not
applicable to this documentation pass.

The token comparison includes token kind and exact token text. String literals,
attributes, executable template content, declaration modifiers and signatures
are unchanged. Together with the syntax checks and existing suite, this confirms
the PHP changes are documentation-only. No dependency, configuration, architecture
or archival behavior was changed.

## Observations left for separate work

- Lint already rejects a `break` inside the cleanup `finally` block in
  `ObjectWriteLockTest.php`. Existing warnings include implicit octal notation and
  chained assignment. The lint script stops before its automatic-fix stage.
- Static analysis already reports extensive Pest typing, mixed-value and other
  issues. Matching totals do not mean identical diagnostics: the new accurate
  `BrowserServer::request()` result shape exposes an existing unchecked
  `string|false` body passed to `hash()` in `VaultBrowserTest.php`. Documenting the
  anonymous validation view's version list removes an existing mixed-iterator
  error. Propagated return-union wording also changes for `LocalArchivesDriver`.
- Item first-creation selection occurs before its write lock, so concurrent first
  creators may require retrying the entire call. Item pin checks and Item
  publication are separate from Asset publication, with no multi-Object
  transaction. Asset post-commit verification can fail after a version exists.
  These current boundaries are now explicit in PHPDoc; this PR does not alter them.

## Reproducing coverage and token checks

From the repository root, save the following as the temporary, untracked
`.phpdoc-audit.php`, run `lerd php .phpdoc-audit.php`, then remove that temporary
file. It uses an existing development dependency and installs nothing. The fixed
base below deliberately checks this pass against its starting commit.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$tracked = explode("\0", trim(shell_exec('git ls-files -z'), "\0"));
$files = array_values(array_filter($tracked, static fn ($file) =>
    str_ends_with($file, '.php')
    || preg_match('/^#![^\n]*\bphp\b/', substr(file_get_contents($file), 0, 128))
));
$counts = ['type' => 0, 'method' => 0, 'function' => 0];
$failures = [];
$walk = function ($node, string $file) use (&$walk, &$counts, &$failures): void {
    if (! $node instanceof PhpParser\Node) return;
    $kind = match (true) {
        $node instanceof PhpParser\Node\Stmt\ClassLike => 'type',
        $node instanceof PhpParser\Node\Stmt\ClassMethod => 'method',
        $node instanceof PhpParser\Node\Stmt\Function_ => 'function',
        default => null,
    };
    if ($kind !== null) {
        $counts[$kind]++;
        $doc = $node->getDocComment()?->getText() ?? '';
        $lines = explode("\n", $doc);
        $description = [];
        foreach ($lines as $line) {
            $line = trim(ltrim(trim($line), '*'));
            if (str_starts_with($line, '@')) break;
            if ($line !== '' && ! str_starts_with($line, '/')) $description[] = $line;
        }
        if (count($lines) < 3 || $description === []) {
            $failures[] = $file . ':' . $node->getStartLine();
        }
    }
    foreach ($node->getSubNodeNames() as $key) {
        $children = $node->$key;
        foreach (is_array($children) ? $children : [$children] as $child) {
            $walk($child, $file);
        }
    }
};
$significant = static function (string $source): array {
    $result = [];
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
        $result[] = is_array($token) ? [$token[0], $token[1]] : $token;
    }
    return $result;
};
foreach ($files as $file) {
    $source = file_get_contents($file);
    foreach ($parser->parse($source) as $node) $walk($node, $file);
    $base = shell_exec('git show 5d82d3792a80639d84ba4c48be8eb5625c74c215:' . escapeshellarg($file));
    if ($significant($base) !== $significant($source)) {
        $failures[] = 'Executable tokens changed: ' . $file;
    }
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $messages, $status);
    if ($status !== 0) $failures[] = 'PHP syntax: ' . $file;
}
echo json_encode(['files' => count($files), 'declarations' => $counts, 'failures' => $failures], JSON_PRETTY_PRINT), "\n";
exit($failures === [] ? 0 : 1);
```

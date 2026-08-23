# PHP coding standards

Stashd follows applicable [PHP-FIG](https://www.php-fig.org/psr/) standards. Enforce them before committing.

## Applicable PSRs

| PSR | Scope | How we comply |
|-----|--------|----------------|
| **PSR-1** | Basic coding | `declare(strict_types=1);`, namespaces, **one class/enum/interface/trait per file** |
| **PSR-4** | Autoloading | `App\` → `app/`, `Tests\` → `tests/` (`composer.json`) |
| **PER Coding Style 3.0** | Code style | PHP CS Fixer `@PER-CS3x0` ruleset (`.php-cs-fixer.dist.php`) |
| **PSR-1 / PSR-4** | File structure | PHPCS structural rules (`phpcs.xml.dist`) |
| **PSR-3** | Logging | Via Tempest log package when used (not custom loggers in domain) |
| **PSR-7 / 17** | HTTP messages | Tempest handles request/response internally (classic-mode SAPI; no PSR-7 bridge) |
| **PSR-11** | Container | Tempest `Container` / DI; prefer constructor injection |
| **PSR-15** | HTTP middleware | `RequireAuthMiddleware` implements Tempest `HttpMiddleware` |

PSRs we do **not** implement directly today: PSR-6/16 (cache), PSR-18 (HTTP client — Tempest wraps Guzzle), PSR-20 (clock — Tempest `Clock`).

## Commands

```bash
composer lint        # strict PSR-4, PHP CS Fixer/PER-CS 3.0, PHPCS
composer test:static # PHPStan
composer format      # auto-fix style
```

`composer lint` runs Composer's strict PSR-4 check, PHP CS Fixer with PER
Coding Style 3.0, and PHPCS structural checks. `composer test:static` runs
PHPStan. PHPCS covers the PSR-1 structural rules, including one named type per
file; Composer checks the actual PSR-4 namespace-to-path mappings.

## Non-negotiables

Before implementing generic infrastructure, check Tempest first. If Tempest does not provide the needed behavior, use an established maintained dependency or tool; custom implementations require a concrete project-specific reason. Prefer Tempest over Symfony where practical. Approved choices include Tempest Storage/filesystem/Process/Validator/Cache/Scheduler/EventBus/Logger, `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `composer/semver`, `symfony/uid`, `umoci` if its packaging spike succeeds, and `opis/json-schema` as approved-to-investigate only.

1. **One type per file** — enums, records, services, exceptions each get their own file matching the type name (see `database-conventions.md`). Multi-type files break Tempest discovery with fatal redeclare errors.
2. **`declare(strict_types=1)`** on every new PHP file.
3. **Import types** — no `\Fully\Qualified\Class` in method bodies when a `use` statement suffices.
4. **Alphabetical `use` imports** — Pint enforces `ordered_imports`.
5. **No closing `?>`** in pure PHP files.

## Namespace imports

Prefer individual imports when only a few symbols from a namespace are used.
When many symbols come from one coherent namespace, import that namespace once
with a short, meaningful alias when `Alias\\Type` remains clear and reduces
noise. Apply this judgmentally; it is not a lint rule.

### Vertical spacing

Use blank lines to separate logical steps.

In particular:

- prefer a blank line before control-flow blocks such as `if`, `foreach`, `for`, `while`, `switch`, and `try`;
- prefer a blank line before terminal/control-transfer statements such as `return`, `throw`, `continue`, and `break`;
- keep tightly related statements together;
- do not add blank lines merely to satisfy the rule when a block is already trivial.

The goal is for control flow to be visually obvious when scanning a method.

## API vs PHP naming

PER Coding Style 3.0 governs PHP source. REST JSON remains **snake_case** per the engineering spec; translate at controller boundaries only.

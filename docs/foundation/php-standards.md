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
composer lint      # check PER-CS 3.0 and PSR-1/PSR-4 structure (CI gate)
composer format    # auto-fix style
```

## Non-negotiables

1. **One type per file** — enums, records, services, exceptions each get their own file matching the type name (see `database-conventions.md`). Multi-type files break Tempest discovery with fatal redeclare errors.
2. **`declare(strict_types=1)`** on every new PHP file.
3. **Import types** — no `\Fully\Qualified\Class` in method bodies when a `use` statement suffices.
4. **Alphabetical `use` imports** — Pint enforces `ordered_imports`.
5. **No closing `?>`** in pure PHP files.

## API vs PHP naming

PER Coding Style 3.0 governs PHP source. REST JSON remains **snake_case** per the engineering spec; translate at controller boundaries only.

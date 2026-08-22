<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Providers\InputOption;
use Tempest\Mapper\SerializeAs;

/**
 * Per-input filter choices captured at add-input time: the universal
 * title-regex tier plus whatever provider-declared options (App\Providers\InputOption)
 * the user toggled. `provider` keys are opaque provider option keys (e.g.
 * provider-declared keys), not DTO field names — kept out of the snake/camel API
 * boundary transform deliberately (see StashController::addInput()).
 */
#[SerializeAs('stash_input_options')]
final readonly class StashInputOptions
{
    /** @param array<string, bool|string> $provider */
    public function __construct(
        public ?string $titleRegexInclude = null,
        public ?string $titleRegexExclude = null,
        public array $provider = [],
    ) {}

    /** @param array<string, mixed>|null $options */
    public static function fromArray(?array $options): ?self
    {
        if ($options === null) {
            return null;
        }

        $titleRegexInclude = self::stringValue($options['titleRegexInclude'] ?? $options['title_regex_include'] ?? null);
        $titleRegexExclude = self::stringValue($options['titleRegexExclude'] ?? $options['title_regex_exclude'] ?? null);
        $provider = $options['provider'] ?? [];

        if ($titleRegexInclude === null && $titleRegexExclude === null && (! is_array($provider) || $provider === [])) {
            return null;
        }

        return new self(
            titleRegexInclude: $titleRegexInclude,
            titleRegexExclude: $titleRegexExclude,
            provider: is_array($provider) ? self::boolOrStringMap($provider) : [],
        );
    }

    /**
     * The effective value for a provider-declared option: the chosen value if
     * present, otherwise the option's own default.
     */
    public function providerValue(InputOption $option): bool|string
    {
        return $this->provider[$option->key] ?? $option->default;
    }

    public static function isValidTitleRegex(string $pattern): bool
    {
        return self::matches($pattern, '') !== null;
    }

    /**
     * `preg_match()` emits an `E_WARNING` for a malformed pattern rather than
     * just returning `false` — under a strict/PHPUnit error handler that
     * becomes an uncatchable warning, so `@`-suppression alone isn't reliable
     * here. Swap in a no-op handler for the duration of the call instead.
     *
     * @return bool|null null when the pattern itself is invalid
     */
    public static function matches(string $pattern, string $subject): ?bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            $result = preg_match(self::delimitedPattern($pattern), $subject);
        } finally {
            restore_error_handler();
        }

        return $result === false ? null : $result === 1;
    }

    public static function delimitedPattern(string $pattern): string
    {
        return '#' . str_replace('#', '\#', $pattern) . '#u';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'titleRegexInclude' => $this->titleRegexInclude,
            'titleRegexExclude' => $this->titleRegexExclude,
            'provider' => $this->provider,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $values
     * @return array<string, bool|string>
     */
    private static function boolOrStringMap(array $values): array
    {
        $map = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (is_bool($value) || is_string($value)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

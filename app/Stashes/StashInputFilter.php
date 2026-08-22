<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Providers\InputOption;
use App\Providers\InputOptionType;

final class StashInputFilter
{
    /**
     * @param  list<InputOption>  $declaredOptions
     */
    public function ignoredReason(string $title, ?string $contentType, ?StashInputOptions $options, array $declaredOptions): ?string
    {
        if ($options?->titleRegexInclude !== null
            && StashInputOptions::matches($options->titleRegexInclude, $title) === false) {
            return 'filter_title_regex';
        }

        if ($options?->titleRegexExclude !== null
            && StashInputOptions::matches($options->titleRegexExclude, $title) === true) {
            return 'filter_title_regex';
        }

        foreach ($declaredOptions as $option) {
            if ($option->type === InputOptionType::Bool
                && ($options?->providerValue($option) ?? $option->default) === false
                && in_array($contentType, $option->excludesContentTypes, true)) {
                return 'filter_video_type';
            }
        }

        return null;
    }

    public function isFilterReason(?string $reason): bool
    {
        return in_array($reason, ['filter_title_regex', 'filter_video_type'], true);
    }
}

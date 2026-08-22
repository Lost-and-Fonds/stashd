<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class PluginContext
{
    public function __construct(
        public Logger $logger = new NullLogger(),
        public ProgressReporter $progress = new NullProgressReporter(),
        public HttpClient $http = new UnavailableHttpClient(),
        public ?StagingArea $staging = null,
    ) {}
}

<?php

namespace App\Services\WebMarketData\DTO;

class WebSourceCandidate
{
    /**
     * @param  array<string, string>  $extraUrls
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceName,
        public string $url,
        public string $parserKey,
        public string $quality,
        public int $priority,
        public ?string $venue = null,
        public ?string $mic = null,
        public int $confidenceScore = 0,
        public bool $verified = false,
        public array $extraUrls = [],
    ) {}
}

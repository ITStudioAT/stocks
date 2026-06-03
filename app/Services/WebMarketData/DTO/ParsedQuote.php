<?php

namespace App\Services\WebMarketData\DTO;

use Illuminate\Support\Carbon;

class ParsedQuote
{
    public function __construct(
        public string $sourceKey,
        public string $sourceName,
        public string $sourceUrl,
        public string $sourceQuality,
        public ?string $venue = null,
        public ?string $mic = null,
        public ?string $isin = null,
        public ?string $wkn = null,
        public ?string $symbol = null,
        public ?string $currency = null,
        public ?string $bid = null,
        public ?string $ask = null,
        public ?string $last = null,
        public ?string $close = null,
        public ?string $nav = null,
        public ?string $price = null,
        public string $priceType = 'unavailable',
        public ?Carbon $asOf = null,
        public Carbon|string|null $fetchedAt = null,
        public string $freshnessStatus = 'unavailable',
        public ?string $rawTextHash = null,
        public ?array $rawPayload = null,
    ) {
        if (! $this->fetchedAt instanceof Carbon) {
            $this->fetchedAt = $this->fetchedAt ? Carbon::parse($this->fetchedAt) : now();
        }
    }
}

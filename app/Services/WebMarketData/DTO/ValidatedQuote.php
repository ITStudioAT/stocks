<?php

namespace App\Services\WebMarketData\DTO;

class ValidatedQuote
{
    /**
     * @param  array<int, string>  $validationErrors
     */
    public function __construct(
        public ParsedQuote $quote,
        public string $validationStatus,
        public string $freshnessStatus,
        public array $validationErrors = [],
        public ?string $spreadAbs = null,
        public ?string $spreadPct = null,
    ) {}

    public function isSelectable(): bool
    {
        return in_array($this->validationStatus, ['valid', 'suspicious'], true)
            && $this->quote->price !== null
            && ! in_array($this->freshnessStatus, ['stale', 'unavailable', 'invalid'], true);
    }
}

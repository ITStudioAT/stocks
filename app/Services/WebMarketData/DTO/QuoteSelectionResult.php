<?php

namespace App\Services\WebMarketData\DTO;

class QuoteSelectionResult
{
    /**
     * @param  array<int, ValidatedQuote>  $quotes
     * @param  array<int, WebSourceCandidate>  $attemptedSources
     * @param  array<int, ParserDiagnostics>  $diagnostics
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public ?ValidatedQuote $selectedQuote,
        public array $quotes,
        public array $attemptedSources,
        public array $diagnostics = [],
        public array $errors = [],
        public string $status = 'unavailable',
        public ?ValidatedQuote $crossCheckQuote = null,
        public array $usedQuotes = [],
        public ?string $arithmeticMean = null,
        public ?string $median = null,
        public ?string $confidence = null,
        public ?string $reason = null,
    ) {}
}

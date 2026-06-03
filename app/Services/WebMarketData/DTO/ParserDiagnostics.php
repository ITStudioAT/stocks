<?php

namespace App\Services\WebMarketData\DTO;

class ParserDiagnostics
{
    /**
     * @param  array<int, string>  $messages
     */
    public function __construct(
        public string $parserKey,
        public array $messages = [],
    ) {}

    public function add(string $message): void
    {
        $this->messages[] = $message;
    }
}

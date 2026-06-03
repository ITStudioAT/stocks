<?php

namespace App\Services\WebMarketData\Parsers;

class EuronextLiveParser extends GenericOfficialQuoteParser
{
    public function __construct()
    {
        parent::__construct('euronext_live');
    }
}

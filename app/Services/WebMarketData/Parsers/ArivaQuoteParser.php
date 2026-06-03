<?php

namespace App\Services\WebMarketData\Parsers;

class ArivaQuoteParser extends GenericOfficialQuoteParser
{
    public function __construct()
    {
        parent::__construct('ariva');
    }
}

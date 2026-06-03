<?php

namespace App\Services\WebMarketData\Parsers;

class BoerseDeQuoteParser extends GenericOfficialQuoteParser
{
    public function __construct()
    {
        parent::__construct('boerse_de');
    }
}

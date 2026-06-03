<?php

namespace App\Services\WebMarketData\Parsers;

class WienerBoerseParser extends GenericOfficialQuoteParser
{
    public function __construct()
    {
        parent::__construct('wiener_boerse');
    }
}

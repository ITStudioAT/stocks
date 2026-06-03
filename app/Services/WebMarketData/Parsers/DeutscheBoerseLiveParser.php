<?php

namespace App\Services\WebMarketData\Parsers;

class DeutscheBoerseLiveParser extends GenericOfficialQuoteParser
{
    public function __construct()
    {
        parent::__construct('deutsche_boerse_live');
    }
}

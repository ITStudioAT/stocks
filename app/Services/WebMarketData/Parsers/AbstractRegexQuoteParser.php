<?php

namespace App\Services\WebMarketData\Parsers;

use App\Services\WebMarketData\Parsers\Concerns\ParsesMarketData;

abstract class AbstractRegexQuoteParser implements WebQuoteParser
{
    use ParsesMarketData;
}

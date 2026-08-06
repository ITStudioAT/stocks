<?php

namespace App;

enum MarketDataType: string
{
    case LiveData = 'live-data';
    case IntradayData = 'intraday-data';
    case EndOfDayData = 'eod-data';
}

<?php

namespace App\Services\WebMarketData\DTO;

use App\Models\StockHolding;
use Illuminate\Support\Str;

class InstrumentIdentity
{
    public function __construct(
        public string $symbol,
        public ?string $name,
        public ?string $isin,
        public ?string $wkn,
        public ?string $exchange,
        public ?string $mic,
        public ?string $instrumentType,
        public ?string $country,
        public ?string $currency,
        public ?string $preferredVenue,
        public ?string $preferredMic,
        public ?string $preferredSourceKey,
        public ?string $lastPrice,
    ) {}

    public static function fromHolding(StockHolding $holding): self
    {
        $latestStockPrice = $holding->latestStockPrice;

        return new self(
            symbol: (string) ($holding->symbol ?? ''),
            name: $holding->name,
            isin: $holding->isin ? Str::upper($holding->isin) : null,
            wkn: $holding->wkn ? Str::upper($holding->wkn) : null,
            exchange: $holding->exchange,
            mic: $holding->mic_code ? Str::upper($holding->mic_code) : null,
            instrumentType: $holding->instrument_type,
            country: $holding->country,
            currency: $latestStockPrice?->currency ?? $holding->currency,
            preferredVenue: $holding->preferred_venue,
            preferredMic: $holding->preferred_mic ? Str::upper($holding->preferred_mic) : null,
            preferredSourceKey: $holding->preferred_source_key,
            lastPrice: $latestStockPrice?->price ?? $holding->latest_price,
        );
    }

    public function isEtfLike(): bool
    {
        $type = Str::upper((string) $this->instrumentType);
        $name = Str::upper((string) $this->name);

        return Str::contains($type, ['ETF', 'ETP', 'ETC', 'FUND'])
            || Str::contains($name, ['ETF', 'ETP', 'ETC', 'UCITS']);
    }
}

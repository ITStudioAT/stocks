<?php

namespace App\Services\WebMarketData;

use App\Models\StockHolding;
use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use Illuminate\Support\Str;

class WebSourceRegistry
{
    /**
     * @return array<int, WebSourceCandidate>
     */
    public function candidatesFor(StockHolding $holding): array
    {
        $instrument = InstrumentIdentity::fromHolding($holding);
        $sources = collect();

        foreach ($holding->sourceCandidates()->where('active', true)->orderByDesc('verified')->orderByDesc('confidence_score')->get() as $candidate) {
            $sources->push($this->candidate(
                sourceKey: $candidate->source_key,
                sourceName: $this->sourceName($candidate->source_key),
                url: $candidate->source_url,
                parserKey: $candidate->parser_key,
                venue: $candidate->venue,
                mic: $candidate->mic,
                confidenceScore: $candidate->confidence_score,
                verified: $candidate->verified,
            ));
        }

        if ($holding->latest_price_source_url && $this->supportedPreviousUrl($holding->latest_price_source_url)) {
            $sourceKey = $this->sourceKeyFromUrl($holding->latest_price_source_url);
            $sources->push($this->candidate(
                sourceKey: $sourceKey,
                sourceName: $this->sourceName($sourceKey),
                url: $holding->latest_price_source_url,
                parserKey: $sourceKey,
                venue: $holding->preferred_venue,
                mic: $holding->preferred_mic,
                confidenceScore: 80,
                verified: true,
            ));
        }

        if ($instrument->isin !== null) {
            $sources = $sources->merge($this->deterministicCandidates($instrument));
        }

        return $sources
            ->filter(fn (WebSourceCandidate $candidate): bool => $this->enabled($candidate->sourceKey))
            ->unique(fn (WebSourceCandidate $candidate): string => "{$candidate->parserKey}:{$candidate->url}")
            ->sortBy(fn (WebSourceCandidate $candidate): int => $candidate->priority)
            ->take((int) config('market-data.max_sources_per_holding', 6))
            ->values()
            ->all();
    }

    /**
     * @return array<int, WebSourceCandidate>
     */
    private function deterministicCandidates(InstrumentIdentity $instrument): array
    {
        $isin = rawurlencode((string) $instrument->isin);

        return [
            $this->candidate('tradegate', 'Tradegate Exchange', $this->template('tradegate', 'quote', $isin), 'tradegate', 'Tradegate'),
            $this->candidate('justetf', 'justETF Austria', $this->template('justetf', 'at', $isin), 'justetf', 'justETF'),
            $this->candidate('justetf', 'justETF Germany', $this->template('justetf', 'de', $isin), 'justetf', 'justETF'),
            $this->candidate('justetf', 'justETF International', $this->template('justetf', 'en', $isin), 'justetf', 'justETF'),
            $this->candidate('quotrix', 'Quotrix', str_replace('{ISIN}', $isin, (string) config('market-data.sources.quotrix.search_url')), 'quotrix', 'Quotrix'),
        ];
    }

    private function candidate(
        string $sourceKey,
        string $sourceName,
        string $url,
        string $parserKey,
        ?string $venue = null,
        ?string $mic = null,
        int $confidenceScore = 0,
        bool $verified = false,
    ): WebSourceCandidate {
        return new WebSourceCandidate(
            sourceKey: $sourceKey,
            sourceName: $sourceName,
            url: $url,
            parserKey: $parserKey,
            quality: (string) config("market-data.sources.{$sourceKey}.quality", 'legacy'),
            priority: (int) config("market-data.sources.{$sourceKey}.priority", 999),
            venue: $venue,
            mic: $mic,
            confidenceScore: $confidenceScore,
            verified: $verified,
        );
    }

    private function template(string $sourceKey, string $templateKey, string $isin): string
    {
        $template = (string) config("market-data.sources.{$sourceKey}.url_templates.{$templateKey}");

        return str_replace('{ISIN}', $isin, $template);
    }

    private function enabled(string $sourceKey): bool
    {
        return (bool) config("market-data.sources.{$sourceKey}.enabled", false);
    }

    private function supportedPreviousUrl(string $url): bool
    {
        return $this->sourceKeyFromUrl($url) !== 'legacy';
    }

    private function sourceKeyFromUrl(string $url): string
    {
        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = Str::lower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return match (true) {
            str_contains($host, 'tradegate') => 'tradegate',
            str_contains($host, 'onvista') => 'onvista_markets',
            str_contains($host, 'finanzen') => 'finanzen_markets',
            str_contains($host, 'quotrix') => 'quotrix',
            str_contains($host, 'boerse-stuttgart') => 'boerse_stuttgart',
            str_contains($host, 'justetf') => 'justetf',
            str_contains($host, 'deutsche-boerse') || str_contains($host, 'boerse-frankfurt') || str_contains($path, 'xetra') => 'deutsche_boerse_live',
            str_contains($host, 'wienerborse') => 'wiener_boerse',
            str_contains($host, 'euronext') => 'euronext_live',
            str_contains($host, 'ariva') => 'ariva',
            str_contains($host, 'boerse.de') => 'boerse_de',
            default => 'legacy',
        };
    }

    private function sourceName(string $sourceKey): string
    {
        return match ($sourceKey) {
            'tradegate' => 'Tradegate Exchange',
            'onvista_markets' => 'onvista Markets',
            'finanzen_markets' => 'finanzen Markets',
            'quotrix' => 'Quotrix',
            'boerse_stuttgart' => 'Boerse Stuttgart',
            'justetf' => 'justETF',
            'deutsche_boerse_live' => 'Deutsche Boerse Live',
            'wiener_boerse' => 'Wiener Boerse',
            'euronext_live' => 'Euronext Live',
            'ariva' => 'ARIVA',
            'boerse_de' => 'boerse.de',
            default => 'Legacy source',
        };
    }
}

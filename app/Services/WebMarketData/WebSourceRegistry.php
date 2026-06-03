<?php

namespace App\Services\WebMarketData;

use App\Models\StockHolding;
use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebSourceRegistry
{
    /**
     * @return array<int, WebSourceCandidate>
     */
    public function candidatesFor(StockHolding $holding): array
    {
        return $this->preparedCandidates(
            $this->sourceCollectionFor($holding),
            (int) config('market-data.max_sources_per_holding', 6),
        );
    }

    /**
     * @return array<int, WebSourceCandidate>
     */
    public function extendedCandidatesFor(StockHolding $holding): array
    {
        $instrument = InstrumentIdentity::fromHolding($holding);
        $sources = $this->sourceCollectionFor($holding);

        if ($instrument->isin !== null) {
            $sources = $sources->merge($this->intensiveCandidates($instrument));
        }

        return $this->preparedCandidates(
            $sources,
            (int) config('market-data.max_extended_sources_per_holding', 14),
        );
    }

    /**
     * @return Collection<int, WebSourceCandidate>
     */
    private function sourceCollectionFor(StockHolding $holding): Collection
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

        return $sources;
    }

    /**
     * @param  Collection<int, WebSourceCandidate>  $sources
     * @return array<int, WebSourceCandidate>
     */
    private function preparedCandidates(Collection $sources, int $limit): array
    {
        return $sources
            ->filter(fn (WebSourceCandidate $candidate): bool => $this->enabled($candidate->sourceKey))
            ->unique(fn (WebSourceCandidate $candidate): string => "{$candidate->parserKey}:{$candidate->url}")
            ->sortBy(fn (WebSourceCandidate $candidate): int => $candidate->priority)
            ->take($limit)
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
            $this->candidate('onvista_markets', 'onvista Markets', "https://www.onvista.de/etf/{$isin}", 'onvista_markets', 'onvista'),
            $this->candidate('justetf', 'justETF Austria', $this->template('justetf', 'at', $isin), 'justetf', 'justETF'),
            $this->candidate('justetf', 'justETF Germany', $this->template('justetf', 'de', $isin), 'justetf', 'justETF'),
            $this->candidate('justetf', 'justETF International', $this->template('justetf', 'en', $isin), 'justetf', 'justETF'),
            $this->candidate('quotrix', 'Quotrix', str_replace('{ISIN}', $isin, (string) config('market-data.sources.quotrix.search_url')), 'quotrix', 'Quotrix'),
        ];
    }

    /**
     * @return array<int, WebSourceCandidate>
     */
    private function intensiveCandidates(InstrumentIdentity $instrument): array
    {
        $isin = rawurlencode((string) $instrument->isin);
        $candidates = [
            ...$this->finanzenEtfCandidates($instrument),
            $this->candidate('finanzen_markets', 'finanzen Markets Germany', "https://www.finanzen.net/suchergebnis.asp?_search={$isin}", 'finanzen_markets', 'finanzen.net'),
            $this->candidate('finanzen_markets', 'finanzen Markets Austria', "https://www.finanzen.at/suchergebnis.asp?_search={$isin}", 'finanzen_markets', 'finanzen.at'),
            $this->candidate('bx_swiss', 'BX Swiss', "https://www.bxswiss.com/instruments/{$isin}", 'bx_swiss', 'BX Swiss', 'XBRN'),
            $this->candidate('boerse_stuttgart', 'Boerse Stuttgart', "https://www.boerse-stuttgart.de/de-de/tools/suche/?query={$isin}", 'boerse_stuttgart', 'Boerse Stuttgart', 'XSTU'),
            $this->candidate('ariva', 'ARIVA', "https://www.ariva.de/search/search.m?searchname={$isin}", 'ariva', 'ARIVA'),
            $this->candidate('boerse_de', 'boerse.de', "https://www.boerse.de/suche/?suchbegriff={$isin}", 'boerse_de', 'boerse.de'),
        ];

        if ($instrument->mic === 'XETR' || Str::contains(Str::upper((string) $instrument->exchange), ['XETRA', 'XETR'])) {
            $candidates[] = $this->candidate('deutsche_boerse_live', 'Boerse Frankfurt', "https://www.boerse-frankfurt.de/suchergebnisse/{$isin}", 'deutsche_boerse_live', 'Xetra', 'XETR');
        }

        return $candidates;
    }

    /**
     * @return array<int, WebSourceCandidate>
     */
    private function finanzenEtfCandidates(InstrumentIdentity $instrument): array
    {
        if (! $instrument->isEtfLike() || $instrument->isin === null || $instrument->name === null) {
            return [];
        }

        $isin = Str::lower($instrument->isin);

        return collect([
            $this->finanzenEtfSlug($instrument->name, removeClassTerms: true),
            $this->finanzenEtfSlug($instrument->name, removeClassTerms: false),
        ])
            ->filter()
            ->unique()
            ->map(fn (string $slug): WebSourceCandidate => $this->candidate(
                'finanzen_markets',
                'finanzen Markets Austria',
                "https://www.finanzen.at/etf/{$slug}-{$isin}",
                'finanzen_markets',
                'finanzen.at',
            ))
            ->values()
            ->all();
    }

    private function finanzenEtfSlug(string $name, bool $removeClassTerms): ?string
    {
        $normalizedName = Str::ascii(Str::lower($name));

        if ($removeClassTerms) {
            $normalizedName = preg_replace('/\([^)]*\)/', ' ', $normalizedName) ?? $normalizedName;
            $normalizedName = preg_replace('/\b(?:ucits|dist|dis|acc|ausschuttend|thesaurierend)\b/i', ' ', $normalizedName) ?? $normalizedName;
        }

        $slug = Str::slug($normalizedName);

        return $slug === '' ? null : $slug;
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
            str_contains($host, 'bxswiss') => 'bx_swiss',
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
            'bx_swiss' => 'BX Swiss',
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

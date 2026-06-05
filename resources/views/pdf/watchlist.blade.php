@php
    /** @var array<int, array<string, mixed>> $holdings */
    /** @var array<string, mixed>|null $depot */
    /** @var \Illuminate\Support\Carbon $generatedAt */

    $formatPrice = static function (?string $value, ?string $currency = null): string {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '–';
        }

        $formatted = number_format((float) $value, 6, '.', ',');

        if (str_contains($formatted, '.')) {
            [$integer, $decimals] = explode('.', $formatted);
            $decimals = rtrim($decimals, '0');
            $decimals = str_pad($decimals, 2, '0');
            $formatted = $integer.'.'.$decimals;
        }

        if ($currency !== null && $currency !== '' && strtoupper($currency) !== 'EUR') {
            return $formatted.' '.strtoupper($currency);
        }

        return $formatted;
    };

    $formatDateTime = static function (?string $value, bool $withDate = true): string {
        if ($value === null || trim((string) $value) === '') {
            return '–';
        }

        try {
            $parsed = \Illuminate\Support\Carbon::parse($value)->setTimezone('Europe/Vienna');

            return $parsed->format($withDate ? 'd.m.Y H:i' : 'H:i');
        } catch (\Throwable) {
            return $value;
        }
    };

    $statusLabels = [
        'realtime' => 'Real-time',
        'fresh' => 'Fresh',
        'delayed' => 'Delayed',
        'closed_market' => 'Market closed',
        'suspicious' => 'Suspicious',
        'unavailable_now' => 'Unavailable now',
        'unavailable' => 'Unavailable',
        'stale' => 'Stale',
        'missing' => 'Missing',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Watch-list Report</title>
    <style>
        @page {
            margin: 34px;
        }

        * {
            font-family: "DejaVu Sans", sans-serif;
        }

        body {
            margin: 0;
            color: #1f2933;
            font-size: 10px;
            line-height: 1.4;
        }

        header {
            margin-bottom: 12px;
        }

        .masthead {
            background: #0f172a;
            color: #ffffff;
            padding: 16px 22px;
            border-radius: 6px;
        }

        .masthead .eyebrow {
            text-transform: uppercase;
            letter-spacing: 3px;
            font-size: 8px;
            color: #94a3b8;
            margin: 0 0 2px;
        }

        .masthead h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .masthead .meta {
            margin-top: 4px;
            font-size: 9px;
            color: #cbd5f5;
        }

        footer {
            margin-top: 18px;
            color: #94a3b8;
            font-size: 8px;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
        }

        footer .left {
            float: left;
        }

        .summary {
            margin: 0 0 14px;
            padding: 10px 14px;
            background: #f1f5f9;
            border-left: 4px solid #0f766e;
            border-radius: 4px;
            font-size: 9px;
            color: #334155;
        }

        .summary strong {
            color: #0f172a;
        }

        .card {
            border: 1px solid #e2e8f0;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }

        .instrument-row {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 9px 12px;
        }

        .instrument-main {
            float: left;
            width: 70%;
        }

        .latest-price-cell {
            float: right;
            width: 28%;
            text-align: right;
        }

        .clear {
            clear: both;
        }

        .symbol {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }

        .name {
            font-size: 10px;
            color: #475569;
        }

        .ids {
            font-size: 8px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .price-value {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }

        .badge {
            display: inline-block;
            margin-top: 3px;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: 700;
        }

        .badge-up {
            background: #dcfce7;
            color: #166534;
        }

        .badge-down {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-flat {
            background: #e2e8f0;
            color: #475569;
        }

        .status-pill {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: 700;
            background: #e0e7ff;
            color: #3730a3;
        }

        .fact-cell {
            display: inline-block;
            padding: 5px 10px;
            border-top: 1px solid #f1f5f9;
            width: 22.8%;
        }

        .fact-wide {
            display: inline-block;
            padding: 5px 10px;
            border-top: 1px solid #f1f5f9;
            width: 47.8%;
        }

        .label {
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 7px;
            color: #94a3b8;
            margin-bottom: 1px;
        }

        .value {
            font-size: 9px;
            color: #1f2933;
        }

        .prices {
            border-top: 1px solid #e2e8f0;
            background: #fbfcfe;
            padding: 8px 10px 10px;
        }

        .section-title {
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 7px;
            color: #0f766e;
            font-weight: 700;
        }

        table.price-history {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        table.price-history th {
            text-align: left;
            font-size: 7px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            border-bottom: 1px solid #e2e8f0;
            padding: 3px 6px;
        }

        table.price-history td {
            font-size: 8.5px;
            padding: 3px 6px;
            border-bottom: 1px solid #f1f5f9;
        }

        table.price-history .num {
            text-align: right;
        }

        table.price-history td.num {
            font-weight: 700;
            color: #0f172a;
        }

        .empty-prices {
            font-size: 9px;
            color: #94a3b8;
            font-style: italic;
            background: #fbfcfe;
        }

        .no-holdings {
            padding: 30px;
            text-align: center;
            color: #94a3b8;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <header>
        <div class="masthead">
            <p class="eyebrow">Stocks · Watch-list Report</p>
            <h1>Watch-list</h1>
            <div class="meta">
                @if ($depot)
                    Depot: {{ $depot['name'] }} &nbsp;·&nbsp;
                @endif
                Generated {{ $generatedAt->copy()->setTimezone('Europe/Vienna')->format('d.m.Y H:i') }} (Europe/Vienna)
            </div>
        </div>
    </header>

    <main>
        <div class="summary">
            <strong>{{ count($holdings) }}</strong> {{ \Illuminate\Support\Str::plural('instrument', count($holdings)) }} on the watch-list. Prices listed below cover the last 24 hours.
        </div>

        @forelse ($holdings as $holding)
            @php
                $trend = $holding['latest_price_trend'] ?? null;
                $changePct = $holding['latest_price_change_pct'] ?? null;
                $badgeClass = match ($trend) {
                    'up' => 'badge-up',
                    'down' => 'badge-down',
                    'flat' => 'badge-flat',
                    default => null,
                };
                $changeLabel = null;
                if ($changePct !== null && is_numeric($changePct)) {
                    $sign = (float) $changePct > 0 ? '+' : '';
                    $changeLabel = $sign.number_format((float) $changePct, 2).'%';
                }
                $statusKey = $holding['latest_price_status'] ?? null;
                $statusLabel = $statusLabels[$statusKey] ?? ($statusKey ? \Illuminate\Support\Str::headline($statusKey) : null);
                $recentPrices = $holding['recent_prices'] ?? [];
            @endphp
            <div class="card">
                <div class="instrument-row">
                    <div class="instrument-main">
                        <span class="symbol">{{ $holding['symbol'] ?: '–' }}</span>
                        @if ($statusLabel)
                            &nbsp;<span class="status-pill">{{ $statusLabel }}</span>
                        @endif
                        <div class="name">{{ $holding['name'] ?: '—' }}</div>
                        <div class="ids">
                            ISIN: {{ $holding['isin'] ?: '–' }} &nbsp;·&nbsp;
                            WKN: {{ $holding['wkn'] ?: '–' }}
                        </div>
                    </div>
                    <div class="latest-price-cell">
                        <div class="price-value">{{ $formatPrice($holding['latest_price'] ?? null, $holding['currency'] ?? null) }}</div>
                        @if ($badgeClass && $changeLabel)
                            <span class="badge {{ $badgeClass }}">{{ $changeLabel }}</span>
                        @endif
                    </div>
                    <div class="clear"></div>
                </div>

                <div class="fact-cell"><span class="label">Exchange</span><span class="value">{{ $holding['exchange'] ?: '–' }}</span></div>
                <div class="fact-cell"><span class="label">Venue</span><span class="value">{{ $holding['venue'] ?: '–' }}</span></div>
                <div class="fact-cell"><span class="label">MIC</span><span class="value">{{ $holding['mic_code'] ?: '–' }}</span></div>
                <div class="fact-cell"><span class="label">Currency</span><span class="value">{{ $holding['currency'] ?: '–' }}</span></div>
                <div class="fact-cell"><span class="label">Instrument type</span><span class="value">{{ $holding['instrument_type'] ?: '–' }}</span></div>
                <div class="fact-cell"><span class="label">Country</span><span class="value">{{ $holding['country'] ?: '–' }}</span></div>
                <div class="fact-cell"><span class="label">Price type</span><span class="value">{{ $holding['price_type'] ?: '–' }}</span></div>
                <div class="fact-cell"><span class="label">Spread %</span><span class="value">{{ $holding['price_spread_pct'] !== null ? number_format((float) $holding['price_spread_pct'], 4).'%' : '–' }}</span></div>
                <div class="fact-cell"><span class="label">Start price</span><span class="value">{{ $formatPrice($holding['start_price'] ?? null, $holding['currency'] ?? null) }}</span></div>
                <div class="fact-cell"><span class="label">End price</span><span class="value">{{ $formatPrice($holding['end_price'] ?? null, $holding['currency'] ?? null) }}</span></div>
                <div class="fact-cell"><span class="label">End 24</span><span class="value">{{ $formatPrice($holding['end_price_24'] ?? null, $holding['currency'] ?? null) }}</span></div>
                <div class="fact-cell"><span class="label">End 48</span><span class="value">{{ $formatPrice($holding['end_price_48'] ?? null, $holding['currency'] ?? null) }}</span></div>
                <div class="fact-cell"><span class="label">Source time</span><span class="value">{{ $formatDateTime($holding['latest_price_as_of'] ?? null) }}</span></div>
                <div class="fact-cell"><span class="label">Fetched</span><span class="value">{{ $formatDateTime($holding['latest_price_fetched_at'] ?? null) }}</span></div>
                <div class="fact-wide"><span class="label">Source</span><span class="value">{{ $holding['latest_price_source'] ?: '–' }}</span></div>
                <div class="fact-wide"><span class="label">Trading times</span><span class="value">{{ $holding['trading_times'] ?: '–' }}</span></div>

                <div class="prices">
                    <span class="section-title">Prices · last 24 hours ({{ count($recentPrices) }})</span>
                    @if (count($recentPrices) > 0)
                        <table class="price-history">
                            <thead>
                                <tr>
                                    <th style="width: 30%;">Time (Europe/Vienna)</th>
                                    <th style="width: 30%;">Source</th>
                                    <th style="width: 20%;">Type</th>
                                    <th class="num" style="width: 20%;">Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentPrices as $recentPrice)
                                    <tr>
                                        <td>{{ $formatDateTime($recentPrice['as_of'] ?? null) }}</td>
                                        <td>{{ $recentPrice['source_name'] ?: '–' }}</td>
                                        <td>{{ $recentPrice['price_type'] ?: '–' }}</td>
                                        <td class="num">{{ $formatPrice($recentPrice['price'] ?? null, $recentPrice['currency'] ?? ($holding['currency'] ?? null)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="empty-prices">No stored prices in the last 24 hours.</div>
                    @endif
                </div>
            </div>
        @empty
            <div class="no-holdings">No stocks in the watch-list.</div>
        @endforelse
    </main>

    <footer>
        <span class="left">Watch-list Report · {{ $generatedAt->copy()->setTimezone('Europe/Vienna')->format('d.m.Y H:i') }}</span>
    </footer>
</body>
</html>

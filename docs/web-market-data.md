# Web Market Data

The stock price refresh flow uses public web pages only. It does not use paid APIs, broker sessions, credentials, AI, or web search as a price source.

## Refresh Flow

1. `resources/js/App.vue` sends `POST /admin/active-depot/holdings/refresh-prices` from the `Refresh prices` button.
2. `App\Http\Controllers\AdminDepotHoldingController::refreshPrices()` creates a `stock_price_refresh_runs` row and dispatches `App\Jobs\RefreshDepotHoldingPrices`.
3. `RefreshDepotHoldingPrices::handle()` loads all holdings for the active depot and calls `App\Services\WebMarketData\WebMarketDataOrchestrator::resolve()` for each holding.
4. `WebSourceRegistry` builds source candidates from verified saved candidates, the previous verified source URL, and deterministic ISIN templates.
5. `WebQuoteFetcher` fetches public HTML/JSON with short timeouts, retries, and a short cache.
6. Source-specific parsers extract quotes only when instrument identity, EUR currency, price, and quote timestamp are visible.
7. `WebQuoteValidator` rejects invalid quotes and marks wide spreads, NAV-only values, large price jumps, or cross-check divergence as suspicious.
8. `WebQuoteSelector` ranks valid quotes by source quality, freshness, preferred venue/source, price type, spread, and source priority.
9. Selected and suspicious quotes are stored in `stock_price_quotes`; the holding receives the selected quote in legacy display fields and `latest_quote_id`.
10. If no valid quote is available, the existing holding price is preserved and `stock_holdings.price_status` becomes `stale` or `unavailable_now`.

## Public Sources

- Tradegate: `config/market-data.php` source key `tradegate`, parsed by `TradegateQuoteParser`.
- onvista Markets: source key `onvista_markets`, parsed by `OnvistaMarketsParser`.
- finanzen market pages: source key `finanzen_markets`, parsed by `FinanzenMarketsParser`.
- Quotrix: source key `quotrix`, parsed by `QuotrixQuoteParser`.
- Boerse Stuttgart: source key `boerse_stuttgart`, parsed by `BoerseStuttgartQuoteParser`.
- justETF: source key `justetf`, parsed by `JustEtfQuoteParser`; NAV values are stored but not treated as live exchange prices.
- Deutsche Boerse, Wiener Boerse, Euronext, ARIVA, and boerse.de are supported as conservative official/generic parser candidates when a verified URL exists.

## Stored Data

- `stock_price_quotes`: quote history with source, venue, identifiers, bid/ask/last/close/nav, selected price, timestamp, freshness, validation status, validation errors, and optional raw payload.
- `stock_holding_source_candidates`: per-holding candidate URLs with parser key, source key, venue, confidence, success/failure counters, and verification flags.
- `stock_price_refresh_runs`: one row per refresh action with totals and status.
- `stock_price_refresh_items`: per-holding refresh status, attempted sources, selected quote, and error message.
- `stock_holdings`: current display state via `latest_quote_id`, `price_status`, `latest_price_type`, `price_spread_pct`, `source_verified_at`, and existing `latest_price*` fields.

## Validation Rules

- Currency must be EUR when `market-data.strict_currency` is enabled.
- ISIN and WKN must match when the source exposes them.
- Bid must not be greater than ask.
- A quote timestamp is required and must not be in the future.
- NAV is stored as `price_type = nav` and marked suspicious/closed-market.
- Wide spreads and large price jumps are marked suspicious and require cross-checking.
- Stale, missing, or unverifiable quotes are not selected.

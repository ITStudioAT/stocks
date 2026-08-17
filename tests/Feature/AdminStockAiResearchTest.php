<?php

namespace Tests\Feature;

use App\Ai\Agents\StockResearchAgent;
use App\Jobs\AnalyzeStockResearch;
use App\Models\StockAiResearch;
use App\Models\StockAiResearchSource;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\StockAiResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use ReflectionClass;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use UnexpectedValueException;

class AdminStockAiResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.eodhd.key' => null]);
    }

    public function test_admin_can_queue_reuse_restore_and_privately_read_stock_research(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $otherAdmin = $this->adminUser();
        $holding = StockHolding::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson("/admin/dashboard/ai/stocks/{$holding->id}/researches");

        $response
            ->assertAccepted()
            ->assertJsonPath('research.stock_holding_id', $holding->id)
            ->assertJsonPath('research.status', 'queued');
        $researchId = $response->json('research.id');

        Queue::assertPushed(
            AnalyzeStockResearch::class,
            fn (AnalyzeStockResearch $job): bool => $job->researchId === $researchId
                && $job->userId === $admin->id
                && $job->stockHoldingId === $holding->id,
        );

        $this->actingAs($admin)
            ->postJson("/admin/dashboard/ai/stocks/{$holding->id}/researches")
            ->assertAccepted()
            ->assertJsonPath('research.id', $researchId);

        Queue::assertPushed(AnalyzeStockResearch::class, 1);

        $this->actingAs($admin)
            ->getJson('/admin/dashboard/ai/stock-researches')
            ->assertOk()
            ->assertJsonCount(1, 'researches')
            ->assertJsonPath('researches.0.id', $researchId);

        $this->actingAs($otherAdmin)
            ->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$researchId}")
            ->assertNotFound();
    }

    public function test_admin_can_queue_research_for_all_stocks_with_one_request(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holdings = StockHolding::factory()->count(3)->create();
        $runningResearch = StockAiResearch::factory()->create([
            'user_id' => $admin->id,
            'stock_holding_id' => $holdings[1]->id,
            'status' => 'running',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/dashboard/ai/stock-researches')
            ->assertAccepted()
            ->assertJsonPath('count', 3)
            ->assertJsonCount(3, 'researches')
            ->assertJsonPath('researches.1.id', $runningResearch->id)
            ->assertJsonPath('researches.1.status', 'running');

        Queue::assertPushed(AnalyzeStockResearch::class, 2);
        $this->assertDatabaseCount('stock_ai_researches', 3);
    }

    public function test_batch_research_jobs_can_wait_for_the_queue_rate_limit(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00');

        $job = new AnalyzeStockResearch('research-id', 1, 2);

        $this->assertSame(0, $job->tries);
        $this->assertSame(
            now()->addHours(6)->getTimestamp(),
            $job->retryUntil()->getTimestamp(),
        );
    }

    public function test_admin_can_load_all_when_there_are_no_stocks(): void
    {
        Queue::fake();

        $this->actingAs($this->adminUser())
            ->postJson('/admin/dashboard/ai/stock-researches')
            ->assertAccepted()
            ->assertJsonPath('count', 0)
            ->assertJsonCount(0, 'researches');

        Queue::assertNothingPushed();
    }

    public function test_stock_research_agent_requires_security_specific_evidence(): void
    {
        $instructions = (string) (new StockResearchAgent)->instructions();

        $this->assertStringContainsString('current five largest positions', $instructions);
        $this->assertStringContainsString('results released in the last 45 days', $instructions);
        $this->assertStringContainsString('earnings scheduled in the next 30 days', $instructions);
        $this->assertStringContainsString('unconfirmed reports from the last 14 days', $instructions);
        $this->assertMatchesRegularExpression('/unusual activit(?:y|ies)/i', $instructions);
        $this->assertStringContainsString('Never write generic bullish or bearish scenarios', $instructions);
        $this->assertStringContainsString('rising sector investment', $instructions);
        $this->assertStringContainsString('general demand growth', $instructions);
        $this->assertStringContainsString('regulatory uncertainty', $instructions);
        $this->assertStringContainsString('An empty developments array is a valid and preferable result', $instructions);
        $this->assertStringContainsString('eodhd_current_evidence', $instructions);
        $this->assertStringContainsString('Analyze the present', $instructions);
        $this->assertStringContainsString('Do not use long price history', $instructions);
        $this->assertStringContainsString('published_at and data_as_of separately', $instructions);
        $this->assertStringContainsString('adds retrieved_at, freshness, and coverage', $instructions);
        $this->assertStringContainsString('server_calculated_current_events', $instructions);
        $this->assertStringContainsString('Never recalculate, replace, reinterpret, or contradict', $instructions);
        $this->assertStringContainsString('Never turn a calculated comparison into a forecast', $instructions);
        $this->assertStringContainsString('guidance_coverage is unavailable', $instructions);
        $this->assertStringContainsString('return the raw values in guidance_inputs', $instructions);
        $this->assertStringContainsString('Do not classify the guidance yourself', $instructions);
        $this->assertStringContainsString('structured provider evidence and server-calculated events', $instructions);
        $this->assertStringContainsString('issuer investor-relations pages and official fund factsheets', $instructions);
        $this->assertStringContainsString('Open-web search is a fallback', $instructions);
        $this->assertStringContainsString('Never return Buy, Hold, Sell', $instructions);
        $this->assertStringContainsString('Return every consulted source in consulted_sources', $instructions);

        $agent = new StockResearchAgent;
        $webSearch = collect($agent->tools())->first();

        $this->assertInstanceOf(WebSearch::class, $webSearch);
        $this->assertSame([], $webSearch->allowedDomains);
        $this->assertSame(
            ['include' => ['web_search_call.action.sources']],
            $agent->providerOptions(Lab::OpenAI),
        );
    }

    public function test_research_prompt_includes_current_eodhd_evidence_and_partial_coverage(): void
    {
        Queue::fake();
        config(['services.eodhd.key' => 'eodhd-test-token']);
        Http::preventStrayRequests();
        Http::fake([
            'eodhd.com/api/id-mapping*' => Http::response(['data' => [
                ['symbol' => 'AMEE.XETRA', 'isin' => 'FR0010930644'],
            ]]),
            'eodhd.com/api/v1.1/fundamentals/AMEE.XETRA*' => Http::response(['message' => 'Forbidden'], 403),
            'eodhd.com/api/news*' => Http::response([[
                'date' => '2026-08-16T08:00:00+00:00',
                'title' => 'Amundi publishes a current ETF notice',
                'content' => 'A current security-specific notice.',
                'link' => 'https://example.com/current-etf-notice',
                'symbols' => ['AMEE.XETRA'],
                'tags' => ['company announcement'],
            ]]),
            'eodhd.com/api/real-time/AMEE.XETRA*' => Http::response([
                'code' => 'AMEE.XETRA',
                'timestamp' => 1786867200,
                'close' => 121.4,
                'previousClose' => 120.8,
                'change_p' => 0.5,
                'volume' => 2200,
            ]),
            'eodhd.com/api/eod/AMEE.XETRA*' => Http::response([[
                'date' => '2026-08-15',
                'close' => 120.8,
                'volume' => 2100,
            ]]),
            'eodhd.com/api/sentiments*' => Http::response(['AMEE.XETRA' => [
                ['date' => '2026-08-15', 'count' => 2, 'normalized' => 0.2],
            ]]),
        ]);
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'name' => 'Amundi Global Hydrogen UCITS ETF Acc',
            'symbol' => 'AMEE',
            'isin' => 'FR0010930644',
            'exchange' => 'XETRA',
            'instrument_type' => 'ETF',
        ]);
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        StockResearchAgent::fake([[
            'summary' => 'Keine konkrete Entwicklung gefunden.',
            'developments' => [],
            'consulted_sources' => [],
            'guidance_inputs' => [],
            'trump_connection' => '',
        ]])->preventStrayPrompts();

        app(StockAiResearchService::class)->run($research->id);

        $research->refresh();
        $this->assertSame(1, $research->calculation_snapshot['version']);
        $this->assertSame('comparison_only', $research->calculated_events['history_usage']);
        $this->assertSame(0.4967, $research->calculated_events['market_reactions'][0]['reaction_pct']);
        $this->assertSame('latest_available_session', $research->calculated_events['market_reactions'][0]['session_label']);

        $this->actingAs($admin)
            ->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$research->id}")
            ->assertOk()
            ->assertJsonPath('research.calculated_events.market_reactions.0.reaction_pct', 0.4967)
            ->assertJsonPath('research.calculated_events.history_usage', 'comparison_only');

        StockResearchAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"eodhd_current_evidence"')
                && str_contains($prompt->prompt, '"status": "partial"')
                && str_contains($prompt->prompt, 'Amundi publishes a current ETF notice')
                && str_contains($prompt->prompt, '"recent_market_context"')
                && str_contains($prompt->prompt, '"server_calculated_current_events"')
                && str_contains($prompt->prompt, '"reaction_pct": 0.4967')
                && str_contains($prompt->prompt, '"unsupported_subscription"')
                && str_contains($prompt->prompt, '"preferred_web_domains"')
                && str_contains($prompt->prompt, '"amundietf.com"')
                && ! str_contains($prompt->prompt, '"allowed_web_domains"')
                && ! str_contains($prompt->prompt, 'eodhd-test-token'),
        );
    }

    public function test_research_service_stores_and_exposes_normalized_developments_and_sources(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-16 10:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'name' => 'Apple Inc.',
            'symbol' => 'AAPL',
            'isin' => 'US0378331005',
        ]);
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        $structured = $this->analysisResult([
            $this->development([
                'category' => ' UNUSUAL_ACTIVITY ',
                'subject' => ' Apple Inc. ',
                'headline' => ' Apple-Direktorin verkauft 50.000 Aktien. ',
                'details' => ' Eine Form-4-Meldung dokumentiert den Verkauf von 50.000 Aktien. ',
                'relevance' => ' Das Insidergeschäft betrifft die analysierte Aktie direkt. ',
                'status' => ' CONFIRMED ',
                'source_title' => ' Apple SEC Form 4 ',
                'source_url' => 'https://EXAMPLE.com/apple-form-4?utm_source=test&id=7#section',
                'retrieved_at' => '1999-01-01T00:00:00+00:00',
            ]),
        ]);
        $structured['guidance_inputs'] = [[
            'symbol' => 'AAPL',
            'metric' => 'revenue',
            'period' => 'FY2026',
            'unit' => 'USDm',
            'event_at' => '2026-08-16',
            'published_at' => '2026-08-16T09:55:00+02:00',
            'data_as_of' => '2026-08-16',
            'current' => ['value' => 110, 'low' => null, 'high' => null],
            'previous' => ['value' => 100, 'low' => null, 'high' => null],
            'source_title' => 'Apple official guidance',
            'source_url' => 'https://example.com/apple-guidance?utm_source=search',
        ]];
        $response = new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
            new Usage,
            new Meta('openai', 'test-model', collect([
                new UrlCitation('https://example.com/apple-form-4?id=7&utm_medium=search#citation', 'Duplicate citation'),
                new UrlCitation('javascript:alert(1)', 'Unsafe source'),
            ])),
        );
        StockResearchAgent::fake([$response])->preventStrayPrompts();

        app(StockAiResearchService::class)->run($research->id);

        $research->refresh();
        $normalizedDevelopment = array_replace($this->development(), [
            'proposed_impact' => 'negative',
            'proposed_materiality' => 'medium',
            'current_impact' => 'no_reliable_assessment',
            'materiality' => null,
            'source_confidence' => 'high',
            'affected_etf_share_pct' => 100,
            'assessment_status' => 'no_reliable_assessment',
            'assessment_reason' => 'Keine belastbare Einschätzung, weil die Rechercheabdeckung unvollständig oder eine Datenquelle ausgefallen ist.',
            'assessment_method' => 'structured_event_classification_with_server_coverage_guardrails',
        ]);
        $this->assertSame('finished', $research->status);
        $this->assertTrue($research->has_material_update);
        $this->assertNull($research->recommendation);
        $this->assertSame('no_reliable_assessment', $research->assessment['current_impact']);
        $this->assertSame($structured['summary'], $research->summary);
        $this->assertSame([$normalizedDevelopment], $research->developments);
        $this->assertSame('raised', $research->calculated_events['guidance'][0]['classification']);
        $this->assertSame(110, $research->calculated_events['guidance'][0]['current']['value']);
        $this->assertSame('https://example.com/apple-guidance', $research->calculated_events['guidance'][0]['source_url']);
        $this->assertSame([
            json_encode($normalizedDevelopment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ], $research->known_information);
        $this->assertDatabaseHas('stock_ai_research_sources', [
            'stock_ai_research_id' => $research->id,
            'url' => $normalizedDevelopment['source_url'],
            'title' => $normalizedDevelopment['source_title'],
        ]);
        $this->assertDatabaseCount('stock_ai_research_sources', 1);

        $this->actingAs($admin)
            ->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$research->id}")
            ->assertOk()
            ->assertJsonPath('research.developments.0', $normalizedDevelopment)
            ->assertJsonPath('research.assessment.current_impact', 'no_reliable_assessment')
            ->assertJsonPath('research.now_relevant.blocks.0.title', 'Heute / letzte 72 Stunden')
            ->assertJsonPath('research.calculated_events.guidance.0.classification', 'raised')
            ->assertJsonPath('research.sources.0', [
                'url' => $normalizedDevelopment['source_url'],
                'title' => $normalizedDevelopment['source_title'],
                'source_type' => 'regulator',
                'confidence' => 'high',
                'is_primary' => true,
                'retrieved_at' => '2026-08-16T10:00:00+02:00',
            ]);

        StockResearchAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"symbol": "AAPL"')
                && str_contains($prompt->prompt, '"is_follow_up": false'),
        );
    }

    public function test_stock_research_uses_an_explicit_accessible_model(): void
    {
        $attributes = (new ReflectionClass(StockResearchAgent::class))->getAttributes(Model::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('gpt-4.1', $attributes[0]->newInstance()->value);
    }

    public function test_initial_research_without_developments_reports_no_new_information(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create(['symbol' => 'HGEN']);
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        StockResearchAgent::fake([[
            'summary' => 'Es wurden keine konkreten, belegten Entwicklungen gefunden.',
            'developments' => [],
            'consulted_sources' => [],
            'guidance_inputs' => [],
            'trump_connection' => '',
        ]])->preventStrayPrompts();

        app(StockAiResearchService::class)->run($research->id);

        $research->refresh();
        $this->assertSame('no_new_information', $research->status);
        $this->assertFalse($research->has_material_update);
        $this->assertStringContainsString('Keine konkreten, belegten Entwicklungen', $research->summary);
        $this->assertSame([], $research->developments);
        $this->assertSame([], $research->known_information);
        $this->assertNull($research->recommendation);
        $this->assertNull($research->justification);
        $this->assertNull($research->trump_connection);
        $this->assertDatabaseCount('stock_ai_research_sources', 0);

        $this->actingAs($admin)
            ->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$research->id}")
            ->assertOk()
            ->assertJsonPath('research.previous_result', null);
    }

    public function test_payload_enriches_legacy_developments_with_measurable_freshness_fields(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();
        $legacyDevelopment = $this->development();

        foreach (['event_at', 'published_at', 'retrieved_at', 'data_as_of', 'freshness', 'coverage'] as $key) {
            unset($legacyDevelopment[$key]);
        }

        $legacyDevelopment['event_date'] = '2026-08-15';
        $research = StockAiResearch::factory()->create([
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'developments' => [$legacyDevelopment],
            'finished_at' => Carbon::parse('2026-08-16 10:00:00', 'Europe/Vienna'),
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$research->id}")
            ->assertOk()
            ->assertJsonPath('research.developments.0.event_at', '2026-08-15')
            ->assertJsonPath('research.developments.0.published_at', null)
            ->assertJsonPath('research.developments.0.retrieved_at', '2026-08-16T10:00:00+02:00')
            ->assertJsonPath('research.developments.0.data_as_of', null)
            ->assertJsonPath('research.developments.0.freshness', 'stale')
            ->assertJsonPath('research.developments.0.coverage', 'partial');
    }

    public function test_repeat_research_ignores_a_duplicate_development_and_fresh_citation(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create(['symbol' => 'MSFT']);
        $knownDevelopment = $this->development([
            'category' => 'earnings',
            'subject' => 'Microsoft Corp.',
            'event_at' => '2026-08-10',
            'headline' => 'Microsoft meldet Quartalsumsatz von 76,4 Mrd. USD.',
            'details' => 'Der Quartalsumsatz stieg im Jahresvergleich um 18 Prozent.',
            'relevance' => 'Die Quartalszahlen betreffen die analysierte Aktie direkt.',
            'source_title' => 'Microsoft FY26 Q4 Results',
            'source_url' => 'https://example.com/known',
        ]);
        $serializedKnownDevelopment = json_encode(
            $knownDevelopment,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $previousResearch = StockAiResearch::factory()->create([
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'summary' => 'Old summary that must not be repeated.',
            'developments' => [$knownDevelopment],
            'known_information' => [$serializedKnownDevelopment],
            'finished_at' => now()->subHour(),
        ]);
        StockAiResearchSource::factory()->create([
            'stock_ai_research_id' => $previousResearch->id,
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'url' => 'https://example.com/known',
            'url_hash' => hash('sha256', 'https://example.com/known'),
            'title' => 'Microsoft FY26 Q4 Results',
        ]);
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        $duplicateDevelopment = array_replace($knownDevelopment, [
            'category' => ' EARNINGS ',
            'subject' => ' Microsoft Corp. ',
            'headline' => ' Microsoft meldet Quartalsumsatz von 76,4 Mrd. USD. ',
            'details' => 'Ein neuer Artikel wiederholt dieselben Quartalszahlen.',
            'relevance' => 'Die Information war bereits bekannt.',
            'status' => ' CONFIRMED ',
            'source_title' => 'Wiederholung der bekannten Meldung',
            'source_url' => 'https://EXAMPLE.com/known?utm_source=fresh#repeat',
        ]);
        $structured = $this->analysisResult([$duplicateDevelopment]);
        $response = new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
            new Usage,
            new Meta('openai', 'test-model', collect([
                new UrlCitation('https://example.com/fresh-citation', 'Fresh citation'),
            ])),
        );
        StockResearchAgent::fake([$response])->preventStrayPrompts();

        app(StockAiResearchService::class)->run($research->id);

        $research->refresh();
        $this->assertSame('no_new_information', $research->status);
        $this->assertFalse($research->has_material_update);
        $this->assertStringContainsString('Keine neuen belegten Meldungen', $research->summary);
        $this->assertStringNotContainsString('Old summary', $research->summary);
        $this->assertSame([], $research->developments);
        $this->assertNull($research->stronger_case);
        $this->assertNull($research->recommendation);
        $this->assertSame([$serializedKnownDevelopment], $research->known_information);
        $this->assertDatabaseCount('stock_ai_research_sources', 3);
        $this->assertDatabaseHas('stock_ai_research_sources', [
            'stock_ai_research_id' => $research->id,
            'url' => 'https://example.com/fresh-citation',
        ]);
        $this->assertDatabaseHas('stock_ai_research_sources', [
            'stock_ai_research_id' => $research->id,
            'url' => 'https://example.com/known',
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$research->id}")
            ->assertOk()
            ->assertJsonPath('research.summary', $research->summary)
            ->assertJsonPath('research.previous_result.id', $previousResearch->id)
            ->assertJsonPath('research.previous_result.summary', 'Old summary that must not be repeated.')
            ->assertJsonPath('research.previous_result.developments.0', $knownDevelopment)
            ->assertJsonPath('research.previous_result.sources.0.url', 'https://example.com/known')
            ->assertJsonPath('research.previous_result.sources.0.title', 'Microsoft FY26 Q4 Results');

        StockResearchAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"is_follow_up": true')
                && str_contains($prompt->prompt, 'Microsoft meldet Quartalsumsatz')
                && str_contains($prompt->prompt, 'https://example.com/known'),
        );
    }

    public function test_latest_payload_keeps_the_last_finished_result_across_consecutive_empty_checks(): void
    {
        $admin = $this->adminUser();
        $otherAdmin = $this->adminUser();
        $holding = StockHolding::factory()->create(['symbol' => 'AMEE']);
        $previousDevelopment = $this->development([
            'category' => 'top_holding',
            'subject' => 'Iberdrola',
            'headline' => 'Iberdrola bleibt eine der größten ETF-Positionen.',
            'source_title' => 'Amundi ETF Factsheet',
            'source_url' => 'https://example.com/amundi-factsheet',
        ]);
        $finishedResearch = StockAiResearch::factory()->create([
            'id' => 'finished-research',
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'summary' => 'Letzte belastbare ETF-Analyse.',
            'developments' => [$previousDevelopment],
            'calculated_events' => ['history_usage' => 'comparison_only', 'market_reactions' => [['reaction_pct' => 2.1]]],
            'created_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
        ]);
        StockAiResearchSource::factory()->create([
            'stock_ai_research_id' => $finishedResearch->id,
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'url' => 'https://example.com/amundi-factsheet',
            'url_hash' => hash('sha256', 'https://example.com/amundi-factsheet'),
            'title' => 'Amundi ETF Factsheet',
        ]);
        $firstEmptyResearch = StockAiResearch::factory()->create([
            'id' => 'first-empty-research',
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'previous_research_id' => $finishedResearch->id,
            'status' => 'no_new_information',
            'has_material_update' => false,
            'summary' => 'Keine wichtigen neueren Informationen gefunden.',
            'developments' => [],
            'created_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
        ]);
        $latestEmptyResearch = StockAiResearch::factory()->create([
            'id' => 'latest-empty-research',
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'previous_research_id' => $firstEmptyResearch->id,
            'status' => 'no_new_information',
            'has_material_update' => false,
            'summary' => 'Keine wichtigen neueren Informationen seit der letzten Prüfung gefunden.',
            'developments' => [],
            'calculated_events' => ['history_usage' => 'comparison_only', 'market_reactions' => [['reaction_pct' => 1.4]]],
            'created_at' => now(),
            'finished_at' => now(),
        ]);
        StockAiResearch::factory()->create([
            'user_id' => $otherAdmin->id,
            'stock_holding_id' => $holding->id,
            'summary' => 'Analyse eines anderen Benutzers.',
            'created_at' => now()->addMinute(),
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/dashboard/ai/stock-researches')
            ->assertOk()
            ->assertJsonCount(1, 'researches')
            ->assertJsonPath('researches.0.id', $latestEmptyResearch->id)
            ->assertJsonPath('researches.0.calculated_events.market_reactions.0.reaction_pct', 1.4)
            ->assertJsonPath('researches.0.previous_result.id', $finishedResearch->id)
            ->assertJsonPath('researches.0.previous_result.summary', 'Letzte belastbare ETF-Analyse.')
            ->assertJsonPath('researches.0.previous_result.developments.0', $previousDevelopment)
            ->assertJsonPath('researches.0.previous_result.calculated_events.market_reactions.0.reaction_pct', 2.1)
            ->assertJsonMissing(['summary' => 'Analyse eines anderen Benutzers.']);
    }

    public function test_provider_failure_is_stored_without_leaking_provider_details(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();
        $previousResearch = StockAiResearch::factory()->create([
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'summary' => 'Letzte belastbare Analyse vor dem Providerfehler.',
            'finished_at' => now()->subMinute(),
        ]);
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        StockResearchAgent::fake(fn (): never => throw new RuntimeException('Provider secret: sk-private-value'))
            ->preventStrayPrompts();

        try {
            app(StockAiResearchService::class)->run($research->id);
            $this->fail('The provider exception should have been rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('sk-private-value', $exception->getMessage());
        }

        $research->refresh();
        $this->assertSame('failed', $research->status);
        $this->assertStringNotContainsString('sk-private-value', (string) $research->error);
        $this->assertStringNotContainsString('sk-private-value', (string) $research->message);

        $this->actingAs($admin)
            ->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$research->id}")
            ->assertOk()
            ->assertJsonPath('research.previous_result.id', $previousResearch->id)
            ->assertJsonPath('research.previous_result.summary', 'Letzte belastbare Analyse vor dem Providerfehler.');
    }

    public function test_incomplete_development_marks_research_as_failed(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        $incompleteDevelopment = $this->development(['relevance' => '']);
        StockResearchAgent::fake([$this->analysisResult([$incompleteDevelopment])])->preventStrayPrompts();

        $this->expectException(UnexpectedValueException::class);

        try {
            app(StockAiResearchService::class)->run($research->id);
        } finally {
            $this->assertSame('failed', $research->fresh()->status);
        }
    }

    public function test_invalid_development_marks_research_as_failed(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        $invalidDevelopment = $this->development([
            'category' => 'rumor',
            'status' => 'scheduled',
        ]);
        StockResearchAgent::fake([$this->analysisResult([$invalidDevelopment])])->preventStrayPrompts();

        $this->expectException(UnexpectedValueException::class);

        try {
            app(StockAiResearchService::class)->run($research->id);
        } finally {
            $this->assertSame('failed', $research->fresh()->status);
        }
    }

    public function test_invalid_development_timestamp_marks_research_as_failed(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        $invalidDevelopment = $this->development(['published_at' => 'next Tuesday']);
        StockResearchAgent::fake([$this->analysisResult([$invalidDevelopment])])->preventStrayPrompts();

        $this->expectException(UnexpectedValueException::class);

        try {
            app(StockAiResearchService::class)->run($research->id);
        } finally {
            $this->assertSame('failed', $research->fresh()->status);
        }
    }

    public function test_guest_cannot_start_or_read_stock_research(): void
    {
        $holding = StockHolding::factory()->create();
        $research = StockAiResearch::factory()->create(['stock_holding_id' => $holding->id]);

        $this->postJson("/admin/dashboard/ai/stocks/{$holding->id}/researches")->assertUnauthorized();
        $this->postJson('/admin/dashboard/ai/stock-researches')->assertUnauthorized();
        $this->getJson('/admin/dashboard/ai/stock-researches')->assertUnauthorized();
        $this->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$research->id}")->assertUnauthorized();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $developments
     * @return array<string, mixed>
     */
    private function analysisResult(?array $developments = null): array
    {
        $developments ??= [$this->development()];
        $firstDevelopment = $developments[0] ?? $this->development();

        return [
            'summary' => 'Eine konkrete, belegte Entwicklung ist für die analysierte Aktie relevant.',
            'developments' => $developments,
            'consulted_sources' => [[
                'title' => $firstDevelopment['source_title'],
                'url' => $firstDevelopment['source_url'],
                'source_type' => $firstDevelopment['source_type'],
            ]],
            'guidance_inputs' => [],
            'trump_connection' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function development(array $overrides = []): array
    {
        return array_replace([
            'category' => 'unusual_activity',
            'subject' => 'Apple Inc.',
            'subject_symbol' => 'AAPL.US',
            'event_at' => '2026-08-01',
            'published_at' => '2026-08-16T09:55:00+02:00',
            'retrieved_at' => '2026-08-16T10:00:00+02:00',
            'data_as_of' => '2026-08-16T09:55:00+02:00',
            'freshness' => 'live',
            'coverage' => 'partial',
            'headline' => 'Apple-Direktorin verkauft 50.000 Aktien.',
            'details' => 'Eine Form-4-Meldung dokumentiert den Verkauf von 50.000 Aktien.',
            'relevance' => 'Das Insidergeschäft betrifft die analysierte Aktie direkt.',
            'status' => 'confirmed',
            'source_type' => 'regulator',
            'impact' => 'negative',
            'materiality' => 'medium',
            'time_horizon' => 'today_72h',
            'impact_rationale' => 'Der dokumentierte Verkauf ist aktuell, aber allein kein Prognosesignal.',
            'source_title' => 'Apple SEC Form 4',
            'source_url' => 'https://example.com/apple-form-4?id=7',
        ], $overrides);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}

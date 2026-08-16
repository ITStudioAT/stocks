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
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Prompts\AgentPrompt;
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

    public function test_research_service_stores_structured_analysis_and_verified_citations(): void
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
        $structured = $this->analysisResult();
        $response = new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
            new Usage,
            new Meta('openai', 'test-model', collect([
                new UrlCitation('https://EXAMPLE.com/apple-news?utm_source=test&id=7#section', 'Apple news'),
                new UrlCitation('javascript:alert(1)', 'Unsafe source'),
            ])),
        );
        StockResearchAgent::fake([$response])->preventStrayPrompts();

        app(StockAiResearchService::class)->run($research->id);

        $research->refresh();
        $this->assertSame('finished', $research->status);
        $this->assertTrue($research->has_material_update);
        $this->assertSame('hold', $research->recommendation);
        $this->assertSame($structured['summary'], $research->summary);
        $this->assertSame($structured['new_findings'], $research->known_information);
        $this->assertDatabaseHas('stock_ai_research_sources', [
            'stock_ai_research_id' => $research->id,
            'url' => 'https://example.com/apple-news?id=7',
            'title' => 'Apple news',
        ]);
        $this->assertDatabaseCount('stock_ai_research_sources', 1);

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

    public function test_repeat_research_does_not_repeat_known_information(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create(['symbol' => 'MSFT']);
        $previousResearch = StockAiResearch::factory()->create([
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'summary' => 'Old summary that must not be repeated.',
            'known_information' => ['The existing known fact.'],
            'finished_at' => now()->subHour(),
        ]);
        StockAiResearchSource::factory()->create([
            'stock_ai_research_id' => $previousResearch->id,
            'user_id' => $admin->id,
            'stock_holding_id' => $holding->id,
            'url' => 'https://example.com/known',
            'url_hash' => hash('sha256', 'https://example.com/known'),
        ]);
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        StockResearchAgent::fake([[
            'has_material_update' => true,
            'summary' => 'Paraphrased old summary.',
            'stronger_case' => 'Old upside case.',
            'weaker_case' => 'Old downside case.',
            'trump_connection' => 'Old political assessment.',
            'recommendation' => 'hold',
            'justification' => 'Old justification.',
            'new_findings' => ['The existing known fact.'],
        ]])->preventStrayPrompts();

        app(StockAiResearchService::class)->run($research->id);

        $research->refresh();
        $this->assertSame('no_new_information', $research->status);
        $this->assertFalse($research->has_material_update);
        $this->assertStringContainsString('Keine wichtigen neueren Informationen', $research->summary);
        $this->assertStringNotContainsString('Old summary', $research->summary);
        $this->assertNull($research->stronger_case);
        $this->assertSame(['The existing known fact.'], $research->known_information);

        StockResearchAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"is_follow_up": true')
                && str_contains($prompt->prompt, 'The existing known fact.')
                && str_contains($prompt->prompt, 'https://example.com/known'),
        );
    }

    public function test_provider_failure_is_stored_without_leaking_provider_details(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();
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
    }

    public function test_incomplete_structured_response_marks_research_as_failed(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();
        $research = app(StockAiResearchService::class)->dispatch($admin, $holding);
        StockResearchAgent::fake([[
            'has_material_update' => true,
            'summary' => 'Incomplete analysis.',
            'stronger_case' => '',
            'weaker_case' => '',
            'trump_connection' => '',
            'recommendation' => 'hold',
            'justification' => '',
            'new_findings' => [],
        ]])->preventStrayPrompts();

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
        $this->getJson('/admin/dashboard/ai/stock-researches')->assertUnauthorized();
        $this->getJson("/admin/dashboard/ai/stocks/{$holding->id}/researches/{$research->id}")->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisResult(): array
    {
        return [
            'has_material_update' => true,
            'summary' => 'Neue Produktdaten stützen kurzfristig die Nachfrage, die Bewertung bleibt jedoch anspruchsvoll.',
            'stronger_case' => 'Überraschend starke Nachfrage könnte den Kurs stützen.',
            'weaker_case' => 'Schwächere Konsumausgaben könnten den Kurs belasten.',
            'trump_connection' => 'Es wurde kein materieller Zusammenhang mit aktuellen Aussagen von Donald Trump gefunden.',
            'recommendation' => 'hold',
            'justification' => 'Die Chancen und Bewertungsrisiken sind derzeit ausgeglichen.',
            'new_findings' => [
                'Neue Produktdaten deuten auf stabile Nachfrage hin.',
                'Die Bewertung bleibt im Branchenvergleich anspruchsvoll.',
            ],
        ];
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeWebsite;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\WebsiteAnalysis;
use App\Services\WebsiteAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminWebsiteAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_queue_analysis_for_selected_company_and_client(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com' => Http::response('<html><title>Example</title></html>'),
        ]);

        $admin = $this->superAdminUser();
        $activeCompany = Company::factory()->create([
            'is_active' => true,
        ]);
        $activeClient = Client::factory()->create([
            'company_id' => $activeCompany->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/website-analyses', [
                'url' => 'https://example.com',
            ])
            ->assertAccepted()
            ->assertJsonPath('analysis.company_name', $activeCompany->company_name_1)
            ->assertJsonPath('analysis.client_name', $activeClient->name)
            ->assertJsonPath('analysis.status', 'queued');

        $this->assertDatabaseHas('website_analyses', [
            'company_id' => $activeCompany->id,
            'client_id' => $activeClient->id,
            'url' => 'https://example.com',
            'status' => 'queued',
        ]);

        Queue::assertPushed(AnalyzeWebsite::class);
    }

    public function test_analysis_requires_an_active_client_for_the_selected_company(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com' => Http::response('<html><title>Example</title></html>'),
        ]);

        $admin = $this->superAdminUser();
        Company::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/website-analyses', [
                'url' => 'https://example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client');

        Queue::assertNothingPushed();
    }

    public function test_super_admin_analysis_uses_assigned_company_when_no_company_is_active(): void
    {
        $company = Company::factory()->create([
            'is_active' => false,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
        ]);
        $admin = $this->superAdminUser([
            'company_id' => $company->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/website-analyses')
            ->assertOk()
            ->assertJsonPath('analyses.0.id', $analysis->id)
            ->assertJsonPath('analyses.0.company_name', $company->company_name_1)
            ->assertJsonPath('analyses.0.client_name', $client->name);
    }

    public function test_admin_can_check_a_url_before_queueing_analysis(): void
    {
        Http::fake([
            'https://example.com' => Http::response('<html><title>Example</title></html>', 200, ['content-type' => 'text/html']),
        ]);

        $admin = $this->superAdminUser();
        Company::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/website-analyses/check-url', [
                'url' => 'https://example.com',
            ])
            ->assertOk()
            ->assertJsonPath('url.url', 'https://example.com')
            ->assertJsonPath('url.status', 200);
    }

    public function test_admin_can_view_completed_analysis_report_for_selected_company_and_client(): void
    {
        Storage::fake('local');

        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'status' => 'completed',
            'pages_count' => 2,
            'assets_count' => 5,
            'result_path' => 'analyses/1/analysis.json',
        ]);
        $report = [
            'summary' => [
                'pages_discovered' => 2,
                'site_assets' => 5,
            ],
            'homepage' => [
                'title' => 'Home',
            ],
            'pages' => [
                ['url' => 'https://example.com', 'title' => 'Home'],
            ],
        ];

        Storage::disk('local')->put($analysis->result_path, json_encode($report));

        $this->actingAs($admin)
            ->getJson("/admin/website-analyses/{$analysis->id}")
            ->assertOk()
            ->assertJsonPath('analysis.company_name', $company->company_name_1)
            ->assertJsonPath('analysis.client_name', $client->name)
            ->assertJsonPath('analysis.show_url', "/admin/helpers/analyse/{$analysis->id}")
            ->assertJsonPath('report.summary.pages_discovered', 2)
            ->assertJsonPath('report.homepage.title', 'Home');
    }

    public function test_admin_cannot_view_analysis_for_another_selected_company_or_client(): void
    {
        $admin = $this->superAdminUser();
        $activeCompany = Company::factory()->create([
            'is_active' => true,
        ]);
        Client::factory()->create([
            'company_id' => $activeCompany->id,
            'is_active' => true,
        ]);
        $otherCompany = Company::factory()->create([
            'is_active' => false,
        ]);
        $otherClient = Client::factory()->create([
            'company_id' => $otherCompany->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $otherCompany->id,
            'client_id' => $otherClient->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/website-analyses/{$analysis->id}")
            ->assertForbidden();
    }

    public function test_admin_can_delete_analysis_for_selected_company_and_client(): void
    {
        Storage::fake('local');

        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'status' => 'completed',
            'result_path' => 'analyses/1/analysis.json',
        ]);

        Storage::disk('local')->put($analysis->result_path, '{}');

        $this->actingAs($admin)
            ->deleteJson("/admin/website-analyses/{$analysis->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Analysis deleted.');

        $this->assertDatabaseMissing('website_analyses', [
            'id' => $analysis->id,
        ]);
        Storage::disk('local')->assertMissing($analysis->result_path);
    }

    public function test_admin_cannot_delete_a_running_analysis(): void
    {
        Storage::fake('local');

        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'status' => 'running',
            'result_path' => 'analyses/1/analysis.json',
        ]);

        Storage::disk('local')->put($analysis->result_path, '{}');

        $this->actingAs($admin)
            ->deleteJson("/admin/website-analyses/{$analysis->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Running analyses cannot be deleted.');

        $this->assertDatabaseHas('website_analyses', [
            'id' => $analysis->id,
        ]);
        Storage::disk('local')->assertExists($analysis->result_path);
    }

    public function test_admin_cannot_delete_analysis_for_another_selected_company_or_client(): void
    {
        $admin = $this->superAdminUser();
        $activeCompany = Company::factory()->create([
            'is_active' => true,
        ]);
        Client::factory()->create([
            'company_id' => $activeCompany->id,
            'is_active' => true,
        ]);
        $otherCompany = Company::factory()->create([
            'is_active' => false,
        ]);
        $otherClient = Client::factory()->create([
            'company_id' => $otherCompany->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $otherCompany->id,
            'client_id' => $otherClient->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/admin/website-analyses/{$analysis->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('website_analyses', [
            'id' => $analysis->id,
        ]);
    }

    public function test_admin_can_rerun_analysis_for_selected_company_and_client(): void
    {
        Queue::fake();
        Storage::fake('local');

        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'status' => 'completed',
            'pages_count' => 12,
            'assets_count' => 34,
            'result_path' => 'analyses/1/analysis.json',
            'error_message' => 'Old error',
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);

        Storage::disk('local')->put($analysis->result_path, '{}');

        $this->actingAs($admin)
            ->postJson("/admin/website-analyses/{$analysis->id}/rerun")
            ->assertAccepted()
            ->assertJsonPath('message', 'Analysis queued again.')
            ->assertJsonPath('analysis.status', 'queued')
            ->assertJsonPath('analysis.pages_count', 0)
            ->assertJsonPath('analysis.assets_count', 0)
            ->assertJsonPath('analysis.result_path', null)
            ->assertJsonPath('analysis.error_message', null)
            ->assertJsonPath('analysis.started_at', null)
            ->assertJsonPath('analysis.completed_at', null)
            ->assertJsonPath('report', null);

        $this->assertDatabaseHas('website_analyses', [
            'id' => $analysis->id,
            'status' => 'queued',
            'pages_count' => 0,
            'assets_count' => 0,
            'result_path' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
        Storage::disk('local')->assertMissing('analyses/1/analysis.json');
        Queue::assertPushed(AnalyzeWebsite::class);
    }

    public function test_admin_cannot_rerun_running_analysis(): void
    {
        Queue::fake();
        Storage::fake('local');

        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'status' => 'running',
            'result_path' => 'analyses/1/analysis.json',
        ]);

        Storage::disk('local')->put($analysis->result_path, '{}');

        $this->actingAs($admin)
            ->postJson("/admin/website-analyses/{$analysis->id}/rerun")
            ->assertConflict()
            ->assertJsonPath('message', 'Running analyses cannot be restarted.');

        $this->assertDatabaseHas('website_analyses', [
            'id' => $analysis->id,
            'status' => 'running',
            'result_path' => 'analyses/1/analysis.json',
        ]);
        Storage::disk('local')->assertExists('analyses/1/analysis.json');
        Queue::assertNothingPushed();
    }

    public function test_admin_can_cancel_queued_analysis_and_remove_pending_job(): void
    {
        config(['queue.default' => 'database']);

        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'status' => 'queued',
        ]);

        AnalyzeWebsite::dispatch($analysis);

        $this->assertDatabaseCount('jobs', 1);

        $this->actingAs($admin)
            ->postJson("/admin/website-analyses/{$analysis->id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Analysis canceled.')
            ->assertJsonPath('analysis.status', 'canceled');

        $this->assertDatabaseHas('website_analyses', [
            'id' => $analysis->id,
            'status' => 'canceled',
            'error_message' => null,
        ]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_admin_can_mark_running_analysis_as_canceled(): void
    {
        Queue::fake();

        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'status' => 'running',
            'error_message' => 'Old error',
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/website-analyses/{$analysis->id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Analysis canceled.')
            ->assertJsonPath('analysis.status', 'canceled')
            ->assertJsonPath('analysis.error_message', null);

        $this->assertDatabaseHas('website_analyses', [
            'id' => $analysis->id,
            'status' => 'canceled',
            'error_message' => null,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_admin_cannot_cancel_finished_analysis(): void
    {
        Queue::fake();

        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/website-analyses/{$analysis->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', 'Only queued or running analyses can be canceled.');

        $this->assertDatabaseHas('website_analyses', [
            'id' => $analysis->id,
            'status' => 'completed',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_website_analyzer_reports_live_page_and_asset_progress(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com' => Http::response($this->homepageHtml(), 200, ['content-type' => 'text/html']),
            'https://example.com/about' => Http::response('<html><title>About</title><body><header><nav><a href="/about">About</a></nav></header><main><h1>About us</h1><img src="/team.jpg" alt="Team"></main><footer><a href="mailto:office@example.com">Office</a></footer></body></html>', 200, ['content-type' => 'text/html']),
            'https://example.com/app.css' => Http::response('body { color: #123456; font-family: "Work Sans", Helvetica, sans-serif; }'),
            'https://example.com/robots.txt' => Http::response("User-agent: *\nAllow: /"),
            'https://example.com/sitemap.xml' => Http::response('<urlset><url><loc>https://example.com/about</loc></url></urlset>'),
        ]);

        $analysis = WebsiteAnalysis::factory()->create([
            'url' => 'https://example.com',
            'host' => 'example.com',
        ]);
        $progressUpdates = [];
        $interimReportChecked = false;

        app(WebsiteAnalyzer::class)->analyze(
            $analysis,
            function (
                int $pagesCount,
                int $assetsCount,
                string $analysisStep,
                int $reachabilityCheckedCount,
                int $reachabilityTotalCount,
            ) use (&$progressUpdates, &$interimReportChecked, $analysis): void {
                $progressUpdates[] = [
                    'pages_count' => $pagesCount,
                    'assets_count' => $assetsCount,
                    'analysis_step' => $analysisStep,
                    'reachability_checked_count' => $reachabilityCheckedCount,
                    'reachability_total_count' => $reachabilityTotalCount,
                ];

                if ($analysisStep !== 'reachability' || $reachabilityCheckedCount !== 0) {
                    return;
                }

                $path = "analyses/{$analysis->id}/analysis.json";

                Storage::disk('local')->assertExists($path);

                $interimReport = json_decode(Storage::disk('local')->get($path), true);

                $this->assertSame('reachability', $interimReport['analysis']['step']);
                $this->assertSame(2, $interimReport['summary']['pages_discovered']);
                $this->assertTrue($interimReport['header_menu']['found']);
                $this->assertTrue($interimReport['footer_information']['found']);
                $this->assertSame(0, $interimReport['summary']['resources_failed']);
                $interimReportChecked = true;
            },
        );

        $this->assertTrue($interimReportChecked);
        $this->assertSame(1, $progressUpdates[0]['pages_count']);
        $this->assertGreaterThanOrEqual(4, $progressUpdates[0]['assets_count']);
        $this->assertSame('analysis', $progressUpdates[0]['analysis_step']);
        $this->assertSame(2, $progressUpdates[array_key_last($progressUpdates)]['pages_count']);
        $this->assertSame('reachability', $progressUpdates[array_key_last($progressUpdates)]['analysis_step']);
        $this->assertSame(
            $progressUpdates[array_key_last($progressUpdates)]['reachability_total_count'],
            $progressUpdates[array_key_last($progressUpdates)]['reachability_checked_count'],
        );
    }

    public function test_website_analyzer_does_not_report_linked_images_as_failed_pages(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com' => Http::response('<html><body><main><a href="/gallery/photo.jpg">Photo</a><a href="/about">About</a></main></body></html>', 200, ['content-type' => 'text/html']),
            'https://example.com/about' => Http::response('<html><body><main><h1>About</h1></main></body></html>', 200, ['content-type' => 'text/html']),
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
        ]);

        $analysis = WebsiteAnalysis::factory()->create([
            'url' => 'https://example.com',
            'host' => 'example.com',
        ]);

        $report = app(WebsiteAnalyzer::class)->analyze($analysis);

        $this->assertSame(2, $report['summary']['pages_discovered']);
        $this->assertSame(0, $report['summary']['pages_failed']);
        $this->assertSame([], $report['errors']);
        $this->assertNotContains('https://example.com/gallery/photo.jpg', $report['homepage']['links']['internal']);
    }

    public function test_website_analyzer_reports_links_images_and_files_that_do_not_return_200(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com' => Http::response('<html><body><main><a href="/about">About</a><a href="https://external.example/broken">External</a><a href="/downloads/menu.pdf">Menu</a><img src="/images/missing.jpg" alt="Missing"></main></body></html>', 200, ['content-type' => 'text/html']),
            'https://example.com/about' => Http::response('<html><body><main><h1>About</h1></main></body></html>', 200, ['content-type' => 'text/html']),
            'https://example.com/downloads/menu.pdf' => Http::response('Forbidden', 403, ['content-type' => 'application/pdf']),
            'https://example.com/images/missing.jpg' => Http::response('Not found', 404, ['content-type' => 'text/plain']),
            'https://external.example/broken' => Http::response('Server error', 500, ['content-type' => 'text/html']),
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
        ]);

        $analysis = WebsiteAnalysis::factory()->create([
            'url' => 'https://example.com',
            'host' => 'example.com',
        ]);

        $report = app(WebsiteAnalyzer::class)->analyze($analysis);
        $errorsByUrl = collect($report['errors'])->keyBy('url');

        $this->assertSame(0, $report['summary']['pages_failed']);
        $this->assertSame(3, $report['summary']['resources_failed']);
        $this->assertSame('link', $errorsByUrl['https://external.example/broken']['type']);
        $this->assertSame(500, $errorsByUrl['https://external.example/broken']['status']);
        $this->assertSame(['https://example.com'], $errorsByUrl['https://external.example/broken']['found_on']);
        $this->assertSame('file', $errorsByUrl['https://example.com/downloads/menu.pdf']['type']);
        $this->assertSame(403, $errorsByUrl['https://example.com/downloads/menu.pdf']['status']);
        $this->assertSame(['https://example.com'], $errorsByUrl['https://example.com/downloads/menu.pdf']['found_on']);
        $this->assertSame('image', $errorsByUrl['https://example.com/images/missing.jpg']['type']);
        $this->assertSame(404, $errorsByUrl['https://example.com/images/missing.jpg']['status']);
        $this->assertSame(['https://example.com'], $errorsByUrl['https://example.com/images/missing.jpg']['found_on']);
    }

    public function test_analysis_job_writes_comprehensive_json_report(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com' => Http::response($this->homepageHtml(), 200, ['content-type' => 'text/html']),
            'https://example.com/about' => Http::response('<html><title>About</title><body><header><nav><a href="/about">About</a></nav></header><main><h1>About us</h1><img src="/team.jpg" alt="Team"></main><footer><a href="mailto:office@example.com">Office</a></footer></body></html>', 200, ['content-type' => 'text/html']),
            'https://example.com/app.css' => Http::response('body { color: #123456; font-family: "Work Sans", Helvetica, sans-serif; }'),
            'https://example.com/robots.txt' => Http::response("User-agent: *\nAllow: /"),
            'https://example.com/sitemap.xml' => Http::response('<urlset><url><loc>https://example.com/about</loc></url></urlset>'),
        ]);

        $company = Company::factory()->create();
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'url' => 'https://example.com',
            'host' => 'example.com',
        ]);

        (new AnalyzeWebsite($analysis))->handle(app(WebsiteAnalyzer::class));

        $analysis->refresh();

        $this->assertSame('completed', $analysis->status);
        $this->assertSame('completed', $analysis->analysis_step);
        $this->assertSame(2, $analysis->pages_count);
        $this->assertGreaterThanOrEqual(4, $analysis->assets_count);
        $this->assertSame($analysis->reachability_total_count, $analysis->reachability_checked_count);
        $this->assertGreaterThan(0, $analysis->reachability_total_count);
        $this->assertNotNull($analysis->result_path);
        Storage::disk('local')->assertExists($analysis->result_path);

        $json = json_decode(Storage::disk('local')->get($analysis->result_path), true);

        $this->assertSame('https://example.com', $json['analysis']['url']);
        $this->assertSame(2, $json['summary']['pages_discovered']);
        $this->assertGreaterThanOrEqual(4, $json['summary']['site_assets']);
        $this->assertSame('Home', $json['homepage']['title']);
        $this->assertSame('de', $json['homepage']['meta']['language']);
        $this->assertStringStartsWith('Das Christian-Doppler-Gymnasium ist ein Realgymnasium', $json['homepage']['meta']['landing_description']);
        $this->assertContains('/about', $json['site_structure']['page_paths']);
        $this->assertTrue($json['crawl_metadata']['robots_txt']['reachable']);
        $this->assertTrue($json['crawl_metadata']['sitemap_xml']['reachable']);
        $this->assertGreaterThanOrEqual(1, $json['summary']['colors_found']);
        $this->assertGreaterThanOrEqual(1, $json['summary']['fonts_found']);
        $this->assertContains('Work Sans', array_column($json['fonts'], 'value'));
        $this->assertContains('Helvetica', array_column($json['fonts'], 'value'));
        $this->assertNotContains('sans-serif', array_column($json['fonts'], 'value'));
        $this->assertTrue($json['header_menu']['found']);
        $this->assertSame(1, $json['header_menu']['link_count']);
        $this->assertSame(2, $json['header_menu']['page_count']);
        $this->assertTrue($json['footer_information']['found']);
        $this->assertSame(1, $json['footer_information']['link_count']);
        $this->assertSame(2, $json['footer_information']['page_count']);
        $this->assertSame(1, count($json['homepage']['links']['internal']));
        $this->assertNotContains('https://example.com/brochure.pdf', $json['homepage']['links']['internal']);
        $this->assertSame(0, count($json['homepage']['content_links']['internal']));
        $this->assertSame(2, $json['summary']['site_images']);
        $this->assertSame(1, $json['summary']['homepage_images']);
        $this->assertSame(1, $json['summary']['homepage_other_files']);
        $this->assertContains('https://example.com/brochure.pdf', $json['homepage']['assets']['other_files']);
    }

    public function test_website_analyzer_detects_footer_information_on_a_single_page(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com' => Http::response('<html><body><main><h1>Home</h1></main><footer><a href="/impressum">Impressum</a><a href="mailto:office@example.com">Office</a></footer></body></html>', 200, ['content-type' => 'text/html']),
            'https://example.com/impressum' => Http::response('Not Found', 404),
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
        ]);

        $analysis = WebsiteAnalysis::factory()->create([
            'url' => 'https://example.com',
            'host' => 'example.com',
        ]);

        $report = app(WebsiteAnalyzer::class)->analyze($analysis);

        $this->assertTrue($report['footer_information']['found']);
        $this->assertSame(2, $report['footer_information']['link_count']);
        $this->assertSame(1, $report['footer_information']['page_count']);
        $this->assertContains('https://example.com/impressum', $report['footer_information']['links']);
        $this->assertContains('mailto:office@example.com', $report['footer_information']['links']);
    }

    public function test_website_analyzer_detects_navigation_from_header_and_footer_ids(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com' => Http::response($this->legacyTemplateHtml('Home'), 200, ['content-type' => 'text/html']),
            'https://example.com/index.php' => Http::response($this->legacyTemplateHtml('Start'), 200, ['content-type' => 'text/html']),
            'https://example.com/index.php/ueberuns.html' => Http::response($this->legacyTemplateHtml('About'), 200, ['content-type' => 'text/html']),
            'https://example.com/index.php/kontakt.html' => Http::response($this->legacyTemplateHtml('Kontakt'), 200, ['content-type' => 'text/html']),
            'https://example.com/index.php/datenschutz.html' => Http::response($this->legacyTemplateHtml('Datenschutz'), 200, ['content-type' => 'text/html']),
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
        ]);

        $analysis = WebsiteAnalysis::factory()->create([
            'url' => 'https://example.com',
            'host' => 'example.com',
        ]);

        $report = app(WebsiteAnalyzer::class)->analyze($analysis);

        $this->assertTrue($report['header_menu']['found']);
        $this->assertSame(4, $report['header_menu']['link_count']);
        $this->assertSame(5, $report['header_menu']['page_count']);
        $this->assertContains('https://example.com/#aktuelles', $report['header_menu']['links']);
        $this->assertContains('https://example.com/index.php/ueberuns.html', $report['header_menu']['links']);
        $this->assertContains('https://example.com/index.php/kontakt.html', $report['header_menu']['links']);
        $this->assertTrue($report['footer_information']['found']);
        $this->assertSame(2, $report['footer_information']['link_count']);
        $this->assertSame(5, $report['footer_information']['page_count']);
        $this->assertContains('https://example.com/index.php/datenschutz.html', $report['footer_information']['links']);
        $this->assertContains('https://external.example/webuntis', $report['footer_information']['links']);
    }

    private function homepageHtml(): string
    {
        return <<<'HTML'
<html lang="de">
    <head>
        <title>Home</title>
        <meta name="description" content="Homepage">
        <meta name="viewport" content="width=device-width">
        <meta property="og:title" content="Home OG">
        <link rel="stylesheet" href="/app.css">
        <link rel="icon" href="/favicon.ico">
        <style>.hero { background: #abcdef; font-family: Arial; }</style>
    </head>
    <body>
        <header><nav><a href="/about">About</a></nav></header>
        <main>
            <h1 style="color: rgb(10, 20, 30)">Welcome</h1>
            <p>Das Christian-Doppler-Gymnasium ist ein Realgymnasium mit drei verschiedenen Schulprofilen, die achtjährig geführt werden, dem science lab, media lab und der sports school.</p>
            <section><img src="/hero.jpg" alt="Hero"></section>
            <a href="/brochure.pdf">PDF</a>
        </main>
        <footer><a href="mailto:office@example.com">Office</a></footer>
    </body>
</html>
HTML;
    }

    private function legacyTemplateHtml(string $title): string
    {
        return <<<HTML
<html lang="de">
    <head>
        <title>{$title}</title>
    </head>
    <body>
        <div class="headbar">
            <div id="HLWHeaderLogo">
                <a href="https://example.com/index.php">Logo</a>
            </div>
            <div id="HLWHeaderMenue">
                <ul class="nav menu mod-list">
                    <li><a href="/index.php">Start</a></li>
                    <li><a href="/#aktuelles">Aktuelles</a></li>
                    <li><a href="/index.php/ueberuns.html">Über uns</a></li>
                    <li><a href="/index.php/kontakt.html">Kontakt</a></li>
                </ul>
            </div>
        </div>
        <main>
            <h1>{$title}</h1>
            <a href="/content-link.html">Content link</a>
        </main>
        <div id="footer2">
            <a id="footerLink" href="https://external.example/webuntis">WebUntis</a>
            <a id="footerLink" href="/index.php/datenschutz.html">Datenschutz</a>
        </div>
    </body>
</html>
HTML;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function superAdminUser(array $attributes = []): User
    {
        Role::findOrCreate('super_admin');

        $user = User::factory()->create($attributes);
        $user->assignRole('super_admin');

        return $user;
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeWebsite;
use App\Models\Client;
use App\Models\Company;
use App\Models\WebsiteAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminWebsiteAnalysisController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $this->selectedCompany($request);
        $client = $this->selectedClient($company);

        $analyses = WebsiteAnalysis::query()
            ->with(['company', 'client'])
            ->where('company_id', $company->id)
            ->where('client_id', $client->id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (WebsiteAnalysis $analysis): array => $this->analysisPayload($analysis));

        return response()->json([
            'analyses' => $analyses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $url = $this->normalizedUrl($validated['url']);
        $urlCheck = $this->checkReachableUrl($url);
        $company = $this->selectedCompany($request);
        $client = $this->selectedClient($company);

        $analysis = WebsiteAnalysis::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'url' => $urlCheck['url'],
            'host' => parse_url($urlCheck['url'], PHP_URL_HOST),
            'status' => 'queued',
            'analysis_step' => 'pending',
        ]);

        AnalyzeWebsite::dispatch($analysis);

        return response()->json([
            'message' => 'Analysis queued.',
            'analysis' => $this->analysisPayload($analysis->load(['company', 'client'])),
        ], 202);
    }

    public function show(Request $request, WebsiteAnalysis $analysis): JsonResponse
    {
        $company = $this->selectedCompany($request);
        $client = $this->selectedClient($company);

        abort_unless($analysis->company_id === $company->id && $analysis->client_id === $client->id, 403);

        $analysis->load(['company', 'client']);

        return response()->json([
            'analysis' => $this->analysisPayload($analysis),
            'report' => $this->analysisReport($analysis),
        ]);
    }

    public function destroy(Request $request, WebsiteAnalysis $analysis): JsonResponse
    {
        $company = $this->selectedCompany($request);
        $client = $this->selectedClient($company);

        abort_unless($analysis->company_id === $company->id && $analysis->client_id === $client->id, 403);

        if (in_array($analysis->status, ['queued', 'running'], true)) {
            return response()->json([
                'message' => 'Running analyses cannot be deleted.',
            ], 409);
        }

        if ($analysis->result_path) {
            Storage::disk('local')->delete($analysis->result_path);
        }

        $analysis->delete();

        return response()->json([
            'message' => 'Analysis deleted.',
        ]);
    }

    public function rerun(Request $request, WebsiteAnalysis $analysis): JsonResponse
    {
        $company = $this->selectedCompany($request);
        $client = $this->selectedClient($company);

        abort_unless($analysis->company_id === $company->id && $analysis->client_id === $client->id, 403);

        if (in_array($analysis->status, ['queued', 'running'], true)) {
            return response()->json([
                'message' => 'Running analyses cannot be restarted.',
            ], 409);
        }

        if ($analysis->result_path) {
            Storage::disk('local')->delete($analysis->result_path);
        }

        $analysis->update([
            'status' => 'queued',
            'analysis_step' => 'pending',
            'pages_count' => 0,
            'assets_count' => 0,
            'reachability_checked_count' => 0,
            'reachability_total_count' => 0,
            'result_path' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        AnalyzeWebsite::dispatch($analysis);

        return response()->json([
            'message' => 'Analysis queued again.',
            'analysis' => $this->analysisPayload($analysis->refresh()->load(['company', 'client'])),
            'report' => null,
        ], 202);
    }

    public function cancel(Request $request, WebsiteAnalysis $analysis): JsonResponse
    {
        $company = $this->selectedCompany($request);
        $client = $this->selectedClient($company);

        abort_unless($analysis->company_id === $company->id && $analysis->client_id === $client->id, 403);

        if (! in_array($analysis->status, ['queued', 'running'], true)) {
            return response()->json([
                'message' => 'Only queued or running analyses can be canceled.',
            ], 409);
        }

        $this->deleteQueuedAnalysisJobs($analysis);

        $analysis->update([
            'status' => 'canceled',
            'analysis_step' => 'canceled',
            'error_message' => null,
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Analysis canceled.',
            'analysis' => $this->analysisPayload($analysis->refresh()->load(['company', 'client'])),
        ]);
    }

    public function checkUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        return response()->json([
            'message' => 'URL checked.',
            'url' => $this->checkReachableUrl($this->normalizedUrl($validated['url'])),
        ]);
    }

    private function selectedCompany(Request $request): Company
    {
        $user = $request->user();

        $company = $user->hasRole('super_admin')
            ? Company::query()
                ->where('is_active', true)
                ->first() ?? $user->company
            : $user->company;

        if ($company) {
            return $company;
        }

        throw ValidationException::withMessages([
            'company' => 'No company is selected.',
        ]);
    }

    private function selectedClient(Company $company): Client
    {
        $client = Client::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->first();

        if ($client) {
            return $client;
        }

        throw ValidationException::withMessages([
            'client' => 'No active client is selected for this company.',
        ]);
    }

    private function normalizedUrl(string $url): string
    {
        return rtrim($url, '/');
    }

    private function deleteQueuedAnalysisJobs(WebsiteAnalysis $analysis): void
    {
        DB::table('jobs')
            ->get(['id', 'payload'])
            ->filter(fn (object $job): bool => $this->jobPayloadBelongsToAnalysis((string) $job->payload, $analysis))
            ->each(fn (object $job): int => DB::table('jobs')->where('id', $job->id)->delete());
    }

    private function jobPayloadBelongsToAnalysis(string $payload, WebsiteAnalysis $analysis): bool
    {
        $decodedPayload = json_decode($payload, true);
        $command = is_array($decodedPayload) ? ($decodedPayload['data']['command'] ?? '') : '';

        if (! is_string($command) || ! str_contains($command, AnalyzeWebsite::class)) {
            return false;
        }

        return str_contains($command, "s:2:\"id\";i:{$analysis->id};")
            || str_contains($command, 's:2:"id";s:'.strlen((string) $analysis->id).":\"{$analysis->id}\";");
    }

    /**
     * @return array{url: string, status: int, content_type: ?string}
     */
    private function checkReachableUrl(string $url): array
    {
        $response = Http::connectTimeout(5)
            ->timeout(10)
            ->retry(1, 250)
            ->withUserAgent('Stocks Analyzer/1.0')
            ->get($url);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'url' => 'The URL could not be reached.',
            ]);
        }

        return [
            'url' => $url,
            'status' => $response->status(),
            'content_type' => $response->header('content-type'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analysisReport(WebsiteAnalysis $analysis): ?array
    {
        if (! $analysis->result_path || ! Storage::disk('local')->exists($analysis->result_path)) {
            return null;
        }

        $report = json_decode(Storage::disk('local')->get($analysis->result_path), true);

        if (! is_array($report)) {
            return null;
        }

        return $report;
    }

    private function analysisPayload(WebsiteAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'url' => $analysis->url,
            'host' => $analysis->host,
            'status' => $analysis->status,
            'analysis_step' => $analysis->analysis_step,
            'company_name' => $analysis->company?->company_name_1,
            'client_name' => $analysis->client?->name,
            'pages_count' => $analysis->pages_count,
            'assets_count' => $analysis->assets_count,
            'reachability_checked_count' => $analysis->reachability_checked_count,
            'reachability_total_count' => $analysis->reachability_total_count,
            'result_path' => $analysis->result_path,
            'error_message' => $analysis->error_message,
            'started_at' => $analysis->started_at?->toIso8601String(),
            'completed_at' => $analysis->completed_at?->toIso8601String(),
            'created_at' => $analysis->created_at?->toIso8601String(),
            'show_url' => "/admin/helpers/analyse/{$analysis->id}",
        ];
    }
}

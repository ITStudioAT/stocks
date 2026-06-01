<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Company;
use App\Models\WebsiteAnalysis;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', Rule::exists(Company::class, 'id')],
        ]);

        $clients = Client::query()
            ->with('company')
            ->withCount('websiteAnalyses')
            ->when(
                ! $request->user()->hasRole('super_admin'),
                fn ($query) => $query->where('company_id', $request->user()->company_id),
            )
            ->when(
                $request->user()->hasRole('super_admin') && isset($validated['company_id']),
                fn ($query) => $query->where('company_id', $validated['company_id']),
            )
            ->latest('updated_at')
            ->get();

        return response()->json([
            'clients' => $clients->map(fn (Client $client): array => $this->clientData($client)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', Rule::exists(Company::class, 'id')],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('clients', 'name')
                    ->where(fn ($query) => $query->where('company_id', $request->input('company_id'))),
            ],
            'signature' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:clients,signature'],
        ]);

        $client = Client::create([
            ...$validated,
            'headline' => $validated['name'],
            'is_published' => true,
            'is_active' => ! Client::query()
                ->where('company_id', $validated['company_id'])
                ->exists(),
        ])->load('company');

        return response()->json([
            'message' => 'Client created.',
            'client' => $this->clientData($client),
        ]);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('clients', 'name')
                    ->where(fn ($query) => $query->where('company_id', $client->company_id))
                    ->ignore($client),
            ],
            'signature' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('clients', 'signature')->ignore($client),
            ],
        ]);

        $client->update([
            ...$validated,
            'headline' => $validated['name'],
        ]);

        return response()->json([
            'message' => 'Client updated.',
            'client' => $this->clientData($client->load('company')),
        ]);
    }

    public function activate(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClientAccess($request, $client);

        Client::query()
            ->where('company_id', $client->company_id)
            ->where('id', '!=', $client->id)
            ->update(['is_active' => false]);

        $client->update(['is_active' => true]);

        return response()->json([
            'message' => 'Client activated.',
            'client' => $this->clientData($client->load('company')),
        ]);
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClientAccess($request, $client);

        $request->validate([
            'confirmation' => ['required', 'string', Rule::in(['DELETE'])],
        ]);

        DB::transaction(function () use ($client): void {
            $client->websiteAnalyses()
                ->get()
                ->each(function (WebsiteAnalysis $analysis): void {
                    if ($analysis->result_path) {
                        Storage::disk('local')->delete($analysis->result_path);
                    }

                    $analysis->delete();
                });

            $client->delete();
        });

        return response()->json([
            'message' => 'Client deleted.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientData(Client $client): array
    {
        return [
            'id' => $client->id,
            'company_id' => $client->company_id,
            'company_name' => $client->company?->company_name_1,
            'name' => $client->name,
            'signature' => $client->signature,
            'headline' => $client->headline,
            'subheadline' => $client->subheadline,
            'is_published' => $client->is_published,
            'is_active' => $client->is_active,
            'analyses_count' => (int) ($client->website_analyses_count ?? $client->websiteAnalyses()->count()),
            'public_url' => route('clients.show', $client),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];
    }

    private function authorizeClientAccess(Request $request, Client $client): void
    {
        if ($request->user()->hasRole('super_admin')) {
            return;
        }

        if ($client->company_id === $request->user()->company_id) {
            return;
        }

        throw new AuthorizationException;
    }
}

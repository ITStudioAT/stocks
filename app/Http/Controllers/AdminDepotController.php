<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Services\PriceRefreshScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminDepotController extends Controller
{
    public function index(): JsonResponse
    {
        $depots = Depot::query()
            ->orderBy('name')
            ->paginate(10)
            ->through(fn (Depot $depot): array => $this->depotPayload($depot));

        return response()->json([
            'depots' => $depots->items(),
            'meta' => [
                'current_page' => $depots->currentPage(),
                'last_page' => $depots->lastPage(),
                'per_page' => $depots->perPage(),
                'total' => $depots->total(),
                'from' => $depots->firstItem(),
                'to' => $depots->lastItem(),
            ],
        ]);
    }

    public function active(PriceRefreshScheduler $priceRefreshScheduler): JsonResponse
    {
        $depot = Depot::query()
            ->where('is_active', true)
            ->first();

        return response()->json([
            'depot' => $depot ? $this->depotPayload($depot) : null,
            'price_refresh_settings' => $priceRefreshScheduler->payload(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedDepotData($request);

        $depot = DB::transaction(function () use ($validated): Depot {
            $shouldActivateDepot = (bool) ($validated['is_active'] ?? false)
                || ! Depot::query()->where('is_active', true)->exists();

            if ($shouldActivateDepot) {
                Depot::query()->update(['is_active' => false]);
            }

            return Depot::create([
                'name' => $validated['name'],
                'account_balance' => $validated['account_balance'],
                'is_active' => $shouldActivateDepot,
            ]);
        });

        return response()->json([
            'message' => 'Depot created.',
            'depot' => $this->depotPayload($depot),
        ], 201);
    }

    public function update(Request $request, Depot $depot): JsonResponse
    {
        $validated = $this->validatedDepotData($request, $depot);

        DB::transaction(function () use ($depot, $validated): void {
            $shouldActivateDepot = (bool) ($validated['is_active'] ?? false);

            if ($shouldActivateDepot) {
                Depot::query()->whereKeyNot($depot->id)->update(['is_active' => false]);
            }

            $depot->update([
                'name' => $validated['name'],
                'account_balance' => $validated['account_balance'],
                'is_active' => $shouldActivateDepot || $depot->is_active,
            ]);
        });

        return response()->json([
            'message' => 'Depot updated.',
            'depot' => $this->depotPayload($depot->refresh()),
        ]);
    }

    public function activate(Depot $depot): JsonResponse
    {
        DB::transaction(function () use ($depot): void {
            Depot::query()->whereKeyNot($depot->id)->update(['is_active' => false]);

            $depot->update([
                'is_active' => true,
            ]);
        });

        return response()->json([
            'message' => 'Depot activated.',
            'depot' => $this->depotPayload($depot->refresh()),
        ]);
    }

    /**
     * @return array{id: int, name: string, account_balance: string, is_active: bool, created_at: ?string, updated_at: ?string}
     */
    private function depotPayload(Depot $depot): array
    {
        return [
            'id' => $depot->id,
            'name' => $depot->name,
            'account_balance' => $depot->account_balance,
            'is_active' => $depot->is_active,
            'created_at' => $depot->created_at?->toIso8601String(),
            'updated_at' => $depot->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{name: string, account_balance: numeric-string, is_active?: bool}
     */
    private function validatedDepotData(Request $request, ?Depot $depot = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Depot::class, 'name')->ignore($depot),
            ],
            'account_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}

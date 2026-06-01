<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminCompanyController extends Controller
{
    public function index(): JsonResponse
    {
        $companies = Company::query()
            ->withCount('clients')
            ->withCount('users')
            ->orderBy('company_name_1')
            ->orderBy('company_name_2')
            ->paginate(10)
            ->through(fn (Company $company): array => $this->companyPayload($company));

        return response()->json([
            'companies' => $companies->items(),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
                'from' => $companies->firstItem(),
                'to' => $companies->lastItem(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return response()->json([
            'companies' => Company::query()
                ->where(function ($query) use ($validated): void {
                    $query
                        ->where('company_name_1', 'like', "%{$validated['search']}%")
                        ->orWhere('company_name_2', 'like', "%{$validated['search']}%");
                })
                ->orderBy('company_name_1')
                ->limit(10)
                ->get(['id', 'company_name_1', 'company_name_2']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = Company::create([
            ...$this->validatedCompanyData($request),
            'is_active' => ! Company::query()->exists(),
        ]);

        return response()->json([
            'message' => 'Company created.',
            'company' => $this->companyPayload($company),
        ], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $company->update($this->validatedCompanyData($request));

        return response()->json([
            'message' => 'Company updated.',
            'company' => $this->companyPayload($company),
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        if ($company->users()->exists() || $company->clients()->exists()) {
            throw ValidationException::withMessages([
                'company' => 'This company still has users or clients and cannot be deleted.',
            ]);
        }

        $company->delete();

        return response()->json([
            'message' => 'Company deleted.',
        ]);
    }

    public function activate(Company $company): JsonResponse
    {
        Company::query()
            ->where('id', '!=', $company->id)
            ->update(['is_active' => false]);

        $company->update(['is_active' => true]);

        return response()->json([
            'message' => 'Company activated.',
            'company' => $this->companyPayload($company),
        ]);
    }

    /**
     * @return array{id: int, company_name_1: string, company_name_2: ?string, street: string, postal_code: string, city: string, country: string, is_active: bool, users_count: int, clients_count: int, can_delete: bool, created_at: ?string, updated_at: ?string}
     */
    private function companyPayload(Company $company): array
    {
        $usersCount = (int) ($company->users_count ?? $company->users()->count());
        $clientsCount = (int) ($company->clients_count ?? $company->clients()->count());

        return [
            'id' => $company->id,
            'company_name_1' => $company->company_name_1,
            'company_name_2' => $company->company_name_2,
            'street' => $company->street,
            'postal_code' => $company->postal_code,
            'city' => $company->city,
            'country' => $company->country,
            'is_active' => $company->is_active,
            'users_count' => $usersCount,
            'clients_count' => $clientsCount,
            'can_delete' => $usersCount === 0 && $clientsCount === 0,
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{company_name_1: string, company_name_2?: ?string, street: string, postal_code: string, city: string, country: string}
     */
    private function validatedCompanyData(Request $request): array
    {
        return $request->validate([
            'company_name_1' => ['required', 'string', 'max:255'],
            'company_name_2' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
        ]);
    }
}

<?php

namespace App\Http\Requests;

use App\Services\EodhdHistoricalDataService;
use Illuminate\Foundation\Http\FormRequest;

class HistoricalPriceRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, int|string>>
     */
    public function rules(): array
    {
        return [
            'row_count' => ['required', 'integer', 'min:1', 'max:'.EodhdHistoricalDataService::MaxAnalysisRowCount],
        ];
    }
}

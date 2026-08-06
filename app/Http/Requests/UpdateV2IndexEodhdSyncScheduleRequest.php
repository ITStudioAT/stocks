<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateV2IndexEodhdSyncScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'times' => ['required_without:realtime', 'array', 'min:1', 'max:8'],
            'times.*' => ['required', 'date_format:H:i', 'distinct'],
            'realtime' => ['required_without:times', 'array'],
            'realtime.trading_interval_minutes' => ['required_with:realtime', 'integer', 'min:1', 'max:1440'],
            'realtime.trading_starts_before_minutes' => ['required_with:realtime', 'integer', 'min:0', 'max:720'],
            'realtime.trading_ends_after_minutes' => ['required_with:realtime', 'integer', 'min:0', 'max:720'],
            'realtime.closed_refresh_enabled' => ['required_with:realtime', 'boolean'],
            'realtime.closed_interval_minutes' => ['required_with:realtime', 'integer', 'min:1', 'max:10080'],
        ];
    }
}

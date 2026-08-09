<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAnalyzeResearchSettingsRequest extends FormRequest
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
            'rows' => ['required', 'integer', 'min:1', 'max:2000'],
            'buy_rules' => ['required', 'array', 'min:1', 'max:20'],
            'buy_rules.*' => ['required', 'array:enabled,from,to'],
            'buy_rules.*.enabled' => ['required', 'boolean'],
            'buy_rules.*.from' => ['required', 'numeric', 'min:-100', 'max:0'],
            'buy_rules.*.to' => ['required', 'numeric', 'min:-100', 'max:0'],
            'buy_step' => ['required', 'numeric', 'gt:0', 'max:100'],
            'sell' => ['required', 'array:from,to,step'],
            'sell.from' => ['required', 'numeric', 'min:0', 'max:100'],
            'sell.to' => ['required', 'numeric', 'min:0', 'max:100'],
            'sell.step' => ['required', 'numeric', 'gt:0', 'max:100'],
            'step' => ['prohibited'],
            'invest' => ['required', 'array:from,to,step'],
            'invest.from' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'invest.to' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'invest.step' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'max_invest' => ['required', 'array:value'],
            'max_invest.value' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('buy_rules', []) as $index => $buyRule) {
                    if (! is_array($buyRule)) {
                        continue;
                    }

                    $from = $buyRule['from'] ?? null;
                    $to = $buyRule['to'] ?? null;

                    if (is_numeric($from) && is_numeric($to) && (float) $from > (float) $to) {
                        $validator->errors()->add(
                            "buy_rules.{$index}.from",
                            'The BUY from value must not be greater than the to value.',
                        );
                    }
                }

                $sellFrom = $this->input('sell.from');
                $sellTo = $this->input('sell.to');

                if (is_numeric($sellFrom) && is_numeric($sellTo) && (float) $sellFrom > (float) $sellTo) {
                    $validator->errors()->add(
                        'sell.from',
                        'The SELL from value must not be greater than the to value.',
                    );
                }

                $investFrom = $this->input('invest.from');
                $investTo = $this->input('invest.to');

                if (is_numeric($investFrom) && is_numeric($investTo) && (float) $investFrom > (float) $investTo) {
                    $validator->errors()->add(
                        'invest.from',
                        'The Invest from value must not be greater than the to value.',
                    );
                }
            },
        ];
    }
}

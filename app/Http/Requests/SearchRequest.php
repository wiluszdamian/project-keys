<?php

namespace App\Http\Requests;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Search;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SearchRequest extends FormRequest
{
    private const RANGES_FIELDS = ['price_min', 'price_max', 'area_min', 'area_max', 'rooms_min', 'rooms_max'];

    public function rules(): array
    {
        return self::rulesFor($this->isMethod('post'));
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $current = $this->route('search');
                $data = array_merge($current instanceof Search ? $current->only(self::RANGES_FIELDS) : [], $validator->safe()->all());

                foreach (self::rangeErrors($data) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * Reguły wspólne dla API i komendy search:create.
     */
    public static function rulesFor(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'deal_type' => [$required, Rule::enum(DealType::class)],
            'property_type' => ['sometimes', Rule::enum(PropertyType::class)],
            'city' => [$required, 'string', 'max:100'],
            'districts' => ['nullable', 'array'],
            'districts.*' => ['string', 'max:100'],
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'integer', 'min:0'],
            'area_min' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'area_max' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'rooms_min' => ['nullable', 'integer', 'min:1', 'max:20'],
            'rooms_max' => ['nullable', 'integer', 'min:1', 'max:20'],
            'portals' => [$required, 'array', 'min:1'],
            'portals.*' => ['distinct', Rule::in(config('scrapers.portals'))],
            'ai_preferences' => ['nullable', 'string', 'max:2000'],
            'notify_channels' => [$required, 'array', 'min:1'],
            'notify_channels.*' => ['distinct', Rule::in(config('notify.channels'))],
            'min_score_to_notify' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Sprawdza, czy minimum nie jest większe od maksimum.
     *
     * @return array<string, string>
     */
    public static function rangeErrors(array $data): array
    {
        $errors = [];

        foreach (['price', 'area', 'rooms'] as $field) {
            $min = $data["{$field}_min"] ?? null;
            $max = $data["{$field}_max"] ?? null;

            if ($min !== null && $max !== null && $min > $max) {
                $errors["{$field}_max"] = "Pole {$field}_max musi być większe lub równe {$field}_min.";
            }
        }

        return $errors;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\DealType;
use App\Http\Controllers\Controller;
use App\Notifications\NotifyGroups;
use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Zbiorcze powiadomienia kanałów: wynajem i sprzedaż osobno.
 */
class NotificationSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->current()]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'weekly_report' => ['sometimes', 'boolean'],
            'alerts_discord' => ['sometimes', 'boolean'],
        ];

        foreach (DealType::cases() as $deal) {
            $key = $deal->value;
            $rules += [
                $key => ['sometimes', 'array'],
                "{$key}.schedule" => ["required_with:{$key}", Rule::in([NotifyGroups::PER_SEARCH, 'every_hours', 'daily'])],
                "{$key}.every_hours" => ['nullable', 'integer', 'min:1', 'max:72', "required_if:{$key}.schedule,every_hours"],
                "{$key}.daily_at" => ['nullable', 'date_format:H:i', "required_if:{$key}.schedule,daily"],
            ];
        }

        $data = $request->validate($rules);
        $settings = [];

        foreach (['weekly_report', 'alerts_discord'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $settings["notify_{$flag}"] = (bool) $data[$flag];
                unset($data[$flag]);
            }
        }

        foreach ($data as $deal => $values) {
            foreach ($values as $field => $value) {
                $settings["notify_{$deal}_{$field}"] = $value;
            }
        }

        AppSettings::save($settings);

        return response()->json(['data' => $this->current()]);
    }

    private function current(): array
    {
        return collect(DealType::cases())->mapWithKeys(fn (DealType $deal) => [$deal->value => [
            'schedule' => config("notify.groups.{$deal->value}.schedule"),
            'every_hours' => (int) config("notify.groups.{$deal->value}.every_hours"),
            'daily_at' => config("notify.groups.{$deal->value}.daily_at"),
            'last_sent_at' => Cache::get("notify:group:{$deal->value}:last_sent"),
        ]])->all() + [
            // Raport rynku: soboty 20:00. Alerty zawsze w panelu, na Discorda opcjonalnie.
            'weekly_report' => (bool) config('notify.weekly_report'),
            'alerts_discord' => (bool) config('notify.alerts_discord'),
        ];
    }
}

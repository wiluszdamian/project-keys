<?php

namespace App\Console\Commands;

use App\Http\Requests\SearchRequest;
use App\Models\Search;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('search:create')]
#[Description('Tworzy nowe wyszukiwanie (pyta o pola)')]
class SearchCreate extends Command
{
    public function handle(): int
    {
        $data = [
            'name' => text('Nazwa', placeholder: 'Kraków wynajem 2 pokoje', required: true),
            'deal_type' => select('Typ transakcji', ['rent' => 'wynajem', 'sale' => 'sprzedaż']),
            'city' => text('Miasto', required: true),
            'districts' => $this->list(text('Dzielnice po przecinku (puste = całe miasto)')),
            'price_min' => $this->number(text('Cena od (PLN, puste = bez limitu)')),
            'price_max' => $this->number(text('Cena do (PLN, puste = bez limitu)')),
            'area_min' => $this->number(text('Metraż od (m2)')),
            'area_max' => $this->number(text('Metraż do (m2)')),
            'rooms_min' => $this->number(text('Pokoje od')),
            'rooms_max' => $this->number(text('Pokoje do')),
            'portals' => multiselect('Portale', config('scrapers.portals'), default: config('scrapers.portals'), required: true),
            'notify_channels' => multiselect('Powiadomienia', config('notify.channels'), required: true),
            'ai_preferences' => text('Preferencje dla AI (np. balkon, blisko metra, bez agencji)') ?: null,
            'min_score_to_notify' => (int) text('Minimalna ocena do powiadomienia (1-10)', default: '6'),
        ];

        $validator = Validator::make($data, SearchRequest::rulesFor(true));
        $validator->after(function ($validator) use ($data) {
            foreach (SearchRequest::rangeErrors($data) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $search = Search::create($validator->validated());

        $this->info("Utworzono wyszukiwanie #{$search->id}: {$search->name}");

        return self::SUCCESS;
    }

    private function number(string $value): ?int
    {
        $value = trim($value);

        return $value === '' ? null : (int) $value;
    }

    private function list(string $value): ?array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value))));

        return $items === [] ? null : $items;
    }
}

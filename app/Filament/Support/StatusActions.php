<?php

namespace App\Filament\Support;

use App\Enums\ListingStatus;
use App\Models\Listing;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * Akcje statusu oferty dla tabeli i strony oferty. Przy "Odrzucona" i "Ulubiona" można dopisać powód:
 * trafia do notatki, a AI bierze notatki pod uwagę przy ocenie kolejnych ofert.
 */
class StatusActions
{
    /**
     * @param  Closure(Listing, ?ListingStatus, ?string): void  $apply
     * @return list<Action>
     */
    public static function make(Closure $apply, bool $markCurrent = false): array
    {
        return [
            ...collect(ListingStatus::cases())->map(function (ListingStatus $status) use ($apply, $markCurrent) {
                $action = Action::make("status_{$status->value}")
                    ->label($status->getLabel())
                    ->color($status->getColor())
                    ->action(fn (Listing $record, array $data = []) => $apply($record, $status, $data['reason'] ?? null));

                if ($markCurrent) {
                    $action->icon(fn (Listing $record) => $record->status === $status ? Heroicon::OutlinedCheck : null);
                }

                if (in_array($status, [ListingStatus::Rejected, ListingStatus::Favorite], true)) {
                    $action
                        ->modalHeading($status === ListingStatus::Rejected ? 'Odrzuć ofertę' : 'Dodaj do ulubionych')
                        ->modalWidth('md')
                        ->modalSubmitActionLabel('Zapisz')
                        ->schema([
                            Textarea::make('reason')
                                ->label($status === ListingStatus::Rejected ? 'Dlaczego odrzucasz? (opcjonalnie)' : 'Co Ci się podoba? (opcjonalnie)')
                                ->placeholder($status === ListingStatus::Rejected ? 'np. za daleko od pracy, parter, ciemna kuchnia' : 'np. duży balkon, blisko parku')
                                ->helperText('Trafi do notatki. AI uczy się z tego przy ocenie kolejnych ofert.')
                                ->rows(3),
                        ]);
                }

                return $action;
            })->all(),
            Action::make('status_clear')
                ->label('Bez statusu')
                ->action(fn (Listing $record) => $apply($record, null, null)),
        ];
    }
}

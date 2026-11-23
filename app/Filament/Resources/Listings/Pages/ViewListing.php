<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Enums\ListingStatus;
use App\Filament\Pages\MortgagePage;
use App\Filament\Resources\Listings\ListingResource;
use App\Models\Listing;
use App\Support\Portals;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewListing extends ViewRecord
{
    protected static string $resource = ListingResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        /** @var Listing $listing */
        $listing = $this->getRecord();

        return collect([$listing->city, $listing->district, $listing->street])->filter()->unique()->implode(' · ') ?: null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Otwórz ogłoszenie')
                ->tooltip(fn (Listing $record) => 'Ogłoszenie na portalu '.Portals::label($record->portal))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (Listing $record) => $record->url, shouldOpenInNewTab: true),
            ActionGroup::make([
                ...collect(ListingStatus::cases())->map(fn (ListingStatus $status) => Action::make("status_{$status->value}")
                    ->label($status->getLabel())
                    ->color($status->getColor())
                    ->icon(fn (Listing $record) => $record->status === $status ? Heroicon::OutlinedCheck : null)
                    ->action(fn (Listing $record) => $this->setStatus($record, $status))),
                Action::make('status_clear')
                    ->label('Bez statusu')
                    ->action(fn (Listing $record) => $this->setStatus($record, null)),
            ])
                ->label(fn () => $this->getRecord()->status?->getLabel() ?? 'Ustaw status')
                ->icon(Heroicon::OutlinedTag)
                ->color('gray')
                ->button(),
            Action::make('note')
                ->label('Notatka')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->fillForm(fn (Listing $record) => ['note' => $record->note])
                ->schema([
                    Textarea::make('note')
                        ->hiddenLabel()
                        ->placeholder('np. dzwoniłem, oglądanie w sobotę 12:00')
                        ->rows(5),
                ])
                ->modalHeading('Notatka')
                ->modalSubmitActionLabel('Zapisz')
                ->action(function (Listing $record, array $data) {
                    $record->update(['note' => $data['note']]);
                    Notification::make()->success()->title('Notatka zapisana')->send();
                }),
            Action::make('mortgage')
                ->label('Policz kredyt')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->url(fn (Listing $record) => MortgagePage::getUrl(['listing' => $record->id]))
                ->visible(fn (Listing $record) => $record->deal_type->value === 'sale' && $record->price),
        ];
    }

    private function setStatus(Listing $listing, ?ListingStatus $status): void
    {
        $listing->update(['status' => $status]);
        $this->refreshFormData(['status']);
        Notification::make()->success()->title($status ? 'Status: '.$status->getLabel() : 'Status usunięty')->send();
    }
}

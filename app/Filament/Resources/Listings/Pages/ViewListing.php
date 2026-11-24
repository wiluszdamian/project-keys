<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Enums\ListingStatus;
use App\Filament\Pages\MortgagePage;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Support\StatusActions;
use App\Jobs\SuggestViewingQuestionsJob;
use App\Models\Listing;
use App\Services\ListingScorer;
use App\Support\Portals;
use App\Support\ViewingCalendar;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
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
            ActionGroup::make(StatusActions::make(fn (Listing $record, ?ListingStatus $status, ?string $reason) => $this->setStatus($record, $status, $reason), markCurrent: true))
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
            ActionGroup::make([
                Action::make('viewing_schedule')
                    ->label(fn (Listing $record) => $record->viewing_at ? 'Zmień termin' : 'Umów oglądanie')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->modalHeading('Termin oglądania')
                    ->modalDescription('Status zmieni się na „Oglądanie”. Po zapisaniu pobierze się plik do kalendarza z przypomnieniem godzinę wcześniej.')
                    ->modalWidth('md')
                    ->modalSubmitActionLabel('Zapisz i pobierz do kalendarza')
                    ->fillForm(fn (Listing $record) => ['viewing_at' => $record->viewing_at])
                    ->schema([
                        DateTimePicker::make('viewing_at')
                            ->label('Kiedy')
                            ->seconds(false)
                            ->minutesStep(5)
                            ->required(),
                    ])
                    ->action(function (Listing $record, array $data) {
                        $record->forceFill(['viewing_at' => $data['viewing_at'], 'viewing_reminded_at' => null])->save();

                        if ($record->status !== ListingStatus::Viewing) {
                            $record->applyStatus(ListingStatus::Viewing);
                        }

                        $this->refreshFormData(['status', 'note', 'viewing_at']);
                        Notification::make()->success()->title('Oglądanie: '.$record->viewing_at->format('d.m.Y H:i'))->send();

                        return response()->streamDownload(fn () => print (ViewingCalendar::ics($record)), ViewingCalendar::filename($record), ['Content-Type' => 'text/calendar; charset=utf-8']);
                    }),
                Action::make('viewing_ics')
                    ->label('Dodaj do kalendarza (.ics)')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (Listing $record) => $record->viewing_at !== null)
                    ->action(fn (Listing $record) => response()->streamDownload(fn () => print (ViewingCalendar::ics($record)), ViewingCalendar::filename($record), ['Content-Type' => 'text/calendar; charset=utf-8'])),
                Action::make('viewing_ai')
                    ->label(fn (Listing $record) => $record->viewing_questions_at ? 'Nowe pytania od AI' : 'Pytania od AI')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->visible(fn () => ListingScorer::isEnabled())
                    ->action(function (Listing $record) {
                        SuggestViewingQuestionsJob::dispatch($record);
                        Notification::make()->info()->title('AI przygotowuje pytania')->body('Zwykle trwa to do minuty. Dam znać w dzwoneczku.')->send();
                    }),
                Action::make('viewing_done')
                    ->label('Po oglądaniu')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->modalHeading('Jak było na oglądaniu?')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Zapisz')
                    ->schema([
                        Textarea::make('impressions')
                            ->label('Wrażenia i odpowiedzi')
                            ->placeholder('np. jasne, ale głośno od ulicy; kaucja 2 czynsze; właściciel zgadza się na kota')
                            ->helperText('Trafi do notatki. AI uczy się z tego przy ocenie kolejnych ofert.')
                            ->rows(5)
                            ->required(),
                        Radio::make('decision')
                            ->label('Co dalej?')
                            ->options([
                                'keep' => 'Zostaw status',
                                ListingStatus::Favorite->value => 'Ulubiona (biorę pod uwagę)',
                                ListingStatus::Contacted->value => 'Kontakt (negocjuję, czekam na odpowiedź)',
                                ListingStatus::Rejected->value => 'Odrzucona',
                            ])
                            ->default('keep')
                            ->required(),
                    ])
                    ->action(function (Listing $record, array $data) {
                        $date = ($record->viewing_at && $record->viewing_at->isPast() ? $record->viewing_at : now())->format('d.m.Y');
                        $note = trim(trim((string) $record->note)."\nPo oglądaniu {$date}: ".trim($data['impressions']));
                        $status = $data['decision'] === 'keep' ? $record->status : ListingStatus::from($data['decision']);

                        $record->update(['note' => $note, 'status' => $status]);
                        $this->refreshFormData(['status', 'note']);
                        Notification::make()->success()->title('Zapisane w notatce')->send();
                    }),
                Action::make('viewing_cancel')
                    ->label('Odwołaj termin')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Listing $record) => $record->viewing_at !== null)
                    ->action(function (Listing $record) {
                        $record->forceFill(['viewing_at' => null, 'viewing_reminded_at' => null])->save();
                        $this->refreshFormData(['viewing_at']);
                    }),
            ])
                ->label(fn () => $this->getRecord()->viewing_at ? 'Oglądanie '.$this->getRecord()->viewing_at->format('d.m H:i') : 'Oglądanie')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->button(),
            Action::make('mortgage')
                ->label('Policz kredyt')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->url(fn (Listing $record) => MortgagePage::getUrl(['listing' => $record->id]))
                ->visible(fn (Listing $record) => $record->deal_type->value === 'sale' && $record->price),
        ];
    }

    private function setStatus(Listing $listing, ?ListingStatus $status, ?string $reason = null): void
    {
        $listing->applyStatus($status, $reason);
        $this->refreshFormData(['status', 'note']);
        Notification::make()->success()->title($status ? 'Status: '.$status->getLabel() : 'Status usunięty')->send();
    }
}

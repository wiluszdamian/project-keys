<?php

namespace App\Notifications;

use App\Filament\Support\Format;
use App\Models\Listing;
use App\Models\MarketReport;
use App\Models\Search;
use App\Models\User;
use App\Services\DealFinder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Powiadomienia w panelu (dzwoneczek). Idą od razu, niezależnie od tego, jak często wysyłamy na Discorda.
 */
class PanelNotifier
{
    /**
     * Nowe dobre oferty z jednego przebiegu wyszukiwania: jedno powiadomienie z najlepszą ofertą.
     *
     * @param  Collection<int, Listing>  $listings  z atrybutem score (może być null)
     */
    public function newListings(Search $search, Collection $listings): void
    {
        if ($listings->isEmpty()) {
            return;
        }

        $best = $listings->sortByDesc(fn (Listing $listing) => $listing->getAttribute('score') ?? -1)->first();
        $score = $best->getAttribute('score');
        $count = $listings->count();

        $url = $count === 1
            ? route('filament.admin.resources.listings.view', $best)
            : route('filament.admin.resources.listings.index', ['filters' => ['search' => ['value' => $search->id]]]);

        $this->send(
            Notification::make()
                ->title($count === 1 ? "{$search->name}: nowa oferta" : "{$search->name}: {$count} nowych ofert")
                ->body(($count > 1 ? 'Najlepsza: ' : '').implode(' · ', array_filter([
                    $score !== null ? "{$score}/10" : null,
                    Str::limit($best->title, 60),
                    Format::pln($best->price),
                    Format::area($best->area),
                ])))
                ->icon('heroicon-o-home-modern')
                ->iconColor(Format::scoreColor($score))
                ->actions([
                    Action::make('open')->label($count === 1 ? 'Zobacz ofertę' : 'Zobacz oferty')->url($url)->markAsRead(),
                ]),
        );
    }

    public function priceDrop(Search $search, Listing $listing, int $oldPrice, int $newPrice): void
    {
        $this->send(
            Notification::make()
                ->title('Obniżka ceny: '.Str::limit($listing->title, 60))
                ->body(Format::pln($oldPrice).' → '.Format::pln($newPrice)." · {$search->name}")
                ->icon('heroicon-o-arrow-trending-down')
                ->iconColor('success')
                ->actions([
                    Action::make('open')->label('Zobacz ofertę')->url(route('filament.admin.resources.listings.view', $listing))->markAsRead(),
                ]),
        );
    }

    public function deal(Listing $listing): void
    {
        $this->send(
            Notification::make()
                ->title('Okazja: '.Str::limit($listing->title, 60))
                ->body(Str::ucfirst((string) DealFinder::summary($listing->deal_reasons)))
                ->icon('heroicon-o-fire')
                ->iconColor('success')
                ->actions([
                    Action::make('open')->label('Zobacz ofertę')->url(route('filament.admin.resources.listings.view', $listing))->markAsRead(),
                ]),
        );
    }

    /**
     * Oferta z Twoim statusem (ulubiona, kontakt, oglądanie) zniknęła ze wszystkich portali.
     */
    public function listingGone(Listing $listing): void
    {
        $this->send(
            Notification::make()
                ->title('Oferta zdjęta: '.Str::limit($listing->title, 60))
                ->body(implode(' · ', array_filter([
                    $listing->status?->getLabel(),
                    Format::pln($listing->price),
                    $listing->inactive_reason,
                ])))
                ->icon('heroicon-o-archive-box-x-mark')
                ->iconColor('warning')
                ->actions([
                    Action::make('open')->label('Zobacz ofertę')->url(route('filament.admin.resources.listings.view', $listing))->markAsRead(),
                ]),
        );
    }

    public function viewingQuestions(Listing $listing, int $count): void
    {
        $this->send(
            Notification::make()
                ->title('Pytania na oglądanie: '.Str::limit($listing->title, 60))
                ->body($count > 0 ? "AI zaproponowało {$count} pytań do tej oferty." : 'AI nie znalazło dodatkowych pytań, zostaje lista z panelu.')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->iconColor('info')
                ->actions([
                    Action::make('open')->label('Zobacz pytania')->url(route('filament.admin.resources.listings.view', $listing))->markAsRead(),
                ]),
        );
    }

    public function viewingReminder(Listing $listing): void
    {
        $this->send(
            Notification::make()
                ->title('Oglądanie o '.$listing->viewing_at->format('H:i').': '.Str::limit($listing->title, 50))
                ->body(implode(' · ', array_filter([
                    $listing->viewing_at->isToday() ? 'dzisiaj' : $listing->viewing_at->format('d.m.Y'),
                    collect([$listing->street, $listing->district, $listing->city])->filter()->unique()->implode(', ') ?: null,
                    Format::pln($listing->price),
                ])))
                ->icon('heroicon-o-calendar-days')
                ->iconColor('success')
                ->actions([
                    Action::make('open')->label('Pytania i szczegóły')->url(route('filament.admin.resources.listings.view', $listing))->markAsRead(),
                ]),
        );
    }

    public function deals(int $count): void
    {
        $this->send(
            Notification::make()
                ->title("Okazje: {$count}")
                ->body('Oferty tańsze niż transakcje albo rynek, albo po dużej obniżce.')
                ->icon('heroicon-o-fire')
                ->iconColor('success')
                ->actions([
                    Action::make('open')->label('Zobacz okazje')->url(route('filament.admin.resources.listings.index', ['tab' => 'deals']))->markAsRead(),
                ]),
        );
    }

    public function marketReport(MarketReport $report): void
    {
        $totals = $report->data['totals'];

        $this->send(
            Notification::make()
                ->title("Raport rynku: {$report->deal_type->getLabel()}, {$report->periodLabel()}")
                ->body(implode(' · ', array_filter([
                    "{$totals['active']} ofert",
                    "{$totals['new']} nowych",
                    $totals['median_price'] !== null ? 'mediana '.Format::pln($totals['median_price']) : null,
                ])))
                ->icon('heroicon-o-chart-bar')
                ->iconColor('info')
                ->actions([
                    Action::make('open')->label('Zobacz raport')->url(route('filament.admin.pages.market-report', ['report' => $report->id]))->markAsRead(),
                ]),
        );
    }

    public function alert(string $title, string $body): void
    {
        $this->send(
            Notification::make()
                ->title($title)
                ->body($body)
                ->danger()
                ->actions([
                    Action::make('logs')->label('Zobacz logi')->url(route('filament.admin.pages.logs'))->markAsRead(),
                ]),
        );
    }

    /**
     * Zapis od razu (notifyNow). Domyślnie Filament kolejkuje to w "default", a ta kolejka czeka za pobieraniem portali.
     */
    private function send(Notification $notification): void
    {
        foreach (User::all() as $user) {
            $user->notifyNow($notification->toDatabase());
        }
    }
}

<?php

namespace App\Notifications;

use App\Filament\Support\Format;
use App\Models\Listing;
use App\Models\Search;
use App\Models\User;
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

    public function alert(string $title, string $body): void
    {
        $this->send(Notification::make()->title($title)->body($body)->danger());
    }

    private function send(Notification $notification): void
    {
        $users = User::all();

        if ($users->isNotEmpty()) {
            $notification->sendToDatabase($users);
        }
    }
}

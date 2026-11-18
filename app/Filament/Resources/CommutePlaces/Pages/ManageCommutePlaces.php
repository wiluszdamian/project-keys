<?php

namespace App\Filament\Resources\CommutePlaces\Pages;

use App\Filament\Resources\CommutePlaces\CommutePlaceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommutePlaces extends ManageRecords
{
    protected static string $resource = CommutePlaceResource::class;

    protected ?string $subheading = 'Z każdej oferty liczę dojazd autem i rowerem na naszym serwerze. Po zmianie miejsca dojazdy przeliczą się w tle.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Dodaj miejsce'),
        ];
    }
}

<?php

namespace App\Filament\Resources\ProductShops\Pages;

use App\Filament\Resources\ProductShops\ProductShopResource;
use App\Jobs\SyncProductShopJob;
use App\Models\ProductShop;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProductShops extends ManageRecords
{
    protected static string $resource = ProductShopResource::class;

    public function getSubheading(): ?string
    {
        return 'Tu monitor cen szuka produktów za darmo: czyta mapy stron (sitemap), które sklepy publikują dla robotów. Wyszukiwarek sklepów nie używamy, bo większość sklepów tego zabrania.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Dodaj sklep')
                ->after(fn (ProductShop $record) => SyncProductShopJob::dispatch($record)),
        ];
    }
}

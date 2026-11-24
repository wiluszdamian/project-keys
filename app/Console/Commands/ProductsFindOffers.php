<?php

namespace App\Console\Commands;

use App\Jobs\FindProductOffersJob;
use App\Models\Product;
use App\Services\Products\ProductFinder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('products:find-offers {id? : jeden produkt, od razu z wynikiem} {--due : wszystkie aktywne, których dawno nie szukaliśmy (w kolejce)}')]
#[Description('Monitor cen: szuka produktu w innych sklepach (mapy stron sklepów, AI, opcjonalnie Brave)')]
class ProductsFindOffers extends Command
{
    public function handle(ProductFinder $finder): int
    {
        if (! ProductFinder::isConfigured()) {
            $this->warn('Nie ma gdzie szukać: dodaj sklepy (Zakupy → Sklepy), włącz AI albo ustaw BRAVE_SEARCH_API_KEY.');

            return $this->option('due') ? self::SUCCESS : self::FAILURE;
        }

        if ($id = $this->argument('id')) {
            $result = $finder->find(Product::findOrFail($id));
            $this->info("Wyniki: {$result['candidates']}, dodane: {$result['added']}, odrzucone: {$result['rejected']}, błędy: {$result['errors']}.");

            foreach ($result['offers'] as $offer) {
                $this->line(($offer['same'] ? '  + ' : '  - ').$offer['url'].': '.($offer['price'] !== null ? number_format($offer['price'], 2, ',', ' ').' zł' : '?').($offer['reason'] ? " ({$offer['reason']})" : ''));
            }

            return self::SUCCESS;
        }

        $days = (int) config('products.search.every_days');

        if (! $this->option('due') || $days < 1) {
            $this->warn('Podaj id produktu albo --due (a PRODUCTS_SEARCH_EVERY_DAYS > 0).');

            return self::SUCCESS;
        }

        $products = Product::where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('offers_searched_at')->orWhere('offers_searched_at', '<=', now()->subDays($days)))
            ->get();

        $products->each(fn (Product $product) => FindProductOffersJob::dispatch($product));
        $this->info("W kolejce: {$products->count()}.");

        return self::SUCCESS;
    }
}

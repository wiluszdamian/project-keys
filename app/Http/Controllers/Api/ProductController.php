<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\FindProductOffersJob;
use App\Jobs\ProductAdviceJob;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductPrice;
use App\Services\Products\ProductFinder;
use App\Services\Products\ProductReader;
use App\Services\Products\ProductTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Monitor cen: produkty, sklepy z ofertami, historia cen, szukanie w innych sklepach i ocena AI.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('offers')->orderByDesc('updated_at');

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return response()->json(['data' => $query->get()->map(fn (Product $product) => $this->present($product))]);
    }

    public function store(Request $request, ProductTracker $tracker): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:2000']] + $this->rules());

        try {
            $product = $tracker->add($data['url'], collect($data)->except('url')->all());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->present($product->load('offers'), withHistory: true)], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->present($product->load('offers'), withHistory: true)]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product->update($request->validate(['name' => ['sometimes', 'string', 'max:255']] + $this->rules()));

        return response()->json(['data' => $this->present($product->fresh('offers'))]);
    }

    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }

    /**
     * Sprawdza ceny od razu i zwraca wynik ze zdarzeniami (obniżka, cel, dostępność).
     */
    public function check(Product $product, ProductTracker $tracker): JsonResponse
    {
        $events = $tracker->check($product);

        return response()->json(['data' => $this->present($product->fresh('offers')), 'events' => $events]);
    }

    public function prices(Product $product): JsonResponse
    {
        $prices = ProductPrice::query()
            ->whereIn('product_offer_id', $product->offers()->select('id'))
            ->with('offer:id,shop')
            ->orderBy('checked_at')
            ->get()
            ->map(fn (ProductPrice $price) => [
                'offer_id' => $price->product_offer_id,
                'shop' => $price->offer?->shop,
                'price' => $price->price,
                'available' => $price->available,
                'checked_at' => $price->checked_at->toIso8601String(),
            ]);

        return response()->json(['data' => $prices]);
    }

    public function addOffer(Request $request, Product $product, ProductTracker $tracker): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:2000']]);
        $before = $tracker->snapshot($product);

        try {
            $offer = $tracker->addOffer($product, $data['url']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }

        $tracker->refresh($product, $before);

        return response()->json(['data' => $this->presentOffer($offer->fresh())], 201);
    }

    public function destroyOffer(Product $product, ProductOffer $offer, ProductTracker $tracker): Response
    {
        abort_unless($offer->product_id === $product->id, 404);

        $offer->delete();
        $tracker->refresh($product, $tracker->snapshot($product));

        return response()->noContent();
    }

    /**
     * Szukanie w innych sklepach w tle (202). Wynik przyjdzie w panelu, oferty w GET /products/{id}.
     */
    public function findOffers(Product $product): JsonResponse
    {
        abort_unless(ProductFinder::isConfigured(), 422, 'Brak klucza Brave Search API (BRAVE_SEARCH_API_KEY).');

        FindProductOffersJob::dispatch($product, report: true);

        return response()->json(['message' => 'Szukam w innych sklepach.'], 202);
    }

    public function advice(Product $product): JsonResponse
    {
        abort_unless(ProductReader::aiEnabled(), 422, 'AI jest wyłączone.');
        abort_if($product->best_price === null, 422, 'Produkt nie ma ceny do oceny.');

        ProductAdviceJob::dispatch($product);

        return response()->json(['message' => 'AI ocenia cenę.', 'previous' => $product->ai_advice], 202);
    }

    private function rules(): array
    {
        return [
            'target_price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'notify_drops' => ['sometimes', 'boolean'],
            'min_drop_percent' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'notify_rises' => ['sometimes', 'boolean'],
            'notify_stock' => ['sometimes', 'boolean'],
            'check_every_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function present(Product $product, bool $withHistory = false): array
    {
        return $product->only([
            'id', 'name', 'image_url', 'brand', 'gtin', 'mpn', 'target_price', 'notify_drops', 'min_drop_percent', 'notify_rises',
            'notify_stock', 'check_every_hours', 'is_active', 'note', 'best_price', 'best_offer_id', 'initial_price', 'lowest_price',
            'highest_price', 'ai_advice',
        ]) + [
            'change_percent' => $product->changePercent(),
            'target_reached' => $product->targetReached(),
            'lowest_price_at' => $product->lowest_price_at?->toIso8601String(),
            'last_checked_at' => $product->last_checked_at?->toIso8601String(),
            'offers_searched_at' => $product->offers_searched_at?->toIso8601String(),
            'ai_advice_at' => $product->ai_advice_at?->toIso8601String(),
            'offers' => $product->offers->map(fn (ProductOffer $offer) => $this->presentOffer($offer))->values(),
        ] + ($withHistory ? ['prices_url' => url("/api/products/{$product->id}/prices")] : []);
    }

    private function presentOffer(ProductOffer $offer): array
    {
        return $offer->only([
            'id', 'url', 'shop', 'title', 'price', 'regular_price', 'lowest_30d_price', 'currency', 'available', 'source',
            'is_primary', 'is_active', 'extracted_by', 'match_note', 'last_error', 'failures',
        ]) + ['last_checked_at' => $offer->last_checked_at?->toIso8601String()];
    }
}

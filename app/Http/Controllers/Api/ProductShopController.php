<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProductShopJob;
use App\Models\ProductShop;
use App\Services\Products\ProductUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Sklepy do darmowego szukania produktów (mapy stron).
 */
class ProductShopController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ProductShop::orderBy('domain')->get()->map($this->present(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['domain' => $this->domain((string) $request->input('domain'))]);
        $shop = ProductShop::create($request->validate($this->rules(null)));
        SyncProductShopJob::dispatch($shop);

        return response()->json(['data' => $this->present($shop->fresh())], 201);
    }

    public function update(Request $request, ProductShop $productShop): JsonResponse
    {
        if ($request->has('domain')) {
            $request->merge(['domain' => $this->domain((string) $request->input('domain'))]);
        }

        $productShop->update($request->validate($this->rules($productShop)));

        return response()->json(['data' => $this->present($productShop->fresh())]);
    }

    public function destroy(ProductShop $productShop): Response
    {
        $productShop->delete();

        return response()->noContent();
    }

    public function sync(ProductShop $productShop): JsonResponse
    {
        SyncProductShopJob::dispatch($productShop);

        return response()->json(['message' => 'Pobieram mapę strony.'], 202);
    }

    private function rules(?ProductShop $shop): array
    {
        $required = $shop ? 'sometimes' : 'required';

        return [
            'domain' => [$required, 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/', Rule::unique('product_shops', 'domain')->ignore($shop?->id)],
            'name' => ['nullable', 'string', 'max:100'],
            'sitemap_url' => ['nullable', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function domain(string $value): string
    {
        return ProductUrl::shop(str_contains($value, '://') ? $value : 'https://'.trim($value));
    }

    private function present(ProductShop $shop): array
    {
        return $shop->only(['id', 'domain', 'name', 'sitemap_url', 'is_active', 'source', 'status', 'last_error', 'urls_count'])
            + ['synced_at' => $shop->synced_at?->toIso8601String()];
    }
}

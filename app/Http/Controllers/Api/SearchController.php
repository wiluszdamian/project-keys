<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\ListingResource;
use App\Http\Resources\SearchResource;
use App\Models\Listing;
use App\Models\Search;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SearchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $searches = Search::query()
            ->withCount('listings')
            ->with('latestScrapeRun')
            ->orderBy('id')
            ->get();

        return SearchResource::collection($searches);
    }

    public function store(SearchRequest $request): SearchResource
    {
        $search = Search::create($request->validated());

        return new SearchResource($search->refresh());
    }

    public function show(Search $search): SearchResource
    {
        return new SearchResource($search->loadCount('listings')->load('latestScrapeRun'));
    }

    public function update(SearchRequest $request, Search $search): SearchResource
    {
        $search->update($request->validated());

        return new SearchResource($search);
    }

    public function destroy(Search $search): Response
    {
        $search->delete();

        return response()->noContent();
    }

    public function pause(Search $search): SearchResource
    {
        $search->update(['is_active' => false]);

        return new SearchResource($search);
    }

    public function resume(Search $search): SearchResource
    {
        $search->update(['is_active' => true]);

        return new SearchResource($search);
    }

    /**
     * Oferty dopasowane do wyszukiwania, z oceną AI dla tego wyszukiwania.
     */
    public function listings(Request $request, Search $search): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'sort' => ['sometimes', 'in:newest,score'],
            'min_score' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $listings = Listing::query()
            ->select('listings.*', 'search_listing.matched_at')
            ->join('search_listing', 'search_listing.listing_id', '=', 'listings.id')
            ->where('search_listing.search_id', $search->id)
            ->leftJoin('listing_analyses', function ($join) use ($search) {
                $join->on('listing_analyses.listing_id', '=', 'listings.id')
                    ->where('listing_analyses.search_id', $search->id);
            })
            ->when(isset($validated['min_score']), fn ($q) => $q->where('listing_analyses.score', '>=', $validated['min_score']))
            ->when(
                ($validated['sort'] ?? 'newest') === 'score',
                fn ($q) => $q->orderByRaw('listing_analyses.score desc nulls last'),
            )
            ->orderByDesc('search_listing.matched_at')
            ->orderByDesc('listings.id')
            ->with(['analyses' => fn ($q) => $q->where('search_id', $search->id)])
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return ListingResource::collection($listings);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScrapeRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScrapeRunResource;
use App\Models\ScrapeRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ScrapeRunController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'portal' => ['sometimes', Rule::in(config('scrapers.portals'))],
            'status' => ['sometimes', Rule::enum(ScrapeRunStatus::class)],
            'search_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $runs = ScrapeRun::query()
            ->when(isset($validated['portal']), fn ($q) => $q->where('portal', $validated['portal']))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['search_id']), fn ($q) => $q->where('search_id', $validated['search_id']))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

        return ScrapeRunResource::collection($runs);
    }
}

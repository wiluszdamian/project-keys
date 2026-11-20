<?php

namespace App\Http\Controllers\Api;

use App\Enums\DealType;
use App\Http\Controllers\Controller;
use App\Models\MarketReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tygodniowe raporty rynku (powstają w soboty o 20:00).
 */
class MarketReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'deal_type' => ['nullable', Rule::enum(DealType::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:52'],
        ]);

        $reports = MarketReport::query()
            ->when($request->input('deal_type'), fn ($query, $deal) => $query->where('deal_type', $deal))
            ->latest('period_end')
            ->limit((int) $request->input('limit', 4))
            ->get()
            ->map(fn (MarketReport $report) => [
                'id' => $report->id,
                'deal_type' => $report->deal_type->value,
                'period_start' => $report->period_start->toIso8601String(),
                'period_end' => $report->period_end->toIso8601String(),
                'sent_at' => $report->sent_at?->toIso8601String(),
                'data' => $report->data,
            ]);

        return response()->json(['data' => $reports]);
    }
}

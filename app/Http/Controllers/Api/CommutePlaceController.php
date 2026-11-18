<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommutePlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Miejsca dojazdu (np. praca). Bez współrzędnych szukamy ich po adresie w OpenStreetMap.
 */
class CommutePlaceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CommutePlace::orderBy('id')->get()->map($this->present(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $place = CommutePlace::create($request->validate($this->rules(true)));

        return response()->json(['data' => $this->present($place)], 201);
    }

    public function show(CommutePlace $commutePlace): JsonResponse
    {
        return response()->json(['data' => $this->present($commutePlace)]);
    }

    public function update(Request $request, CommutePlace $commutePlace): JsonResponse
    {
        $commutePlace->update($request->validate($this->rules(false)));

        return response()->json(['data' => $this->present($commutePlace->fresh())]);
    }

    public function destroy(CommutePlace $commutePlace): Response
    {
        $commutePlace->delete();

        return response()->noContent();
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'address' => [$required, 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'max_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function present(CommutePlace $place): array
    {
        return $place->only(['id', 'name', 'address', 'lat', 'lng', 'max_minutes', 'is_active']) + [
            'located' => $place->lat !== null,
            'commutes_count' => $place->commutes()->count(),
        ];
    }
}

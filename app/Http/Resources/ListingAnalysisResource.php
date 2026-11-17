<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingAnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'search_id' => $this->search_id,
            'model' => $this->model,
            'score' => $this->score,
            'summary' => $this->summary,
            'pros' => $this->pros,
            'red_flags' => $this->red_flags,
            'created_at' => $this->created_at,
        ];
    }
}

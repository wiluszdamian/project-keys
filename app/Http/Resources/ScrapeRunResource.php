<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScrapeRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'search_id' => $this->search_id,
            'portal' => $this->portal,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'pages_fetched' => $this->pages_fetched,
            'found_count' => $this->found_count,
            'new_count' => $this->new_count,
            'error_message' => $this->error_message,
        ];
    }
}

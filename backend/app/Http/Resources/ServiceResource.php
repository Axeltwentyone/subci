<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Service */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->slug,
            'mono' => $this->mono,
            'name' => $this->name,
            'color' => $this->color,
            'fg' => $this->fg,
            'category' => $this->category,
            'meta' => $this->meta,
            'description' => $this->description,
            // Prix et places issus des offres en ligne (Availability::annotate).
            'price' => $this->avail_price ?? $this->price,
            'fullPrice' => $this->full_price,
            'seats' => $this->avail_seats ?? $this->seats,
            'free' => $this->avail_free ?? 0,
            'groupFree' => $this->avail_group_free ?? 0,
            'activation' => $this->activation_minutes,
            'popular' => $this->is_popular,
        ];
    }
}

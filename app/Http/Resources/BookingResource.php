<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
class BookingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Booking $booking */
        $booking = $this->resource;
        $bookable = $booking->resource;
        $tz = $bookable?->timezone ?? 'UTC';

        return [
            'id' => $booking->id,
            'resource' => [
                'id' => $bookable?->id,
                'name' => $bookable?->name,
                'slug' => $bookable?->slug,
                'timezone' => $tz,
            ],
            'customer_name' => $booking->customer_name,
            'customer_email' => $booking->customer_email,
            'starts_at' => $booking->starts_at->copy()->timezone($tz)->toIso8601String(),
            'ends_at' => $booking->ends_at->copy()->timezone($tz)->toIso8601String(),
            'status' => $booking->status,
            'cancelled_at' => $booking->cancelled_at?->copy()->timezone($tz)->toIso8601String(),
            'created_at' => $booking->created_at?->toIso8601String(),
        ];
    }
}

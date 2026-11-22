<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Resource;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookingService) {}

    public function availability(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $resource->timezone);

        return response()->json(
            $this->bookingService->dailyAvailability($resource, $date),
        );
    }

    public function store(StoreBookingRequest $request, Resource $resource): JsonResponse
    {
        $result = $this->bookingService->reserve(
            resource: $resource,
            customerName: $request->validated('customer_name'),
            customerEmail: $request->validated('customer_email'),
            startsAt: CarbonImmutable::parse($request->validated('starts_at')),
            endsAt: CarbonImmutable::parse($request->validated('ends_at')),
            idempotencyKey: $request->idempotencyKey(),
        );

        $status = $result['replayed'] ? 200 : 201;

        return (new BookingResource($result['booking']))
            ->additional(['replayed' => $result['replayed']])
            ->response()
            ->setStatusCode($status);
    }

    public function cancel(Booking $booking): BookingResource
    {
        return new BookingResource($this->bookingService->cancel($booking));
    }
}

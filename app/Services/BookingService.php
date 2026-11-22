<?php

namespace App\Services;

use App\Exceptions\BookingConflictException;
use App\Exceptions\InactiveResourceException;
use App\Jobs\SendBookingConfirmationJob;
use App\Models\Booking;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BookingService
{
    /**
     * Create a booking while preventing double-bookings via an atomic resource lock
     * and row-level pessimistic locking inside a database transaction.
     *
     * @return array{booking: Booking, replayed: bool}
     */
    public function reserve(
        Resource $resource,
        string $customerName,
        string $customerEmail,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?string $idempotencyKey = null,
    ): array {
        $startUtc = CarbonImmutable::instance($startsAt)->utc();
        $endUtc = CarbonImmutable::instance($endsAt)->utc();

        if ($idempotencyKey !== null) {
            $existing = Booking::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return ['booking' => $existing->load('resource'), 'replayed' => true];
            }
        }

        // Guard across multi-worker queues/containers while InnoDB lockForUpdate serializes DB writes.
        $lock = Cache::lock("resource-booking:{$resource->id}", 10);

        return $lock->block(5, function () use (
            $resource,
            $customerName,
            $customerEmail,
            $startUtc,
            $endUtc,
            $idempotencyKey,
        ): array {
            return DB::transaction(function () use (
                $resource,
                $customerName,
                $customerEmail,
                $startUtc,
                $endUtc,
                $idempotencyKey,
            ): array {
                $lockedResource = Resource::query()
                    ->whereKey($resource->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedResource->is_active) {
                    throw new InactiveResourceException;
                }

                if ($idempotencyKey !== null) {
                    $existing = Booking::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        return ['booking' => $existing->load('resource'), 'replayed' => true];
                    }
                }

                $hasOverlap = Booking::query()
                    ->where('resource_id', $lockedResource->id)
                    ->confirmed()
                    ->overlapping($startUtc, $endUtc)
                    ->exists();

                if ($hasOverlap) {
                    throw new BookingConflictException;
                }

                $booking = Booking::query()->create([
                    'resource_id' => $lockedResource->id,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'starts_at' => $startUtc,
                    'ends_at' => $endUtc,
                    'status' => Booking::STATUS_CONFIRMED,
                    'idempotency_key' => $idempotencyKey,
                ]);

                SendBookingConfirmationJob::dispatch($booking->id)->afterCommit();

                return ['booking' => $booking->load('resource'), 'replayed' => false];
            });
        });
    }

    public function cancel(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $locked = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === Booking::STATUS_CANCELLED) {
                return $locked->load('resource');
            }

            $locked->update([
                'status' => Booking::STATUS_CANCELLED,
                'cancelled_at' => now()->utc(),
            ]);

            return $locked->load('resource');
        });
    }

    /**
     * Return confirmed bookings and free 60-minute slots for a given local date (08:00-22:00).
     *
     * @return array{date: string, timezone: string, booked: list<array{starts_at: string, ends_at: string}>, available_slots: list<array{starts_at: string, ends_at: string}>}
     */
    public function dailyAvailability(Resource $resource, CarbonImmutable $date): array
    {
        $tz = $resource->timezone;
        $dayStartLocal = $date->setTimezone($tz)->setTime(8, 0);
        $dayEndLocal = $date->setTimezone($tz)->setTime(22, 0);

        $bookings = Booking::query()
            ->where('resource_id', $resource->id)
            ->confirmed()
            ->overlapping($dayStartLocal, $dayEndLocal)
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at']);

        $booked = $bookings->map(fn (Booking $b): array => [
            'starts_at' => $b->starts_at->copy()->timezone($tz)->toIso8601String(),
            'ends_at' => $b->ends_at->copy()->timezone($tz)->toIso8601String(),
        ])->values()->all();

        $availableSlots = [];
        for ($cursor = $dayStartLocal; $cursor->lt($dayEndLocal); $cursor = $cursor->addHour()) {
            $slotEnd = $cursor->addHour();
            $slotStartUtc = $cursor->utc();
            $slotEndUtc = $slotEnd->utc();

            $conflict = $bookings->contains(
                fn (Booking $b): bool => $b->starts_at->lt($slotEndUtc) && $b->ends_at->gt($slotStartUtc),
            );

            if (! $conflict) {
                $availableSlots[] = [
                    'starts_at' => $cursor->toIso8601String(),
                    'ends_at' => $slotEnd->toIso8601String(),
                ];
            }
        }

        return [
            'date' => $dayStartLocal->toDateString(),
            'timezone' => $tz,
            'booked' => $booked,
            'available_slots' => $availableSlots,
        ];
    }
}

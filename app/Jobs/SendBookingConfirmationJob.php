<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Notifications\BookingConfirmedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

class SendBookingConfirmationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $bookingId) {}

    public function handle(): void
    {
        $booking = Booking::query()->with('resource')->find($this->bookingId);

        if ($booking === null || $booking->status !== Booking::STATUS_CONFIRMED) {
            return;
        }

        Notification::route('mail', $booking->customer_email)
            ->notify(new BookingConfirmedNotification($booking));
    }
}

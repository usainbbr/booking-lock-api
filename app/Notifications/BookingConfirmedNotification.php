<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resource = $this->booking->resource;
        $tz = $resource->timezone;

        return (new MailMessage)
            ->subject("Booking Confirmed: {$resource->name}")
            ->greeting("Hi {$this->booking->customer_name},")
            ->line("Your booking for {$resource->name} is confirmed.")
            ->line('Start: '.$this->booking->starts_at->copy()->timezone($tz)->toDayDateTimeString()." ({$tz})")
            ->line('End: '.$this->booking->ends_at->copy()->timezone($tz)->toDayDateTimeString()." ({$tz})");
    }
}

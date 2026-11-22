<?php

namespace Tests\Feature;

use App\Jobs\SendBookingConfirmationJob;
use App\Models\Booking;
use App\Models\Resource;
use App\Notifications\BookingConfirmedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resource = Resource::query()->create([
            'name' => 'Main Pitch A',
            'slug' => 'main-pitch-a',
            'timezone' => 'Africa/Lagos',
            'is_active' => true,
        ]);
    }

    public function test_it_creates_a_booking_and_dispatches_confirmation_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'Usaini Ahmad',
            'customer_email' => 'usainia.dev@gmail.com',
            'starts_at' => '2026-12-01T14:00:00+01:00',
            'ends_at' => '2026-12-01T15:00:00+01:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Booking::STATUS_CONFIRMED)
            ->assertJsonPath('data.resource.slug', 'main-pitch-a')
            ->assertJsonPath('replayed', false);

        $bookingId = $response->json('data.id');

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'resource_id' => $this->resource->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        Queue::assertPushed(SendBookingConfirmationJob::class, function (SendBookingConfirmationJob $job) use ($bookingId): bool {
            return $job->bookingId === $bookingId;
        });
    }

    public function test_it_prevents_overlapping_bookings_with_409_conflict(): void
    {
        Queue::fake();

        $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'First User',
            'customer_email' => 'first@example.com',
            'starts_at' => '2026-12-01T14:00:00+01:00',
            'ends_at' => '2026-12-01T16:00:00+01:00',
        ])->assertCreated();

        // Partial overlap at the tail
        $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'Second User',
            'customer_email' => 'second@example.com',
            'starts_at' => '2026-12-01T15:00:00+01:00',
            'ends_at' => '2026-12-01T17:00:00+01:00',
        ])->assertStatus(409);

        // Exact same slot
        $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'Third User',
            'customer_email' => 'third@example.com',
            'starts_at' => '2026-12-01T14:00:00+01:00',
            'ends_at' => '2026-12-01T16:00:00+01:00',
        ])->assertStatus(409);

        $this->assertSame(1, Booking::query()->count());
    }

    public function test_it_allows_back_to_back_bookings_without_false_conflict(): void
    {
        Queue::fake();

        $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'First User',
            'customer_email' => 'first@example.com',
            'starts_at' => '2026-12-01T14:00:00+01:00',
            'ends_at' => '2026-12-01T15:00:00+01:00',
        ])->assertCreated();

        $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'Second User',
            'customer_email' => 'second@example.com',
            'starts_at' => '2026-12-01T15:00:00+01:00',
            'ends_at' => '2026-12-01T16:00:00+01:00',
        ])->assertCreated();

        $this->assertSame(2, Booking::query()->count());
    }

    public function test_idempotency_key_replays_existing_booking_instead_of_duplicating(): void
    {
        Queue::fake();

        $payload = [
            'customer_name' => 'Retry User',
            'customer_email' => 'retry@example.com',
            'starts_at' => '2026-12-02T10:00:00+01:00',
            'ends_at' => '2026-12-02T11:00:00+01:00',
        ];

        $first = $this->withHeader('Idempotency-Key', 'req-abc-123')
            ->postJson('/api/resources/main-pitch-a/bookings', $payload)
            ->assertCreated();

        $second = $this->withHeader('Idempotency-Key', 'req-abc-123')
            ->postJson('/api/resources/main-pitch-a/bookings', $payload)
            ->assertOk()
            ->assertJsonPath('replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Booking::query()->count());
        Queue::assertPushed(SendBookingConfirmationJob::class, 1);
    }

    public function test_cancelling_a_booking_frees_the_slot_for_new_bookings(): void
    {
        Queue::fake();

        $created = $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'Original User',
            'customer_email' => 'orig@example.com',
            'starts_at' => '2026-12-03T18:00:00+01:00',
            'ends_at' => '2026-12-03T19:00:00+01:00',
        ])->assertCreated();

        $bookingId = $created->json('data.id');

        $this->postJson("/api/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_CANCELLED);

        $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'Replacement User',
            'customer_email' => 'next@example.com',
            'starts_at' => '2026-12-03T18:00:00+01:00',
            'ends_at' => '2026-12-03T19:00:00+01:00',
        ])->assertCreated();
    }

    public function test_it_rejects_bookings_on_inactive_resources(): void
    {
        $this->resource->update(['is_active' => false]);

        $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'Test User',
            'customer_email' => 'test@example.com',
            'starts_at' => '2026-12-04T10:00:00+01:00',
            'ends_at' => '2026-12-04T11:00:00+01:00',
        ])->assertStatus(422);
    }

    public function test_availability_endpoint_excludes_booked_hours(): void
    {
        Queue::fake();

        $this->postJson('/api/resources/main-pitch-a/bookings', [
            'customer_name' => 'Morning Player',
            'customer_email' => 'morning@example.com',
            'starts_at' => '2026-12-05T09:00:00+01:00',
            'ends_at' => '2026-12-05T11:00:00+01:00',
        ])->assertCreated();

        $response = $this->getJson('/api/resources/main-pitch-a/availability?date=2026-12-05')
            ->assertOk()
            ->assertJsonPath('date', '2026-12-05')
            ->assertJsonPath('timezone', 'Africa/Lagos')
            ->assertJsonCount(1, 'booked')
            ->assertJsonCount(12, 'available_slots');

        $availableStarts = array_column($response->json('available_slots'), 'starts_at');
        $this->assertNotContains('2026-12-05T09:00:00+01:00', $availableStarts);
        $this->assertNotContains('2026-12-05T10:00:00+01:00', $availableStarts);
        $this->assertContains('2026-12-05T11:00:00+01:00', $availableStarts);
    }

    public function test_confirmation_job_sends_notification_for_confirmed_booking(): void
    {
        Notification::fake();

        $booking = Booking::query()->create([
            'resource_id' => $this->resource->id,
            'customer_name' => 'Usaini Ahmad',
            'customer_email' => 'usainia.dev@gmail.com',
            'starts_at' => '2026-12-06T12:00:00Z',
            'ends_at' => '2026-12-06T13:00:00Z',
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        (new SendBookingConfirmationJob($booking->id))->handle();

        Notification::assertSentOnDemand(
            BookingConfirmedNotification::class,
            fn (BookingConfirmedNotification $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'usainia.dev@gmail.com'
                && $n->booking->id === $booking->id,
        );
    }
}

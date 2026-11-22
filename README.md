# Booking Lock API (Laravel)

A small, focused Laravel API showing how I handle **double-booking prevention**, **idempotent retries**, and **queued notifications** for real-time venue/resource scheduling.

I built this as a clean reference implementation of patterns I've used in production booking and high-traffic systems.

## What It Solves

1. **No double bookings under concurrent requests**
   - `BookingService::reserve()` acquires an atomic cache lock per resource and a pessimistic row lock (`lockForUpdate()`) inside a `DB::transaction()` before checking interval overlap.
   - Adjacent back-to-back slots (`14:00–15:00` and `15:00–16:00`) are allowed; any true overlap returns `409 Conflict`.
2. **Safe client retries (`Idempotency-Key`)**
   - Passing an `Idempotency-Key` header (or `idempotency_key` field) replays the original booking (`200 OK`, `replayed: true`) instead of creating duplicates or throwing a conflict when a client retries after a network timeout.
3. **Notifications dispatched only after commit**
   - `SendBookingConfirmationJob` is dispatched with `->afterCommit()` and exponential backoff (`[10, 60, 300]` seconds) so emails never fire if a transaction rolls back.
4. **Basic abuse protection**
   - Endpoints are guarded by a dedicated `booking-api` rate limiter (`30 req/min` per IP).

## Endpoints

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/api/resources/{slug}/availability?date=YYYY-MM-DD` | Returns booked intervals & open 1-hour slots in the resource's timezone |
| `POST` | `/api/resources/{slug}/bookings` | Creates a booking (supports `Idempotency-Key` header) |
| `POST` | `/api/bookings/{id}/cancel` | Cancels a booking idempotently and frees the slot |

## Running Locally

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```

Code style check:

```bash
./vendor/bin/pint --test
```

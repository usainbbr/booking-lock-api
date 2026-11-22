<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email:rfc', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $headerKey = $this->header('Idempotency-Key');
        $bodyKey = $this->validated('idempotency_key');

        $key = is_string($headerKey) && $headerKey !== '' ? $headerKey : $bodyKey;

        return is_string($key) && $key !== '' ? substr($key, 0, 100) : null;
    }
}

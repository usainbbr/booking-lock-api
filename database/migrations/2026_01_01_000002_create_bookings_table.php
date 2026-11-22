<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 24)->default('confirmed');
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'status', 'starts_at', 'ends_at'], 'bookings_overlap_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};

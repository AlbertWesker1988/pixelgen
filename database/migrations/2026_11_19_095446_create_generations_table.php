<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Pixelforge's own id (gen_...), filled once POST /v1/generations returns.
            $table->string('pixelforge_id')->nullable()->unique();

            // The key we send on create so a retry after a 503 never makes a duplicate.
            $table->string('idempotency_key')->unique();

            // creating -> queued -> processing -> succeeded | failed (plus create_failed).
            $table->string('status')->default('creating');

            // What the user asked for.
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images')->nullable();

            // Rates snapshotted at creation, fixed for this generation's lifetime.
            $table->json('rates')->nullable();

            // Token counts, arrive when the generation succeeds.
            $table->json('usage')->nullable();

            // Money as exact decimal STRINGS. Never float, never computed by the DB.
            $table->string('estimated_user_cost')->nullable(); // shown before submit, incl. 25% markup
            $table->string('provider_cost')->nullable();       // actual, what Pixelforge charged us
            $table->string('user_cost')->nullable();           // actual, incl. 25% markup

            // Result.
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};

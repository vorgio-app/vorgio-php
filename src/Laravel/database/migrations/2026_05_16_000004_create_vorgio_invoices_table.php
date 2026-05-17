<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of the Vorgio invoices that belong to one of our billables,
 * upserted by the package's webhook controller. Consumers query this
 * instead of round-tripping to the API for "what's the latest open
 * invoice for this customer".
 */
return new class () extends Migration {
    public function up(): void
    {
        $prefix = (string) config('vorgio.table_prefix', 'vorgio_');

        Schema::create($prefix.'invoices', function (Blueprint $table) use ($prefix) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vorgio_billable_id')
                ->constrained($prefix.'billables')
                ->cascadeOnDelete();
            $table->string('vorgio_invoice_id')->unique();
            $table->string('parent_invoice_id')->nullable()->index();
            // Short cap — values are constants from a small set.
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->string('currency', 3)->default('EUR');
            // Optional fields surfaced by the webhook payload that consumer
            // UIs typically want to display alongside the mirror. Vorgio is
            // still the source of truth — these are a denormalised cache.
            $table->string('number')->nullable();
            $table->date('billing_date')->nullable();
            $table->string('every')->nullable();
            $table->dateTime('next_invoice_at')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['vorgio_billable_id', 'status']);
        });
    }

    public function down(): void
    {
        $prefix = (string) config('vorgio.table_prefix', 'vorgio_');
        Schema::dropIfExists($prefix.'invoices');
    }
};

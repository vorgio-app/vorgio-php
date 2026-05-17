<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transient retry-safety state for the Cashier-style trait.
 *
 * One row per (billable, purpose) tracks the operation_id used for the
 * in-flight SDK call so queue retries and midnight-cross retries can pick
 * up the same UUIDv7 — and therefore the same `Idempotency-Key` + body —
 * without polluting the consumer's domain tables.
 */
return new class () extends Migration {
    public function up(): void
    {
        $prefix = (string) config('vorgio.table_prefix', 'vorgio_');

        Schema::create($prefix.'operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Polymorphic pointer at the *consumer* model (e.g. Association),
            // not at vorgio_billables — the consumer always exists, the
            // Vorgio mapping might not yet on the first `subscribe()` call.
            // Column lengths capped — same reason as in the matching note
            // on `vorgio_billables`. The four-column composite index below
            // would otherwise overflow MySQL's 3072-byte combined-key cap.
            $table->string('billable_type', 191);
            // String, not unsignedBigInteger — see the matching note on
            // vorgio_billables. The polymorphic id must accommodate both
            // bigint and UUID consumer keys.
            $table->string('billable_id', 64);
            $table->string('purpose', 32);
            $table->uuid('operation_id')->unique();
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('last_attempted_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'purpose', 'status']);
        });
    }

    public function down(): void
    {
        $prefix = (string) config('vorgio.table_prefix', 'vorgio_');
        Schema::dropIfExists($prefix.'operations');
    }
};

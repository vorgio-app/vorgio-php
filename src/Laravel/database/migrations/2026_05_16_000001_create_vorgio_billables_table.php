<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $prefix = (string) config('vorgio.table_prefix', 'vorgio_');

        Schema::create($prefix.'billables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Lengths capped so the composite indexes below stay under the
            // 3072-byte combined-key limit MySQL enforces under utf8mb4.
            // Defaults (varchar(255)) blow that budget the moment the index
            // touches more than one string column.
            $table->string('billable_type', 191);
            // String column rather than unsignedBigInteger so the polymorphic
            // key can hold either a bigint or a UUID. MVGV's Association uses
            // HasUuids; coercing a UUID into a bigint silently truncates to
            // 0 and breaks every polymorphic lookup. String works for both;
            // 64 chars accommodates UUIDs (36) and bigints (≤20).
            $table->string('billable_id', 64);
            $table->string('vorgio_client_id')->unique();
            $table->timestamps();

            $table->unique(['billable_type', 'billable_id']);
            $table->index(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        $prefix = (string) config('vorgio.table_prefix', 'vorgio_');
        Schema::dropIfExists($prefix.'billables');
    }
};

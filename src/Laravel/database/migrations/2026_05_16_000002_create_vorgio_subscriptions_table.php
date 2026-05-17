<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $prefix = (string) config('vorgio.table_prefix', 'vorgio_');

        Schema::create($prefix.'subscriptions', function (Blueprint $table) use ($prefix) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vorgio_billable_id')
                ->constrained($prefix.'billables')
                ->cascadeOnDelete();
            $table->string('vorgio_invoice_id')->unique();
            $table->string('every', 32);
            $table->dateTime('next_invoice_at')->nullable();
            // Short cap — values are constants from a small set.
            $table->string('status', 16)->default('active');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('stopped_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['vorgio_billable_id', 'status']);
        });
    }

    public function down(): void
    {
        $prefix = (string) config('vorgio.table_prefix', 'vorgio_');
        Schema::dropIfExists($prefix.'subscriptions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dispensations', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('prescription_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('warehouse_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('dispensed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->timestamps();

            $table->unique(
                ['dispensed_by', 'idempotency_key'],
                'dispensations_idempotency_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispensations');
    }
};

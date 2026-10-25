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
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('patient_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('prescribed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->date('valid_from');
            $table->date('valid_until');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};

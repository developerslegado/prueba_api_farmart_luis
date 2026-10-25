
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_medication_authorizations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('prescription_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('dispensation_id')
                ->nullable()
                ->constrained('dispensations')
                ->restrictOnDelete();

            $table->string('status', 20)->default('PENDIENTE');
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index([
                'prescription_id',
                'product_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_medication_authorizations');
    }
};

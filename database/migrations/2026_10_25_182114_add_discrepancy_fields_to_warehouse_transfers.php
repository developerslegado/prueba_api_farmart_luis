<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->string('discrepancy_status', 25)->nullable(); 
            $table->text('discrepancy_notes')->nullable();
            $table->foreignId('discrepancy_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discrepancy_resolved_at')->nullable();
        });

        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            $table->integer('quantity_discrepancy')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            $table->dropColumn('quantity_discrepancy');
        });

        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discrepancy_resolved_by');
            $table->dropColumn([
                'discrepancy_status',
                'discrepancy_notes',
                'discrepancy_resolved_at',
            ]);
        });
    }
};

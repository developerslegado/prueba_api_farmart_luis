
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $constraintExists = DB::select("
            SELECT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conname = 'inventory_lots_quantity_nonnegative'
            )
        ");

        if (!$constraintExists[0]->exists) {
            DB::statement("
                ALTER TABLE inventory_lots
                ADD CONSTRAINT inventory_lots_quantity_nonnegative
                CHECK (quantity >= 0)
            ");
        }

        $dispensationConstraintExists = DB::select("
            SELECT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conname = 'dispensation_items_quantity_positive'
            )
        ");

        if (!$dispensationConstraintExists[0]->exists) {
            DB::statement("
                ALTER TABLE dispensation_items
                ADD CONSTRAINT dispensation_items_quantity_positive
                CHECK (quantity > 0)
            ");
        }
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE dispensation_items
            DROP CONSTRAINT IF EXISTS dispensation_items_quantity_positive
        ");

        DB::statement("
            ALTER TABLE inventory_lots
            DROP CONSTRAINT IF EXISTS inventory_lots_quantity_nonnegative
        ");
    }
};

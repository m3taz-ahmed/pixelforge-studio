<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Authorize/capture for credits: a generation reserves its worst-case
     * cost at submit (`held_amount`), and the ledger records `hold` /
     * `release` rows alongside `charge`. The per-generation ledger rows
     * become unique on (generation_id, type) since one generation now owns
     * up to one row of each type.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->decimal('held_amount', 14, 9)->nullable()->after('user_charge');
        });

        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->dropUnique(['generation_id']);
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->dropUnique(['generation_id', 'type']);
            $table->unique(['generation_id']);
        });

        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn('held_amount');
        });
    }
};

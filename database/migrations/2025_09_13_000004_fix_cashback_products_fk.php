<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If cashback_products currently stores ec_customers.id in cashback_customer_id,
        // remap it to cashback_customers.id using rule + customer match.
        if (Schema::hasTable('cashback_products') && Schema::hasTable('cashback_customers')) {
            DB::statement(
                'UPDATE cashback_products cp
                 JOIN cashback_customers cc
                   ON cc.customer_id = cp.cashback_customer_id
                  AND cc.cashback_rule_id = cp.cashback_rule_id
                 SET cp.cashback_customer_id = cc.id'
            );
        }

        Schema::table('cashback_products', function (Blueprint $table) {
            // Drop old FK if it exists (to ec_customers)
            try {
                $table->dropForeign(['cashback_customer_id']);
            } catch (\Throwable $e) {
                // Ignore if it doesn't exist
            }

            // Create FK to cashback_customers(id)
            $table->foreign('cashback_customer_id')
                ->references('id')->on('cashback_customers')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('cashback_products', function (Blueprint $table) {
            try {
                $table->dropForeign(['cashback_customer_id']);
            } catch (\Throwable $e) {
                // Ignore if it doesn't exist
            }
        });
    }
};


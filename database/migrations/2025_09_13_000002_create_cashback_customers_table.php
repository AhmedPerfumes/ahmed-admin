<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cashback_rule_id');
            $table->unsignedBigInteger('customer_id');
            $table->timestamps();

            $table->index(['cashback_rule_id', 'customer_id']);
            $table->unique(['cashback_rule_id', 'customer_id']);

            // FK to cashback_rules, cascade on delete
            $table->foreign('cashback_rule_id')->references('id')->on('cashback_rules')->onDelete('cascade');
            // Botble's customers table name
            $table->foreign('customer_id')->references('id')->on('ec_customers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_customers');
    }
};

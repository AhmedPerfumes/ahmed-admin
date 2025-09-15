<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cashback_rule_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('cashback_customer_id');
            $table->timestamps();

            $table->index(['cashback_rule_id', 'product_id']);
            $table->unique(['cashback_rule_id', 'product_id', 'cashback_customer_id'], 'cb_products_unique');

            // FK to cashback_rules, cascade on delete
            $table->foreign('cashback_rule_id')->references('id')->on('cashback_rules')->onDelete('cascade');
            // Botble's products and customers tables
            $table->foreign('product_id')->references('id')->on('ec_products')->onDelete('cascade');
            $table->foreign('cashback_customer_id')->references('id')->on('ec_customers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_products');
    }
};

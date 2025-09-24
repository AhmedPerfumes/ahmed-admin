<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id');
            $table->enum('customer_type', ['all', 'group'])->default('all');
            $table->enum('product_type', ['all', 'group'])->default('all');
            $table->decimal('cashback_percentage', 10, 2)->nullable();
            $table->decimal('cashback_amount', 15, 2)->nullable();
            $table->timestamps();

            $table->index('promotion_id');
            // If you want FK and your promotions table allows it, uncomment:
            $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_rules');
    }
};


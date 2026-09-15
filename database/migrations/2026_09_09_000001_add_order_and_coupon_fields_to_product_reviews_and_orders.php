<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_reviews')) {
            Schema::table('product_reviews', function (Blueprint $table) {
                if (!Schema::hasColumn('product_reviews', 'order_id')) {
                    $table->unsignedBigInteger('order_id')->nullable()->after('product_id')->index();
                }
                if (!Schema::hasColumn('product_reviews', 'coupon_code')) {
                    $table->string('coupon_code', 100)->nullable()->after('status');
                }
                if (!Schema::hasColumn('product_reviews', 'coupon_sent_at')) {
                    $table->timestamp('coupon_sent_at')->nullable()->after('coupon_code');
                }
            });
        }

        if (Schema::hasTable('ec_orders')) {
            Schema::table('ec_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('ec_orders', 'review_request_sent_at')) {
                    $table->timestamp('review_request_sent_at')->nullable()->after('status');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('product_reviews')) {
            Schema::table('product_reviews', function (Blueprint $table) {
                if (Schema::hasColumn('product_reviews', 'order_id')) {
                    $table->dropColumn('order_id');
                }
                if (Schema::hasColumn('product_reviews', 'coupon_code')) {
                    $table->dropColumn('coupon_code');
                }
                if (Schema::hasColumn('product_reviews', 'coupon_sent_at')) {
                    $table->dropColumn('coupon_sent_at');
                }
            });
        }

        if (Schema::hasTable('ec_orders')) {
            Schema::table('ec_orders', function (Blueprint $table) {
                if (Schema::hasColumn('ec_orders', 'review_request_sent_at')) {
                    $table->dropColumn('review_request_sent_at');
                }
            });
        }
    }
};

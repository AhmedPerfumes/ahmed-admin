<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'name_ar')) {
                $table->string('name_ar', 120)->nullable()->after('name');
            }
            if (! Schema::hasColumn('pages', 'description_ar')) {
                $table->string('description_ar', 400)->nullable()->after('description');
            }
            if (! Schema::hasColumn('pages', 'content_ar')) {
                $table->longText('content_ar')->nullable()->after('content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('pages', 'name_ar')) {
                $columnsToDrop[] = 'name_ar';
            }
            if (Schema::hasColumn('pages', 'description_ar')) {
                $columnsToDrop[] = 'description_ar';
            }
            if (Schema::hasColumn('pages', 'content_ar')) {
                $columnsToDrop[] = 'content_ar';
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

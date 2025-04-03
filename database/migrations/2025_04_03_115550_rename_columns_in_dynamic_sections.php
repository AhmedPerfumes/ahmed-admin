<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('dynamic_sections', function (Blueprint $table) {
            // $table->renameColumn('input1', 'title');
            $table->renameColumn('input2', 'subtitle');
            $table->renameColumn('input3', 'description');
        });
    }

    public function down()
    {
        Schema::table('dynamic_sections', function (Blueprint $table) {
            // $table->renameColumn('title', 'input1');
            $table->renameColumn('subtitle', 'input2');
            $table->renameColumn('description', 'input3');
            // Revert back in case of rollback
        });
    }
};

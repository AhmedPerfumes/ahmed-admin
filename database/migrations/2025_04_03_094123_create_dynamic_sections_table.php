<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('dynamic_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle');
            $table->string('description');
            $table->string('image'); // Path for the uploaded image
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('dynamic_sections');
    }
};
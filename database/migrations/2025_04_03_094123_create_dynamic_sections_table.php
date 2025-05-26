<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('dynamic_sections', function (Blueprint $table) {
            $table->id();
            $table->string('heading');
            $table->string('description');
            $table->string('link'); // URL for the link
            $table->string('image'); // Path for the uploaded image
            $table->string('video1');
            $table->string('video2');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('dynamic_sections');
    }
};
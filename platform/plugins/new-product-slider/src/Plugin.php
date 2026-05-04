<?php

namespace Ahmed\NewProductSlider;

use Illuminate\Support\Facades\Schema;
use Botble\PluginManagement\Abstracts\PluginOperationAbstract;

class Plugin extends PluginOperationAbstract
{
    public static function remove(): void
    {
        Schema::dropIfExists('New Product Sliders');
        Schema::dropIfExists('New Product Sliders_translations');
    }
}

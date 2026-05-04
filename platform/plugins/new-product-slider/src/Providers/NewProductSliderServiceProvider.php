<?php

namespace Ahmed\NewProductSlider\Providers;

use Botble\Base\Supports\ServiceProvider;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Botble\Base\Facades\DashboardMenu;
use Ahmed\NewProductSlider\Models\NewProductSlider;

class NewProductSliderServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function boot(): void
    {
        $this
            ->setNamespace('plugins/new-product-slider')
            ->loadHelpers()
            ->loadAndPublishConfigurations(['permissions'])
            ->loadAndPublishTranslations()
            ->loadRoutes(['web', 'api'])
            ->loadAndPublishViews()
            ->loadMigrations();
            
            if (defined('LANGUAGE_ADVANCED_MODULE_SCREEN_NAME')) {
                \Botble\LanguageAdvanced\Supports\LanguageAdvancedManager::registerModule(NewProductSlider::class, [
                    'name',
                ]);
            }
            
            DashboardMenu::default()->beforeRetrieving(function () {
                DashboardMenu::registerItem([
                    'id' => 'cms-plugins-new-product-slider',
                    'priority' => 5,
                    'parent_id' => null,
                    'name' => 'New Product Slider',
                    'icon' => 'ti ti-box',
                    'url' => route('new-product-slider.index'),
                    'permissions' => ['new-product-slider.index'],
                ]);
            });
    }
}

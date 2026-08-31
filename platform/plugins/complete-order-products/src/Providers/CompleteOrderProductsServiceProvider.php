<?php

namespace Ahmed\CompleteOrderProducts\Providers;

use Botble\Base\Supports\ServiceProvider;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Botble\Base\Facades\DashboardMenu;
use Ahmed\CompleteOrderProducts\Models\CompleteOrderProduct;

class CompleteOrderProductsServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function boot(): void
    {
        $this
            ->setNamespace('plugins/complete-order-products')
            ->loadHelpers()
            ->loadAndPublishConfigurations(['permissions'])
            ->loadAndPublishTranslations()
            ->loadRoutes(['web', 'api'])
            ->loadAndPublishViews()
            ->loadMigrations();

        DashboardMenu::default()->beforeRetrieving(function () {
            DashboardMenu::registerItem([
                'id' => 'cms-plugins-complete-order-products',
                'priority' => 6,
                'parent_id' => null,
                'name' => 'Complete Your Order',
                'icon' => 'ti ti-shopping-cart-plus',
                'url' => route('complete-order-product.index'),
                'permissions' => ['complete-order-product.index'],
            ]);
        });
    }
}

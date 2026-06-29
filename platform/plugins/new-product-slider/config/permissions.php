<?php

return [
    [
        'name' => 'New product sliders',
        'flag' => 'new-product-slider.index',
    ],
    [
        'name' => 'Create',
        'flag' => 'new-product-slider.create',
        'parent_flag' => 'new-product-slider.index',
    ],
    [
        'name' => 'Edit',
        'flag' => 'new-product-slider.edit',
        'parent_flag' => 'new-product-slider.index',
    ],
    [
        'name' => 'Delete',
        'flag' => 'new-product-slider.destroy',
        'parent_flag' => 'new-product-slider.index',
    ],
];

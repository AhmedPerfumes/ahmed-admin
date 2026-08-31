<?php

return [
    [
        'name' => 'Complete Order Products',
        'flag' => 'complete-order-product.index',
    ],
    [
        'name' => 'Create',
        'flag' => 'complete-order-product.create',
        'parent_flag' => 'complete-order-product.index',
    ],
    [
        'name' => 'Edit',
        'flag' => 'complete-order-product.edit',
        'parent_flag' => 'complete-order-product.index',
    ],
    [
        'name' => 'Delete',
        'flag' => 'complete-order-product.destroy',
        'parent_flag' => 'complete-order-product.index',
    ],
];

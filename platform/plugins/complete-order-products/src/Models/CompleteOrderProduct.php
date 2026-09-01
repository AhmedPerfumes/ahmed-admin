<?php

namespace Ahmed\CompleteOrderProducts\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Models\BaseModel;
use Botble\Ecommerce\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompleteOrderProduct extends BaseModel
{
    protected $table = 'complete_order_products';

    protected $fillable = [
        'product_id',
        'custom_title',
        'custom_title_ar',
        'order_index',
        'status',
    ];

    protected $casts = [
        'status' => BaseStatusEnum::class,
        'custom_title' => SafeContent::class,
        'custom_title_ar' => SafeContent::class,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withDefault();
    }
}

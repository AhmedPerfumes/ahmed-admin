<?php

namespace Ahmed\NewProductSlider\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Models\BaseModel;

class Newproductslider extends BaseModel
{
    protected $table = 'newproductsliders';

    protected $fillable = [
        'product_id',
        'name',
        'name_ar',
        'category',
        'category_ar',
        'desc',
        'desc_ar',
        'product_img',
        'note_img',
        'theme_bg',
        'theme_roman',
        'theme_accent',
        'theme_glow',
        'link',
        'order_index',
        'status',
    ];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Botble\Ecommerce\Models\Product::class, 'product_id')->withDefault();
    }

    protected $casts = [
        'status' => BaseStatusEnum::class,
        'name' => SafeContent::class,
    ];
}

<?php

namespace Botble\SimpleSlider\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Models\BaseModel;

class SimpleSliderItem extends BaseModel
{
    protected $table = 'simple_slider_items';

    protected $fillable = [
        'title',
        'title_ar',
        'description',
        'description_ar',
        'link',
        'image',
        'mobile_image',
        'order',
        'season',
        'season_ar',
        'sub_title',
        'sub_title_ar',
        'simple_slider_id',
        'type',
        'color'
    ];

    protected $casts = [
        'title' => SafeContent::class,
        'title_ar' => SafeContent::class,
        'description' => SafeContent::class,
        'description_ar' => SafeContent::class,
        'link' => SafeContent::class,
        'sub_title' => SafeContent::class,
        'sub_title_ar' => SafeContent::class,
        'season' => SafeContent::class,
        'season_ar' => SafeContent::class,
    ];

    protected static function booted(): void
    {
        static::deleted(function (SimpleSliderItem $item) {
            $item->metadata()->delete();
        });
    }
}

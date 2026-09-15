<?php

namespace App\Models;

use App\Scopes\ActiveReviewScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Botble\Base\Models\BaseModel;
use Botble\Ecommerce\Models\Product; // <-- ADD THIS LINE
use Botble\Ecommerce\Models\Order;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // <-- ADD THIS LINE
use Botble\Base\Enums\BaseStatusEnum; 

class ProductReview extends BaseModel
{
    use HasFactory;

    /**
     * This line tells our blueprint which table to connect to in the database.
     * @var string
     */
    protected $table = 'product_reviews';

    /**
     * This is a "safe list" of the columns that we are allowed
     * to fill in from a web form. It's a security feature.
     * @var array
     */
    protected $fillable = [
        'customer_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'product_id',
        'order_id',
        'star',
        'comment',
        'status',
        'coupon_code',
        'coupon_sent_at',
        'images',
    ];

    /**
     * This tells our blueprint to treat certain columns in a special way.
     * Here, we're saying the 'images' column should be handled as a list (an array).
     * @var array
     */
    protected $casts = [
        'images' => 'array',
        'coupon_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ActiveReviewScope());
    }

    public function product(): BelongsTo
    {
        // This links our 'product_id' column to the main Product blueprint.
        return $this->belongsTo(Product::class)->withDefault();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id')->withDefault();
    }
}
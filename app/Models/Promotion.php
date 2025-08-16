<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'description',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'type' => 'string',
    ];

    public function bogoRules()
    {
        return $this->hasMany(BogoRule::class);
    }

    public function buyXGetYRules()
    {
        return $this->hasMany(BuyXGetYRule::class);
    }

    public function discountRules()
    {
        return $this->hasMany(DiscountRule::class);
    }

    public function couponRules()
    {
        return $this->hasMany(CouponRule::class);
    }

    public function focRules()
    {
        return $this->hasMany(FocRule::class);
    }
}

class BogoRule extends Model
{
    use HasFactory;

    protected $fillable = ['promotion_id', 'buy_product_id', 'free_product_id'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}

class BuyXGetYRule extends Model
{
    use HasFactory;

    protected $fillable = ['promotion_id', 'buy_quantity', 'get_quantity'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function products()
    {
        return $this->hasMany(BuyXGetYProduct::class, 'rule_id');
    }

    public function categories()
    {
        return $this->hasMany(BuyXGetYCategory::class, 'rule_id');
    }
}

class BuyXGetYProduct extends Model
{
    use HasFactory;

    protected $fillable = ['rule_id', 'product_id', 'type'];
}

class BuyXGetYCategory extends Model
{
    use HasFactory;

    protected $fillable = ['rule_id', 'category_id', 'type'];
}

class DiscountRule extends Model
{
    use HasFactory;

    protected $fillable = ['promotion_id', 'apply_to', 'percentage'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function individualRules()
    {
        return $this->hasMany(DiscountIndividualRule::class, 'discount_rule_id');
    }

    public function products()
    {
        return $this->hasMany(DiscountProduct::class, 'discount_rule_id');
    }

    public function categories()
    {
        return $this->hasMany(DiscountCategory::class, 'discount_rule_id');
    }
}

class DiscountIndividualRule extends Model
{
    use HasFactory;

    protected $fillable = ['discount_rule_id', 'product_id', 'discount_type', 'value', 'product_price', 'discount_amount', 'final_price',];
}

class DiscountProduct extends Model
{
    use HasFactory;

    protected $fillable = ['discount_rule_id', 'product_id'];
}

class DiscountCategory extends Model
{
    use HasFactory;

    protected $fillable = ['discount_rule_id', 'category_id'];
}

class CouponRule extends Model
{
    use HasFactory;

    protected $fillable = ['promotion_id', 'coupon_code', 'apply_to', 'percentage'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function products()
    {
        return $this->hasMany(CouponProduct::class, 'coupon_rule_id');
    }

    public function categories()
    {
        return $this->hasMany(CouponCategory::class, 'coupon_rule_id');
    }
}

class CouponProduct extends Model
{
    use HasFactory;

    protected $fillable = ['coupon_rule_id', 'product_id'];
}

class CouponCategory extends Model
{
    use HasFactory;

    protected $fillable = ['coupon_rule_id', 'category_id'];
}

class FocRule extends Model
{
    use HasFactory;

    protected $fillable = ['promotion_id', 'min_threshold', 'max_threshold'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function products()
    {
        return $this->hasMany(FocProduct::class, 'foc_rule_id');
    }

    public function categories()
    {
        return $this->hasMany(FocCategory::class, 'foc_rule_id');
    }
}

class FocProduct extends Model
{
    use HasFactory;

    protected $fillable = ['foc_rule_id', 'product_id'];
}

class FocCategory extends Model
{
    use HasFactory;

    protected $fillable = ['foc_rule_id', 'category_id'];
}
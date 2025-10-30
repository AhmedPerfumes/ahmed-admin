<?php

namespace App\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class PaymentCart extends BaseModel
{
    use HasFactory;
    protected $table = 'payment_carts';

    protected $fillable = [
        'customer_id',
        'cart_data',
        'status',
    ];

    protected $casts = [
        'cart_data' => 'array',
        'status' => 'string',
    ];

    public $incrementing = false;
    protected $keyType = 'string';
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
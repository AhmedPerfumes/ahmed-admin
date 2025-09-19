<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartProduct extends Model
{
    protected $table = 'ec_cart_products';

    protected $fillable = [
        'cart_id', 'qty', 'price', 'tax_amount', 'product_id',
        'product_name', 'product_image', 'discount_percent',
        'total_amount', 'net_amount', 'gross_amount', 'product_category',
        'product_subcategory', 'discount_amount', 'vat', 'is_gift', 'campaign'
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }
}


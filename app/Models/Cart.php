<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'ec_carts';

    protected $fillable = [
        'user_id', 'amount', 'tax_amount', 'shipping_amount',
        'sub_total', 'shipping_amount_vat', 'service_amount',
        'service_amount_vat', 'vat', 'cod_charge', 'cod_charge_vat'
    ];

    public function cartProducts()
    {
        return $this->hasMany(CartProduct::class, 'cart_id');
    }
}


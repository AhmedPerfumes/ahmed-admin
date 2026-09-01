<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Botble\Ecommerce\Models\Customer;

class CustomerSession extends Model
{
    use HasFactory;

    protected $table = 'ec_customer_sessions';

    protected $fillable = [
        'customer_id',
        'session_id',
        'refresh_token_jti',
        'device_type',
        'device_name',
        'ip_address',
        'user_agent',
        'last_active_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}

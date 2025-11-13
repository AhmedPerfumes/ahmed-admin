<?php

namespace Botble\Ecommerce\Models; // Adjust namespace to your plugin's structure

use Botble\Base\Models\BaseModel;

class PrebookingSubmission extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'prebooking_submissions';

    /**
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'interested_series',
        'ip_address',
    ];

    /**
     * The attributes that should be cast.
     * @var array
     */
    protected $casts = [
        // Cast the series to an array for easy usage (Laravel automatically handles JSON encoding/decoding)
        'interested_series' => 'array', 
    ];
}
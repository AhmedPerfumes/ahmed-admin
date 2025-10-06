<?php

return [
    'profile_id' => env('PAYTABS_PROFILE_ID'),
    'server_key' => env('PAYTABS_SERVER_KEY'),
    'base_url'   => env('PAYTABS_BASE_URL', 'https://secure.paytabs.com/payment/request'),
];
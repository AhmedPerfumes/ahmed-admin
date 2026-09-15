<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NextJsCacheService
{
    public static function revalidate($tags)
    {
        app(self::class)->clear($tags);
    }

    public function clear($tags)
    {
        // Normalize to array
        $tags = is_array($tags) ? $tags : [$tags];
        
        // SEND ONE REQUEST WITH ALL TAGS
        $this->sendRequest($tags);
    }

    protected function sendRequest($tags)
    {
        $url = 'http://localhost:3000/en/api/clearcache';
        $secret = env('NEXTJS_REVALIDATION_SECRET');

        dispatch(function () use ($url, $secret, $tags) {
            try {
                $response = Http::post($url, [
                    'secret' => $secret,
                    'tags'   => $tags, // Send array key 'tags'
                ]);

                if ($response->failed()) {
                    Log::error("NextJS Cache Clear Failed: " . $response->body());
                } else {
                    Log::info("NextJS Cache Cleared for tags: " . implode(', ', $tags));
                }
            } catch (\Exception $e) {
                Log::error("NextJS Connection Error: " . $e->getMessage());
            }
        })->afterResponse();
    }
}
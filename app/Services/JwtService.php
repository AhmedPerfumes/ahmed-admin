<?php

namespace App\Services;

use Botble\Ecommerce\Models\Customer;
use App\Models\CustomerSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class JwtService
{
    /**
     * Base64Url Encode
     */
    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Base64Url Decode
     */
    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    /**
     * Secret key used for signing JWTs
     */
    private static function getSecret(): string
    {
        $key = config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        return $key;
    }

    /**
     * Parse User-Agent into device details
     */
    public static function parseDeviceDetails(Request $request): array
    {
        $userAgent = $request->userAgent() ?? '';
        $deviceType = 'desktop';

        if (preg_match('/(mobile|iphone|ipod|android|blackberry|opera mini|opera mobi)/i', $userAgent)) {
            $deviceType = 'mobile';
        } elseif (preg_match('/(ipad|tablet|playbook|silk)/i', $userAgent)) {
            $deviceType = 'tablet';
        }

        // Platform
        $platform = 'Unknown OS';
        if (preg_match('/windows nt 10/i', $userAgent)) $platform = 'Windows 10/11';
        elseif (preg_match('/windows/i', $userAgent)) $platform = 'Windows';
        elseif (preg_match('/macintosh|mac os x/i', $userAgent)) $platform = 'macOS';
        elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) $platform = 'iOS';
        elseif (preg_match('/android/i', $userAgent)) $platform = 'Android';
        elseif (preg_match('/linux/i', $userAgent)) $platform = 'Linux';

        // Browser
        $browser = 'Unknown Browser';
        if (preg_match('/edg/i', $userAgent)) $browser = 'Edge';
        elseif (preg_match('/chrome/i', $userAgent)) $browser = 'Chrome';
        elseif (preg_match('/firefox/i', $userAgent)) $browser = 'Firefox';
        elseif (preg_match('/safari/i', $userAgent)) $browser = 'Safari';

        $deviceName = "$browser on $platform";

        return [
            'device_type' => $deviceType,
            'device_name' => $deviceName,
        ];
    }

    /**
     * Generate a signed JWT with unique jti and session_id (sid) claims
     */
    public static function generateToken(Customer $customer, string $sessionId = '', string $type = 'access', int $ttl = 3600): array
    {
        $jti = (string) Str::uuid();
        $header = json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT'
        ]);

        $payloadData = [
            'jti'   => $jti,
            'sub'   => $customer->id,
            'type'  => $type,
            'iat'   => time(),
            'exp'   => time() + $ttl
        ];

        // Include profile claims only in short-lived access tokens
        if ($type === 'access') {
            $payloadData['email'] = $customer->email;
            $payloadData['phone'] = $customer->phone;
            $payloadData['name']  = $customer->name;
        }

        if ($sessionId) {
            $payloadData['sid'] = $sessionId;
        }

        $payload = json_encode($payloadData);

        $base64UrlHeader  = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        $token = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

        return [
            'token' => $token,
            'jti'   => $jti,
            'exp'   => $payloadData['exp'],
        ];
    }

    /**
     * Create a new device session record and return tokens
     */
    public static function createDeviceSession(Customer $customer, Request $request): array
    {
        $sessionId = (string) Str::uuid();
        $deviceInfo = self::parseDeviceDetails($request);

        $accessTokenData = self::generateToken($customer, $sessionId, 'access', 900); // 15 min
        $refreshTokenData = self::generateToken($customer, $sessionId, 'refresh', 1209600); // 14 days

        CustomerSession::create([
            'customer_id'       => $customer->id,
            'session_id'        => $sessionId,
            'refresh_token_jti' => $refreshTokenData['jti'],
            'device_type'       => $deviceInfo['device_type'],
            'device_name'       => $deviceInfo['device_name'],
            'ip_address'        => $request->ip(),
            'user_agent'        => substr($request->userAgent() ?? '', 0, 500),
            'last_active_at'    => now(),
        ]);

        return [
            'access_token'  => $accessTokenData['token'],
            'refresh_token' => $refreshTokenData['token'],
            'session_id'    => $sessionId,
            'token_type'    => 'Bearer',
        ];
    }

    /**
     * Refresh an existing device session (Static Refresh Token)
     */
    public static function refreshDeviceSession(string $refreshToken, Request $request): ?array
    {
        $payload = self::validateToken($refreshToken, 'refresh');
        if (!$payload || !isset($payload->sid)) {
            return null;
        }

        $session = CustomerSession::where('customer_id', $payload->sub)
            ->where('session_id', $payload->sid)
            ->first();

        if (!$session) {
            return null; // Session was revoked or deleted
        }

        // Verify that the incoming refresh token matches the active session token
        if ($session->refresh_token_jti && isset($payload->jti) && $session->refresh_token_jti !== $payload->jti) {
            return null;
        }

        $customer = Customer::find($payload->sub);
        if (!$customer) {
            return null;
        }

        // Generate only a new access token (1 hour)
        $accessTokenData = self::generateToken($customer, $payload->sid, 'access', 3600);

        // Update session activity timestamp and IP
        $session->update([
            'last_active_at' => now(),
            'ip_address'     => $request->ip(),
        ]);

        return [
            'access_token'  => $accessTokenData['token'],
            'refresh_token' => $refreshToken, // Retain static refresh token
            'session_id'    => $payload->sid,
            'token_type'    => 'Bearer',
        ];
    }

    /**
     * Validate JWT Token & Check Blacklist
     */
    public static function validateToken(string $token, string $expectedType = 'access'): ?object
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $parts;

        // Verify signature
        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true)
        );

        if (!hash_equals($expectedSignature, $base64UrlSignature)) {
            return null;
        }

        // Decode payload
        $payloadJson = self::base64UrlDecode($base64UrlPayload);
        $payload = json_decode($payloadJson);

        if (!$payload || !isset($payload->sub, $payload->exp, $payload->type)) {
            return null;
        }

        // Check expiration
        if (time() >= $payload->exp) {
            return null;
        }

        // Check token type
        if ($payload->type !== $expectedType) {
            return null;
        }

        // Check server-side blacklist
        if (isset($payload->jti) && Cache::has('jwt_bl_' . $payload->jti)) {
            return null;
        }

        return $payload;
    }

    /**
     * Revoke/Blacklist a JWT token on server-side
     */
    public static function invalidateToken(?string $token): bool
    {
        if (!$token) {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $payloadJson = self::base64UrlDecode($parts[1]);
        $payload = json_decode($payloadJson);

        if (!$payload || !isset($payload->jti, $payload->exp)) {
            return false;
        }

        $remainingTtl = $payload->exp - time();
        if ($remainingTtl > 0) {
            Cache::put('jwt_bl_' . $payload->jti, true, $remainingTtl);
        }

        // If payload has session ID, blacklist its refresh token and delete session from DB
        if (isset($payload->sid, $payload->sub)) {
            $session = CustomerSession::where('customer_id', $payload->sub)
                ->where('session_id', $payload->sid)
                ->first();

            if ($session) {
                if ($session->refresh_token_jti) {
                    Cache::put('jwt_bl_' . $session->refresh_token_jti, true, 1209600);
                }
                $session->delete();
            }
        }

        return true;
    }

    /**
     * Get active device sessions for a customer
     */
    public static function getCustomerSessions(int $customerId, ?string $currentSessionId = null): array
    {
        $sessions = CustomerSession::where('customer_id', $customerId)
            ->orderBy('last_active_at', 'desc')
            ->get();

        return $sessions->map(function ($s) use ($currentSessionId) {
            return [
                'session_id'     => $s->session_id,
                'device_type'    => $s->device_type,
                'device_name'    => $s->device_name,
                'ip_address'     => $s->ip_address,
                'last_active_at' => $s->last_active_at ? $s->last_active_at->toIso8601String() : null,
                'is_current'     => $currentSessionId && $s->session_id === $currentSessionId,
            ];
        })->toArray();
    }

    /**
     * Revoke specific device session
     */
    public static function revokeSession(int $customerId, string $sessionId): bool
    {
        $session = CustomerSession::where('customer_id', $customerId)
            ->where('session_id', $sessionId)
            ->first();

        if (!$session) {
            return false;
        }

        // Blacklist the refresh token JTI in cache
        if ($session->refresh_token_jti) {
            Cache::put('jwt_bl_' . $session->refresh_token_jti, true, 1209600); // 14 days
        }

        return (bool) $session->delete();
    }

    /**
     * Revoke all other device sessions
     */
    public static function revokeOtherSessions(int $customerId, string $currentSessionId): int
    {
        $sessions = CustomerSession::where('customer_id', $customerId)
            ->where('session_id', '!=', $currentSessionId)
            ->get();

        $count = 0;
        foreach ($sessions as $session) {
            if ($session->refresh_token_jti) {
                Cache::put('jwt_bl_' . $session->refresh_token_jti, true, 1209600); // 14 days
            }
            $session->delete();
            $count++;
        }

        return $count;
    }
}

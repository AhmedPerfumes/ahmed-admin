<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\JwtService;
use App\Models\CustomerSession;
use Botble\Ecommerce\Models\Customer;
use Illuminate\Support\Facades\Auth;

class JwtAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            $token = $request->query('token');
        }

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated. Token missing.'], 401);
        }

        $payload = JwtService::validateToken($token, 'access');

        if (!$payload) {
            return response()->json(['message' => 'Unauthenticated. Invalid or expired token.'], 401);
        }

        $customer = Customer::find($payload->sub);
        if (!$customer) {
            return response()->json(['message' => 'Unauthenticated. Customer not found.'], 401);
        }

        $status = is_object($customer->status) ? ($customer->status->value ?? (string) $customer->status) : (string) $customer->status;
        if (strtolower($status) !== 'activated') {
            return response()->json(['message' => 'Unauthenticated. Account disabled.'], 401);
        }

        // Check if session is active (if session tracking claim 'sid' exists)
        if (isset($payload->sid)) {
            $session = CustomerSession::where('customer_id', $customer->id)
                ->where('session_id', $payload->sid)
                ->first();

            if (!$session) {
                return response()->json(['message' => 'Unauthenticated. Session revoked or expired.'], 401);
            }

            // Touch last_active_at periodically (e.g. if last active > 5 mins ago)
            if (!$session->last_active_at || $session->last_active_at->diffInMinutes(now()) >= 5) {
                $session->update(['last_active_at' => now(), 'ip_address' => $request->ip()]);
            }

            $request->attributes->set('current_session_id', $payload->sid);
            $customer->current_session_id = $payload->sid;
        }

        // Set authenticated user in Laravel Auth context
        Auth::setUser($customer);
        Auth::guard('api')->setUser($customer);

        return $next($request);
    }
}

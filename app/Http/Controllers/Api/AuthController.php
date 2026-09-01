<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\MobileVerification;
use Illuminate\Support\Facades\Auth;
// use Botble\Ecommerce\Models\Discount as DiscountModel;
use Botble\Ecommerce\Models\OrderAddress;
use Botble\Ecommerce\Models\Review;
use App\Services\JwtService;
use Botble\Ecommerce\Models\Address;

class AuthController extends Controller
{
    /**
     * Register a new customer
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function signup(Request $request) {

        // $validator = Validator::make($request->all(), [
        //     'name'      => 'required|string|max:255',
        //     'email'     => 'required|string|max:255',
        //     'mobile'     => 'required|numeric',
        //     'password'  => 'required|string'
        //     ]);

        // if ($validator->fails()) {
        //     return response()->json($validator->errors());
        // }

        $customer = Customer::where('email', $request->email)->orWhere('phone', $request->mobile)->first();

        if ($customer) {
            return response()->json([
                'message'       => 'Duplicate Email Id Or Mobile Number',
            ]);
        }

        // $customer = Customer::create([
        //     'name'      => $request->name,
        //     'email'     => $request->email,
        //     'phone'     => $request->mobile,
        //     'password'  => Hash::make($request->password)
        // ]);

        // $token = $customer->createToken('auth_token')->plainTextToken;

        $otp = rand(1111, 9999);

        $ch = curl_init();

        $passw = "11F2";
        $pass = "$";
        $p = "E89_6C3";
        $password = $passw.$pass.$p;

        curl_setopt($ch, CURLOPT_URL, "https://myinboxmedia.ae/api/mim/SendSMS?userid=MIM2300278&pwd=".$password."&mobile=971".ltrim($request->mobile, $request->mobile[0])."&sender=Ahmedper&msg=".$otp."".urlencode(' is your OTP for Registration')."&msgtype=16");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);die;
        }
        curl_close ($ch);

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://waba.myinboxmedia.in/api/sendwaba',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
            "ProfileId": "MIM2400074",
            "APIKey": "#JpXt4fbMCFj",
            "MobileNumber": 971'.ltrim($request->mobile, $request->mobile[0]).',
            "templateName": "websiteauthentication",
            "Parameters": [
                '.$otp.'      
            ],
            "HeaderType": "Text",
            "Text": "",
            "MediaUrl": "",
            "Latitude": 0,
            "Longitude": 0,
            "isTemplate": "true",
            "ButtonOrListJSON": "",
            "SubClientCode": "",
            "HeaderParameter": "",
            "CTAButtonURLParameter":"",
            "CTAButtonURLParameter2" : ""
        }',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        // echo $response;

        // $customer->otp = $otp;
        // $customer->save();

        $Mobile_verification = MobileVerification::create([
            'otp'     => $otp,
            'phone'     => $request->mobile,
        ]);

        return response()->json([
            'message'          => 'OTP Sent on Above Mobile Number'
        ]);
    }

    /**
     * Verify OTP
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyOTP(Request $request) {

        if($request->flag == 'checkout') {
            $validator = Validator::make($request->all(), [
                'mobile'     => 'required|numeric',
                'otp'  => 'required|numeric'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }
            $mobile_verification = MobileVerification::where('phone', $request->mobile)->where('otp', $request->otp)->orderBy('id', 'desc')->first();

            if (!$mobile_verification) {
                return response()->json([
                    'message'       => 'Invalid Mobile Number or OTP',
                ]);
            }

            $mobile_verification->otp = 0;
            $mobile_verification->save();

            $customer = Customer::where('email', $request->email)->orWhere('phone', $request->mobile)->first();

            if (!$customer) {
                $customer = Customer::create([
                    'name'      => 'Guest User',
                    'email'     => $request->email,
                    'phone'     => $request->mobile,
                    'password'  => Hash::make($request->password ?: '123456')
                ]);
            }

            $sessionData = JwtService::createDeviceSession($customer, $request);

            return response()->json([
                'message'       => 'OTP Verified Successfully',
                'data'          => $customer,
                'access_token'  => $sessionData['access_token'],
                'refresh_token' => $sessionData['refresh_token'],
                'session_id'    => $sessionData['session_id'],
                'token_type'    => 'Bearer'
            ]);
        } else {
            $validator = Validator::make($request->all(), [
                'mobile'     => 'required|numeric',
                'otp'  => 'required|numeric'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }

            if($request->flag == 'fpassword') {
                $customer = Customer::select('id', 'name', 'email', 'phone')->where('phone', $request->mobile)->where('otp', $request->otp)->first();

                if (!$customer) {
                    return response()->json([
                        'message'       => 'Invalid Mobile Number or OTP',
                    ]);
                }

                $customer->otp = 0;
                $customer->save();

                $sessionData = JwtService::createDeviceSession($customer, $request);

                return response()->json([
                    'message'       => 'OTP Verified Successfully',
                    'data'          => $customer,
                    'access_token'  => $sessionData['access_token'],
                    'refresh_token' => $sessionData['refresh_token'],
                    'session_id'    => $sessionData['session_id'],
                    'token_type'    => 'Bearer'
                ]);
            }

            $mobile_verification = MobileVerification::where('phone', $request->mobile)->where('otp', $request->otp)->orderBy('id', 'desc')->first();

            if (!$mobile_verification) {
                return response()->json([
                    'message'       => 'Invalid Mobile Number or OTP',
                ]);
            }

            $mobile_verification->otp = 0;
            $mobile_verification->save();

            $validator = Validator::make($request->all(), [
                'name' => 'required',
                'email' => 'required|email|unique:ec_customers,email,',
                'mobile' => 'required|unique:ec_customers,phone,',
                'password' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }

            $customer = Customer::create([
                'name'      => $request->name,
                'email'     => $request->email,
                'phone'     => $request->mobile,
                'password'  => Hash::make($request->password)
            ]);

            $apiUrl = env('SMART_VIEW_COUPON_API_URL').'Coupon/Register';

            $postData = [
                'couponId' => "3FDF342E-52C6-4D73-AD84-DA2605E15DF8",
                'customerName'  => $customer->name,
                'email' => $customer->email,
                'mobileNo' => $customer->phone,
            ];

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            
            $apiResponse = curl_exec($ch);

            if (curl_errno($ch)) {
                \Log::info('Coupon Register API Error', [
                    'error' => curl_error($ch),
                ]);
            }
            curl_close($ch);
            $resp = json_decode($apiResponse, true);

            \Log::info('Coupon Register API Response', ['response' => $resp]);

            $sessionData = JwtService::createDeviceSession($customer, $request);

            return response()->json([
                'message'       => 'Customer Registered Successfully',
                'data'          => $customer,
                'access_token'  => $sessionData['access_token'],
                'refresh_token' => $sessionData['refresh_token'],
                'session_id'    => $sessionData['session_id'],
                'token_type'    => 'Bearer'
            ]);
        }
    }

    /**
     * Sign In
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function signin(Request $request) {

        $validator = Validator::make($request->all(), [
            'mobile'     => 'required|numeric',
            'password'  => 'required|string'
          ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $customer = Customer::select('id', 'name', 'email', 'password', 'phone')->where('phone', $request->mobile)->where('status', 'activated')->first();

        if (!$customer || !Hash::check($request->password, $customer->password)) {
            return response()->json([
                'message'       => 'Invalid Mobile Number or Password or Inactive Status',
            ]);
        }

        $address = Address::where('customer_id', $customer->id)->get();

        if(!$address->isEmpty()) {
            if ($address->count() == 1) {
                $original = $address->first()->replicate(); // clone the model
                $original->id = -1; // change ID
                $address->push($original); // add to collection
            }

            $customer->addresses = $address;
        }

        $sessionData = JwtService::createDeviceSession($customer, $request);

        return response()->json([
            'message'       => 'Login Successfully',
            'data'          => $customer,
            'access_token'  => $sessionData['access_token'],
            'refresh_token' => $sessionData['refresh_token'],
            'session_id'    => $sessionData['session_id'],
            'token_type'    => 'Bearer'
        ]);
    }

    /**
     * Refresh JWT Access Token
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh(Request $request) {
        // Read refresh token from request payload, header, or cookie
        $refreshToken = $request->input('refresh_token') ?? $request->bearerToken() ?? $request->cookie('refresh_token');
        if (!$refreshToken) {
            return response()->json(['message' => 'Refresh token is required'], 401);
        }

        $sessionData = JwtService::refreshDeviceSession($refreshToken, $request);
        if (!$sessionData) {
            return response()->json(['message' => 'Invalid or expired refresh token'], 401);
        }

        // Return new access token and fresh refresh token in JSON response
        return response()->json([
            'access_token'  => $sessionData['access_token'],
            'refresh_token' => $sessionData['refresh_token'],
            'session_id'    => $sessionData['session_id'],
            'token_type'    => 'Bearer',
        ]);
    }

    /**
     * Sign Out (Revokes current device session & token)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function signout(Request $request) {
        $token = $request->bearerToken() ?? $request->query('token');
        if ($token) {
            JwtService::invalidateToken($token);
        }

        // Also invalidate the refresh token if provided
        $refreshToken = $request->input('refresh_token') ?? $request->cookie('refresh_token');
        if ($refreshToken) {
            JwtService::invalidateToken($refreshToken);
        }

        return response()->json(['message' => 'Logged Out Successfully']);
    }

    /**
     * List Active Device Sessions
     */
    public function getSessions(Request $request) {
        $customer = Auth::user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $currentSessionId = $request->attributes->get('current_session_id') ?? $customer->current_session_id ?? null;
        $sessions = JwtService::getCustomerSessions($customer->id, $currentSessionId);

        return response()->json([
            'status'   => 'success',
            'sessions' => $sessions
        ]);
    }

    /**
     * Revoke Specific Device Session
     */
    public function revokeSession(Request $request) {
        $customer = Auth::user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $sessionId = $request->input('session_id');
        if (!$sessionId) {
            return response()->json(['message' => 'Session ID is required'], 400);
        }

        $success = JwtService::revokeSession($customer->id, $sessionId);

        return response()->json([
            'status'  => $success ? 'success' : 'error',
            'message' => $success ? 'Device session revoked successfully' : 'Session not found',
        ]);
    }

    /**
     * Revoke All Other Device Sessions
     */
    public function revokeOtherSessions(Request $request) {
        $customer = Auth::user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $currentSessionId = $request->attributes->get('current_session_id') ?? $customer->current_session_id ?? '';
        $revokedCount = JwtService::revokeOtherSessions($customer->id, $currentSessionId);

        return response()->json([
            'status'  => 'success',
            'message' => "All other device sessions ($revokedCount) revoked successfully",
        ]);
    }



    public function getCustomer(Request $request)
    {
        $customer = Auth::user() ?? Auth::guard('api')->user();

        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($customer);
    }

    public function sendOTP(Request $request) {

        $validator = Validator::make($request->all(), [
            'mobile'     => 'required|numeric',
            ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }        

        $raw_mobile = (string) $request->mobile;
        $clean_mobile = $raw_mobile;
        
        if (substr($raw_mobile, 0, 1) === '0') {
            $clean_mobile = substr($raw_mobile, 1);
        }
        
        $final_mobile = "971" . $clean_mobile;

        $otp = rand(1111, 9999);
        $ch = curl_init();

        $passw = "11F2";
        $pass = "$";
        $p = "E89_6C3";
        $password = $passw.$pass.$p;

        $sms_params = [
            'userid' => 'MIM2300278',
            'pwd'    => $password,
            'mobile' => $final_mobile,
            'sender' => 'Ahmedper',
            'msg'    => $otp . ' is your OTP for Registration',
            'msgtype'=> '16'
        ];
        $sms_url = "https://myinboxmedia.ae/api/mim/SendSMS?" . http_build_query($sms_params);

        curl_setopt($ch, CURLOPT_URL, $sms_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            // echo 'Error:' . curl_error($ch);die;
            \Log::error("Signup SMS API Connection Error: " . curl_error($ch));
        }
        \Log::info("Signup SMS API Raw Response: " . $result);
        curl_close ($ch);

        $curl = curl_init();

        $wa_payload = [
            "ProfileId" => "MIM2400074",
            "APIKey" => "#JpXt4fbMCFj",
            "MobileNumber" => (string)$final_mobile, // Send as string to be safe, or int if API strictly requires it
            "templateName" => "websiteauthentication",
            "Parameters" => [
                (string)$otp 
            ],
            "HeaderType" => "Text",
            "Text" => "",
            "MediaUrl" => "",
            "Latitude" => 0,
            "Longitude" => 0,
            "isTemplate" => "true",
            "ButtonOrListJSON" => "",
            "SubClientCode" => "",
            "HeaderParameter" => "",
            "CTAButtonURLParameter" => "",
            "CTAButtonURLParameter2" => ""
        ];

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://waba.myinboxmedia.in/api/sendwaba',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($wa_payload),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (curl_errno($curl)) {
            \Log::error("Signup WA API Connection Error: " . curl_error($curl));
        }

        curl_close($curl);
        // echo $response;
        \Log::info("Signup WA API Status: $http_status | Raw Response: " . $response);

        if($request->flag == 'fpassword') {
            $customer = Customer::select('id', 'name', 'email', 'phone')->where('phone', $request->mobile)->first();

            if (!$customer) {
                return response()->json([
                    'message'       => 'Invalid Mobile Number',
                ]);
            }

            $customer->otp = $otp;
            $customer->save();
        } else {
            $mobile_verification = MobileVerification::where('phone', $request->mobile)->get();

            if ($mobile_verification) {
                foreach ($mobile_verification as $key => $value) {
                    MobileVerification::where('phone', $value->phone)->delete();
                }
            }

            $Mobile_verification = MobileVerification::create([
                'otp'     => $otp,
                'phone'     => $request->mobile,
            ]);

            $Mobile_verification->save();
        }

        return response()->json([
            'message'          => 'OTP Sent on Above Mobile Number'
        ]);
    }
    public function submitReview(Request $request)
    {
        
        Review::create([
            'product_id'     => $request?->order_id, // or set if needed
            // 'customer_id'    => $request?->id,
            'customer_name'  => $request?->customer_name ?? 'Guest',
            // 'customer_email' => null,
            'star'           => $request->star ?? 0,
            'comment'        => $request->comment ?? '',
            // 'status'         => 'published', // or 'pending' if needed
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Review submitted successfully.',
        ]);
    }


}

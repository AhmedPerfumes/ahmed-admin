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
// use Botble\Ecommerce\Models\Discount;
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

        curl_setopt($ch, CURLOPT_URL, "https://myinboxmedia.in/api/mim/SendSMS?userid=MIM2300278&pwd=".$password."&mobile=971".ltrim($request->mobile, $request->mobile[0])."&sender=Ahmedper&msg=".$otp."".urlencode(' is your OTP for Registration')."&msgtype=16");
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

            // $customer = OrderAddress::select('ec_order_addresses.id', 'name', 'email', 'phone')->join('payments', 'payments.order_id', '=', 'ec_order_addresses.order_id')->where('status', 'completed')->where('phone', $request->mobile)->get();

            // echo "<pre>";print_r($customer);

            // $coupon = DiscountModel::where('code', 'WELCOME10')->where('start_date', '<=', now())->where('end_date', '>=', now())->first();

            return response()->json([
                'message'       => 'OTP Verified Successfully',
                // 'customer'          => !$customer->isEmpty() ? false : true,
                // 'coupon'            => $coupon
            ]);
        } else {
            // $customer = Customer::select('id', 'name', 'email', 'phone')->where('phone', $request->mobile)->where('otp', $request->otp)->first();

            // if (!$customer) {
            //     return response()->json([
            //         'message'       => 'Invalid Mobile Number or OTP',
            //     ]);
            // }

            // $customer->otp = 0;
            // $customer->save();

            $validator = Validator::make($request->all(), [
                'mobile'     => 'required|numeric',
                'otp'  => 'required|numeric'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }

            // $mobile_verification = MobileVerification::where('phone', $request->mobile)->where('otp', $request->otp)->orderBy('id', 'desc')->first();

            // if (!$mobile_verification) {
            //     return response()->json([
            //         'message'       => 'Invalid Mobile Number or OTP',
            //     ]);
            // }

            // $mobile_verification->otp = 0;
            // $mobile_verification->save();

            $validator = Validator::make($request->all(), [
                // 'customer_id'      => 'required',
                'name' => 'required',
                'email' => 'required|email|unique:ec_customers,email,',
                'mobile' => 'required|unique:ec_customers,phone,',
                // 'password' => 'required',
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
                // echo 'Error:' . curl_error($ch);
                \Log::info('Coupon Register API Response', [
                    'error' => curl_error($ch),
                ]);
            }
            curl_close($ch);
            // echo "<pre>";print_r($apiResponse);

            // Optionally log the API response for debugging
            // \Log::info('Coupon/Register API Response', [
            //     'http_code' => $httpCode,
            //     'response'  => $apiResponse,
            // ]);


            // $coupons = DiscountModel::select('code', 'value', 'start_date', 'end_date')->where('target', 'customer')->where('customer_id', $customer->id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discount_customers', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();

            // // Manually transform into an array with formatted strings
            // $formattedCoupons = $coupons->map(function ($coupon) {
            //     return [
            //         'code'       => $coupon->code,
            //         'value'      => $coupon->value,
            //         'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
            //         'end_date'   => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
            //         'type'       => 'customer',
            //     ];
            // })->toArray();

            // $customer->coupon = $formattedCoupons;

            $token = $customer->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'       => $request->flag == 'fpassword' ? 'OTP Verified Successfully' : 'Customer Registered Successfully',
                'data'          => $customer,
                'access_token'  => $token,
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

        // $coupons = DiscountModel::select('code', 'value', 'start_date', 'end_date')->where('target', 'customer')->where('customer_id', $customer->id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discount_customers', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();

        // Manually transform into an array with formatted strings
        // $formattedCoupons = $coupons->map(function ($coupon) {
        //     return [
        //         'code'       => $coupon->code,
        //         'value'      => $coupon->value,
        //         'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
        //         'end_date'   => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
        //         'type'       => 'customer',
        //     ];
        // })->toArray();

        // $customer->coupon = $formattedCoupons;

        $address = Address::where('customer_id', $customer->id)->get();

        if(!$address->isEmpty()) {
            if ($address->count() == 1) {
                $original = $address->first()->replicate(); // clone the model
                $original->id = -1; // change ID
                $address->push($original); // add to collection
            }

            $customer->addresses = $address;
        }

        $token = $customer->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'       => 'Login Successfully',
            'data'          => $customer,
            'access_token'  => $token,
            'token_type'    => 'Bearer'
        ]);
    }

    /**
     * Sign Out
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function signout() {
        $customer = Auth::guard('api')->user();
        if (!$customer) {
            return response()->json(['message' => 'No Active Session'], 401);
        }
        $customer->tokens()->delete();
        return response()->json(['message' => 'Logged Out Successfully']);
    }

    public function getCustomer(Request $request)
    {
        $customer = Auth::guard('api')->user();

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

        $otp = rand(1111, 9999);

        $ch = curl_init();

        $passw = "11F2";
        $pass = "$";
        $p = "E89_6C3";
        $password = $passw.$pass.$p;

        curl_setopt($ch, CURLOPT_URL, "https://myinboxmedia.in/api/mim/SendSMS?userid=MIM2300278&pwd=".$password."&mobile=971".ltrim($request->mobile, $request->mobile[0])."&sender=Ahmedper&msg=".$otp."".urlencode(' is your OTP for Registration')."&msgtype=16");
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

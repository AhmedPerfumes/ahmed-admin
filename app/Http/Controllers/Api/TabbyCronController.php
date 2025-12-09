<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Botble\Ecommerce\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderHistory;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\OrderHistoryActionEnum;
use Botble\Ecommerce\Services\CreatePaymentForOrderService;
use Botble\Ecommerce\Models\OrderAddress;
use Botble\Ecommerce\Models\Address;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\Invoice;
use Botble\Ecommerce\Models\InvoiceItem;
use Botble\Ecommerce\Facades\Discount;
use Botble\Ecommerce\Models\DiscountProduct;
use Botble\Ecommerce\Models\Discount as DiscountModel;
use Botble\Ecommerce\Models\MobileVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TabbyCronController extends Controller
{
    public function tabbyAllPayments(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        // echo "<pre>";print_r($request->all());die;
        // $request->query('email');die;
        // $payment_id = $request->input('payment_id') ? $request->input('payment_id') : $request->query('payment_id');
        // $customer = Customer::where('email', base64_decode($request->query('email')))->first();
        // $order = Order::where('user_id', $customer->id)->orderBy('id', 'desc')->first();
        // echo "<pre>";print_r($order);
        // echo Carbon::now()->toDateString();die;
        $BASE_URL = 'https://api.tabby.ai/api/v2/payments?created_at__gte='.Carbon::now()->toDateString().'&created_at__lte='.Carbon::now()->toDateString().'&limit=10&offset=0';
        $SERVER_KEY = env('TABBY_SECRET_KEY');
        // $SERVER_KEY = 'sk_test_019228fd-8e52-3ecd-f813-bf12445e44d4';

        // Initialize cURL session
        $ch = curl_init();

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $BASE_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response instead of outputting it
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification (useful for testing)
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'authorization: Bearer ' . $SERVER_KEY,
        ]);
        // Execute cURL session
        // $response = curl_exec($ch);

        // Check for errors
        // if (curl_errno($ch)) {
        //     echo 'cURL Error: ' . curl_error($ch);
        // }

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        // echo "<pre>";print_r($response);die;
        foreach ($response['payments'] as $key => $value) {
            $order = Order::where('code', $value['order']['reference_id'])->orderBy('id', 'desc')->first();
            // echo "<pre>";print_r($order);die;
            if($value['status'] == 'AUTHORIZED' || $value['status'] == 'authorized') {

                // Initialize cURL session
                $c = curl_init();

                // Set cURL options
                curl_setopt_array($c, array(
                    CURLOPT_URL => 'https://api.tabby.ai/api/v2/payments/'.$value["id"].'/captures',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => json_encode(["amount" => $value["amount"], true]),
                    CURLOPT_HTTPHEADER => array(
                        'authorization: Bearer ' . $SERVER_KEY,
                        'Content-Type:application/json'
                    ),
                ));
        
                $resp = json_decode(curl_exec($c), true);
                curl_close($c);
                // echo "<pre>";print_r($resp);die;
                
                $createPaymentForOrderService->execute(
                    $order,
                    'tabby',
                    $resp['status'],
                    $order->user_id,
                    $value["id"],
                    (isset($resp['description']) && !empty($resp['description'])) ? $resp['description'] : $resp['status'],
                );
            }   
        }
        return response()->json([
            'message'          => 'Record Updated Successfully'
        ]);
        // else {
        //     $createPaymentForOrderService->execute(
        //         $order,
        //         'tabby',
        //         $response['status'],
        //         $customer->id,
        //         $request->input('payment_id'),
        //         (isset($response['description']) && !empty($response['description'])) ? $response['description'] : $response['status'],
        //     );
        // }

        // header('Location: http://localhost:3000/'.$order->lang.'/shop-order-payment-complete?q='.base64_encode($order->code));exit();
    }
}

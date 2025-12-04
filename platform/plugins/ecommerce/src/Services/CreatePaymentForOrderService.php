<?php

namespace Botble\Ecommerce\Services;

use Botble\ACL\Models\User;
use Botble\Ecommerce\Enums\OrderHistoryActionEnum;
use Botble\Ecommerce\Events\OrderPaymentConfirmedEvent;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderHistory;
use Botble\Payment\Enums\PaymentStatusEnum;
use Botble\Payment\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Botble\Ecommerce\Models\OrderAddress;
use Botble\Ecommerce\Models\Address;
use Botble\Ecommerce\Models\OrderProduct;
use PHPMailer\PHPMailer\PHPMailer;
use App\Models\ActiveCoupon;

class CreatePaymentForOrderService
{
    public function execute(
        Order $order,
        string $paymentMethod,
        string $paymentStatus = PaymentStatusEnum::PENDING,
        string|int|null $customerId = null,
        ?string $chargeId = null,
        ?string $description = null

    ): void {
        if (! is_plugin_active('payment')) {
            return;
        }

        if ($order->payment->exists) {
            $order->payment->update([
                'payment_channel' => $paymentMethod,
                'status' => $paymentStatus,
                'description' => $description,
            ]);
        }

        /**
         * @var User $user
         */
        $user = !$customerId ? Auth::user() : Auth::guard('api')->user();

        if($paymentMethod == 'cod') {
            $paymentStat = $paymentStatus ? $paymentStatus : 'completed';
        } else {
            $paymentStat = (($paymentStatus == 'fully_captured') || ($paymentStatus == 'A') || ($paymentStatus == 'AUTHORIZED' || $paymentStatus == 'CREATED' || $paymentStatus == 'CLOSED')) ? 'completed' : 'failed';
        }
        $data = [
            'amount' => $order->amount,
            'currency' => cms_currency()->getDefaultCurrency()->title,
            'payment_channel' => $paymentMethod,
            'status' => $paymentStat,
            'payment_type' => 'confirm',
            'order_id' => $order->getKey(),
            'charge_id' => $chargeId,
            'user_id' => !$customerId ? $user->getKey() : $customerId,
            'description' => $description,
        ];

        if ($customerId) {
            $data = [
                ...$data,
                'customer_id' => $customerId,
                'customer_type' => Customer::class,
            ];
        }

        $payment = Payment::query()->create($data);

        $order->payment_id = $payment->getKey();
        $order->save();

        $shipping_data = OrderAddress::where('order_id', $order->getKey())->first();

        $billing_data = Address::where('customer_id', $order->user_id)->first();

        $order_products = OrderProduct::where('order_id', $order->getKey())->get();

        if($paymentStat == 'completed' || $paymentMethod == 'cod') {
            $activeCoupon = ActiveCoupon::where('order_id', $order->id)->first();
            if ($activeCoupon) {
                try {
                    $curl = curl_init();
                    $payload = [
                        'couponRegistrationId' => $activeCoupon->couponRegistrationId,
                        'refDocNo'             => $order->code,
                        'salesType'            => $activeCoupon->salesType,
                        'company'              => $activeCoupon->company,
                        'whsCode'              => $activeCoupon->whsCode,
                        'custNo'               => $customerId,
                        'mobileNo'             => $shipping_data->phone ?? '',
                        'netAmount'            => $order->amount,
                    ];
                    if ($activeCoupon->couponRegistrationId == 0) {
                        $payload['couponCode'] = $activeCoupon->couponCode;
                    }
                    \Log::info('Redeem Payload: ' . json_encode($payload));
                        curl_setopt_array($curl, [
                        CURLOPT_URL            => env('SMART_VIEW_COUPON_API_URL') . 'Coupon/Redeem',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING       => '',
                        CURLOPT_MAXREDIRS      => 10,
                        CURLOPT_TIMEOUT        => 0,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST  => 'POST',
                        CURLOPT_POSTFIELDS     => json_encode($payload),
                        CURLOPT_HTTPHEADER     => [
                            'Content-Type: application/json'
                        ],
                    ]);
                    $response = curl_exec($curl);
                    $curlError = curl_error($curl);

                    \Log::info('Redeem API Response: ' . $response);

                    if ($curlError) {
                        // Handle cURL-level errors (e.g., timeout, connection failed)
                        \Log::error('Redeem API cURL Error: ' . $curlError);
                        $activeCoupon->status = 'Redeem cURL Error';
                    } else {
                        $responseData = json_decode($response);

                        // Check if JSON decoded and responseType is 0 (assuming 0 is success)
                        if ($responseData && isset($responseData->responseType) && $responseData->responseType == 0) {
                            $activeCoupon->status = 'Redeemed';
                        } else {
                            // Store the API's error message, or a generic failure
                            $errorMessage = $responseData->message ?? 'Redeem Failed';
                            $activeCoupon->status = !empty($errorMessage) ? Str::limit($errorMessage, 250) : 'Redeem Failed';
                        }
                    }

                    $activeCoupon->save();
                    curl_close($curl);
                } catch (\Exception $e) {
                    \Log::error('Redeem API Error: ' . $e->getMessage());
                    
                    $activeCoupon->status = 'Redeem Exception';
                    $activeCoupon->save();
                }
            }
            
            
            // if (!empty($order->coupon_code)) {
            //     try {
            //         $curl = curl_init();
            //         $payload = [
            //             'couponRegistrationId' => $couponData->data[0]->couponRegistrationId,
            //                 // 'couponId'             => $decode->data[0]->couponId,
            //                 'refDocNo'             => $order->code,
            //                 'salesType'            => $couponData->data[0]->salesType,
            //                 'company'              => $couponData->data[0]->company,
            //                 'whsCode'              => $couponData->data[0]->whsCode,
            //                 'custNo'               => $customerId,
            //                 'mobileNo'             => $shipping_data->phone ?? '',
            //                 // 'discAmount'           => 27.50,
            //                 'netAmount'            => $order->amount,
            //         ];
            //         if (($order->couponRegistrationId ?? 0) == 0) { $payload['couponCode'] = $order->coupon_code; }
            //         \Log::info('Redeem Payload: ' . json_encode($payload));
            //         curl_setopt_array($curl, [
            //             CURLOPT_URL            => env('SMART_VIEW_COUPON_API_URL') . 'Coupon/Redeem',
            //             CURLOPT_RETURNTRANSFER => true,
            //             CURLOPT_ENCODING       => '',
            //             CURLOPT_MAXREDIRS      => 10,
            //             CURLOPT_TIMEOUT        => 0,
            //             CURLOPT_FOLLOWLOCATION => true,
            //             CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            //             CURLOPT_CUSTOMREQUEST  => 'POST',
            //             CURLOPT_POSTFIELDS     => json_encode($payload),
            //             CURLOPT_HTTPHEADER     => [
            //                 'Content-Type: application/json'
            //             ],
            //         ]);

            //         $response = curl_exec($curl);
            //         \Log::info('Redeem API Response: ' . $response);

            //         curl_close($curl);
            //     } catch (\Exception $e) {
            //         \Log::error('Redeem API Error: ' . $e->getMessage());
            //     }
            // }
            
            $ch = curl_init();

            $passw = "11F2";
            $pass = "$";
            $p = "E89_6C3";
            $password = $passw.$pass.$p;

            curl_setopt($ch, CURLOPT_URL, "https://myinboxmedia.in/api/mim/SendSMS?userid=MIM2300278&pwd=".$password."&mobile=971".ltrim($shipping_data->phone, $shipping_data->phone[0])."&sender=Ahmedper&msg=".urlencode('"Dear '. $shipping_data->name .', Thank you for your order '. $order->code .'. Your order is being processed. Please wait for confirmation call! Your Total Bill = '. floatval($order->amount) .'AED"')."&msgtype=16");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

            $result = curl_exec($ch);

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
                "MobileNumber": 971'.ltrim($shipping_data->phone, $shipping_data->phone[0]).',
                "templateName": "ordersucessnotification",
                "Parameters": [
                    "'.$order->code.'",
                    '.floatval($order->amount).'      
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

            $mail = new PHPMailer(true);
        
            /* Email SMTP Settings */
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = env('MAIL_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = env('MAIL_USERNAME');
            $mail->Password = env('MAIL_PASSWORD');
            $mail->SMTPSecure = env('MAIL_ENCRYPTION');
            $mail->Port = env('MAIL_PORT');

            $mail->setFrom(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'));
            $mail->addAddress($shipping_data->email);
            $mail->addCC(env('MAIL_FROM_ADDRESS'));

            $mail->isHTML(true);

            $mail->Subject = 'Your Ahmed Al Maghribi Perfumes order has been received!';

            $body = '<table style="text-align:center;background-color:#F7F7F7;width:100%;">
                <tbody>
                    <tr>
                        <td style="text-align:center;direction:ltr;"></td>
                        <td style="text-align:center;direction:ltr;width:600px;">
                            <div style="width:100%;max-width:600px;margin:0 auto;padding:70px 0;" dir="ltr">
                                <table style="width:100%;border-spacing:0;border-collapse:collapse;box-sizing:border-box;" cellpadding="0" cellspacing="0">
                                    <tbody>
                                        <tr>
                                            <td style="vertical-align:top;" align="center">
                                                <div>
                                                    <p style="margin-top:0;margin-bottom:0;">
                                                        <span style="font-size:14px;">
                                                            <b>
                                                                <img style="display:inline-block;max-width:100%;margin:0;border-width:4px;" alt="Ahmed Al Maghribi Perfumes" src="https://www.ahmedalmaghribi.com/wp-content/uploads/2021/09/Ahmed-Logo-150x150.png" data-imagetype="External">
                                                            </b>
                                                        </span>
                                                    </p>
                                                </div>
                                                <table style="background-color:white;width:100%;border-spacing:0;border-collapse:collapse;border-radius:3px;border:1px solid #DEDEDE;box-sizing:border-box;" cellpadding="0" cellspacing="0">
                                                    <tbody>
                                                        <tr>
                                                            <td style="vertical-align:top;" align="center">
                                                                <table style="color:white;background-color:#C7944B;width:100%;border-spacing:0;border-collapse:collapse;border-radius:3px;box-sizing:border-box;line-height:100%;" cellpadding="0" cellspacing="0">
                                                                    <tbody>
                                                                        <tr>
                                                                            <td style="padding:36px 48px;line-height:100%;">
                                                                                <h1 style="color:white;font-size:30px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;font-weight:300;text-align:left;margin:0;line-height:150%;">Thank you for your order</h1>
                                                                            </td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="vertical-align:top;" align="center">
                                                                <table style="width:100%;border-spacing:0;border-collapse:collapse;box-sizing:border-box;" cellpadding="0" cellspacing="0">
                                                                    <tbody>
                                                                        <tr>
                                                                            <td style="vertical-align:top;background-color:white;">
                                                                                <table style="width:100%;border-spacing:0;border-collapse:collapse;box-sizing:border-box;" cellpadding="20" cellspacing="0">
                                                                                    <tbody>
                                                                                        <tr>
                                                                                            <td style="vertical-align:top;padding:48px 48px 32px 48px;">
                                                                                                <div style="color:#636363;font-size:14px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;line-height:150%;" align="left">
                                                                                                    <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Hi '. $shipping_data->name .',</p>
                                                                                                    <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Just to let you know - we have received your order '. $order->code .', and it is now being processed:</p>
                                                                                                    '.($paymentMethod == 'cod' ? '<p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Pay with cash upon delivery.</p>' : '').'
                                                                                                    <h2 style="color:#C7944B;font-size:18px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;display:block;margin:0 0 18px 0;line-height:130%;">[Order '. $order->code .'] ('.date_format(date_create($order->created_at), "F j, Y").')</h2>
                                                                                                    <div style="margin-bottom:40px;">
                                                                                                        <table style="color:#636363;width:100%;border-spacing:0;border-collapse:collapse;border:1px solid #E5E5E5;box-sizing:border-box;" cellpadding="6" cellspacing="0">
                                                                                                            <tbody>
                                                                                                                <tr>
                                                                                                                    <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Product</div>
                                                                                                                    </th>
                                                                                                                    <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Quantity</div>
                                                                                                                    </th>
                                                                                                                    <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Price</div>
                                                                                                                    </th>
                                                                                                                </tr>';

                                                                                                                foreach ($order_products as $key => $value) {
                                                                                                                    if($value->discount_percent != 0) {
                                                                                                                        if($value->is_gift == 1) {
                                                                                                                             $body .= '<tr>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->product_name.'</div>
                                                                                                                                </td>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->qty.'</div>
                                                                                                                                </td>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; 0.00 (Free Gift)'.'</div>
                                                                                                                                </td>
                                                                                                                            </tr>';
                                                                                                                        } else {
                                                                                                                            $body .= '<tr>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->product_name.'</div>
                                                                                                                                </td>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->qty.'</div>
                                                                                                                                </td>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625;'.round((($value->price * 1.05 - ($value->price * 1.05 * $value->discount_percent / 100)) * $value->qty), 2).'</div>
                                                                                                                                </td>
                                                                                                                            </tr>';
                                                                                                                        }
                                                                                                                    }
                                                                                                                    // else if($value->product_category == 'Collections') {
                                                                                                                    //     $body .= '<tr>
                                                                                                                    //         <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                    //             <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->product_name.'</div>
                                                                                                                    //         </td>
                                                                                                                    //         <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                    //             <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->qty.'</div>
                                                                                                                    //         </td>
                                                                                                                    //         <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                    //             <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625;'.round($value->gross_amount, 2).'</div>
                                                                                                                    //         </td>
                                                                                                                    //     </tr>';
                                                                                                                    // }
                                                                                                                    else {
                                                                                                                        if($value->discount_amount != '0') {
                                                                                                                            $body .= '<tr>
                                                                                                                            <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->product_name.'</div>
                                                                                                                            </td>
                                                                                                                            <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->qty.'</div>
                                                                                                                            </td>
                                                                                                                            <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625;'.round($value->gross_amount, 2).'</div>
                                                                                                                            </td>
                                                                                                                            </tr>';
                                                                                                                        }
                                                                                                                        else {
                                                                                                                            $body .= '<tr>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->product_name.'</div>
                                                                                                                                </td>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">'.$value->qty.'</div>
                                                                                                                                </td>
                                                                                                                                <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                                    <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625;'.round((($value->price * (1 + $value->vat / 100)) * $value->qty), 2).'</div>
                                                                                                                                </td>
                                                                                                                                </tr>';
                                                                                                                        }
                                                                                                                    }
                                                                                                                }
                                                                                                                
                                                                                                                $body .= '<tr>
                                                                                                                    <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border-width:4px 1px 1px 1px;border-style:solid;border-color:#E5E5E5;" colspan="2">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Subtotal:</div>
                                                                                                                    </th>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border-width:4px 1px 1px 1px;border-style:solid;border-color:#E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625;'.$order->sub_total.'</div>
                                                                                                                    </td>
                                                                                                                </tr>
                                                                                                                <tr>
                                                                                                                    <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Shipping:</div>
                                                                                                                    </th>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">
                                                                                                                            '.($order->shipping_amount == '0.00' ? 'You Got Free Shipping' : round(($order->shipping_amount * 1.05), 2)).'&#x62F;&#x2E;&#x625;
                                                                                                                        </div>
                                                                                                                    </td>

                                                                                                                </tr>
                                                                                                                <tr>
                                                                                                                    <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Service Fee: </div>
                                                                                                                    </th>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625;'.round(($order->service_amount * 1.05), 2).'</div>
                                                                                                                    </td>
                                                                                                                </tr>';
                                                                                                                if ($order->cod_charge != '0.00') {
                                                                                                                    $body .= '<tr>
                                                                                                                        <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">COD Charges: </div>
                                                                                                                        </th>
                                                                                                                        <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625;'.round(($order->cod_charge * 1.05), 2).'</div>
                                                                                                                        </td>
                                                                                                                    </tr>';
                                                                                                                }
                                                                                                                $body .= '<tr>
                                                                                                                    <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Payment method:</div>
                                                                                                                    </th>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">
                                                                                                                            '.($paymentMethod == 'cod' ? 'Cash on delivery' : $paymentMethod).'
                                                                                                                        </div>
                                                                                                                    </td>
                                                                                                                </tr>
                                                                                                                <tr>
                                                                                                                    <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Total:</div>
                                                                                                                    </th>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625;'.$order->amount.'
                                                                                                                            <small style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                                <div style="text-align:left;">(includes &#x62F;&#x2E;&#x625;'.round(($order->tax_amount), 2).' VAT)</div>
                                                                                                                            </small>
                                                                                                                        </div>
                                                                                                                    </td>
                                                                                                                </tr>
                                                                                                            </tbody>
                                                                                                        </table>
                                                                                                    </div>
                                                                                                    <table style="width:100%;border-spacing:0;border-collapse:collapse;margin-bottom:40px;box-sizing:border-box;" cellpadding="0" cellspacing="0">
                                                                                                        <tbody>
                                                                                                            <tr>
                                                                                                                <td style="text-align:left;vertical-align:top;width:50%;">
                                                                                                                    <h2 style="color:#C7944B;font-size:18px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;display:block;margin:0 0 18px 0;line-height:130%;">Billing address</h2>
                                                                                                                    <address style="color:#636363;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;padding:12px;border:1px solid #E5E5E5;">'.$billing_data->name.'<br> '.$billing_data->address.'<br>'.$billing_data->state.'<br>'.$billing_data->city.'<br>
                                                                                                                        <span style="color:#C7944B;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                            <u>
                                                                                                                                 <a style="color:#C7944B;" href="" title="tel:+971'.ltrim($billing_data->phone, $billing_data->phone[0]).'">+971'.ltrim($billing_data->phone, $billing_data->phone[0]).'</a>
                                                                                                                            </u>
                                                                                                                        </span><br>
                                                                                                                        <span style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                            <a href="" title="mailto:'.$billing_data->email.'">'.$billing_data->email.'</a>
                                                                                                                        </span>
                                                                                                                    </address>
                                                                                                                </td>
                                                                                                                <td style="text-align:left;vertical-align:top;width:50%;">
                                                                                                                    <h2 style="color:#C7944B;font-size:18px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;display:block;margin:0 0 18px 0;line-height:130%;">Shipping address</h2>
                                                                                                                    <address style="color:#636363;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;padding:12px;border:1px solid #E5E5E5;">'.$shipping_data->name.'<br> '.$shipping_data->address.'<br>'.$shipping_data->state.'<br>'.$shipping_data->city.'<br>
                                                                                                                        <span style="color:#C7944B;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                            <u>
                                                                                                                                <a style="color:#C7944B;" href="" title="tel:+971'.ltrim($shipping_data->phone, $shipping_data->phone[0]).'">+971'.ltrim($shipping_data->phone, $shipping_data->phone[0]).'</a>
                                                                                                                            </u>
                                                                                                                        </span><br>
                                                                                                                        <span style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                            <a href="" title="mailto:'.$shipping_data->email.'">'.$shipping_data->email.'</a>
                                                                                                                        </span>
                                                                                                                    </address>
                                                                                                                </td>
                                                                                                            </tr>
                                                                                                        </tbody>
                                                                                                    </table>
                                                                                                    <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Thanks for using <a style="margin-top:0;margin-bottom:0;" target="_blank" href="http://www.ahmedalmaghribi.com" title="http://www.ahmedalmaghribi.com">www.ahmedalmaghribi.com</a>!</p>
                                                                                                </div>
                                                                                            </td>
                                                                                        </tr>
                                                                                    </tbody>
                                                                                </table>
                                                                            </td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="vertical-align:top;" align="center">
                                                <table style="width:100%;border-spacing:0;border-collapse:collapse;box-sizing:border-box;" cellpadding="10" cellspacing="0">
                                                    <tbody>
                                                        <tr>
                                                            <td style="vertical-align:top;border-radius:6px;">
                                                                <table style="width:100%;border-spacing:0;border-collapse:collapse;box-sizing:border-box;" cellpadding="10" cellspacing="0">
                                                                    <tbody>
                                                                        <tr>
                                                                            <td style="color:#3C3C3C;text-align:center;vertical-align:middle;border-radius:6px;padding-top:24px;padding-bottom:24px;line-height:150%;" colspan="2">
                                                                                <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:center;margin:0 0 16px 0;line-height:150%;">
                                                                                    <span style="font-size:12px;">Ahmed Al Maghribi Perfumes LLC</span>
                                                                                </p>
                                                                            </td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                        <td style="text-align:center;direction:ltr;"></td>
                    </tr>
                </tbody>
            </table>';

            $mail->Body   = $body;

            $mail->send();

            // $date = '2025-08-31 23:59:00';

            // if($order->amount >= 250 && now() < $date) {
            //     $mail2 = new PHPMailer(true);

            //     /* Email SMTP Settings */
            //     $mail2->SMTPDebug = 0;
            //     $mail2->isSMTP();
            //     $mail2->Host = env('MAIL_HOST');
            //     $mail2->SMTPAuth = true;
            //     $mail2->Username = env('MAIL_USERNAME');
            //     $mail2->Password = env('MAIL_PASSWORD');
            //     $mail2->SMTPSecure = env('MAIL_ENCRYPTION');
            //     $mail2->Port = env('MAIL_PORT');

            //     $mail2->setFrom(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'));
            //     $mail2->addAddress($shipping_data->email);
            //     $mail2->addCC(env('MAIL_FROM_ADDRESS'));

            //     $mail2->isHTML(true);

            //     $mail2->Subject = 'Congratulations You Have Entered the Draw';

            //     $body2 = '<div>
            //                 <p>Dear customer,</p>
            //                 <img alt="Ahmed Al Maghribi Perfumes" src="https://admin.ahmedalmaghribi.com/public/storage/emailer-1.jpg">                        
            //             </div>';

            //     $mail2->Body = $body2;

            //     $mail2->send();
            // }

        }

            
        if ($paymentStat == PaymentStatusEnum::COMPLETED) {
            !$customerId ? event(new OrderPaymentConfirmedEvent($order, $user)) : null;

            OrderHistory::query()->create([
                'action' => OrderHistoryActionEnum::CONFIRM_PAYMENT,
                'description' => trans('plugins/ecommerce::order.payment_was_confirmed_by', [
                    'money' => format_price($order->amount),
                ]),
                'order_id' => $order->getKey(),
                'user_id' => !$customerId ? $user->getKey() : $customerId,
            ]);
        }
    }
}

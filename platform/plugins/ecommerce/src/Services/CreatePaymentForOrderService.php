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
        \Illuminate\Support\Facades\Log::info('CreatePaymentService: Started execution.', ['order_id' => $order->id]);
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

        \Log::info('[CreatePaymentForOrderService] Checking payment condition', [
            'order_id'      => $order->getKey(),
            'paymentStat'   => $paymentStat,
            'paymentMethod' => $paymentMethod,
        ]);

        if($paymentStat == 'completed' || $paymentMethod == 'cod') {
            \Log::info('[CreatePaymentForOrderService] Condition MET — entering coupon/order processing block', [
                'order_id'      => $order->getKey(),
                'paymentStat'   => $paymentStat,
                'paymentMethod' => $paymentMethod,
            ]);
            $activeCoupon = ActiveCoupon::where('order_id', $order->id)->first();

            \Log::info('[CreatePaymentForOrderService] ActiveCoupon lookup', [
                'order_id'     => $order->getKey(),
                'activeCoupon' => $activeCoupon ? $activeCoupon->toArray() : null,
            ]);

            if ($activeCoupon) {
                \Log::info('[CreatePaymentForOrderService] ActiveCoupon found — entering redeem block', [
                    'order_id'              => $order->getKey(),
                    'couponRegistrationId'  => $activeCoupon->couponRegistrationId,
                    'couponCode'            => $activeCoupon->couponCode ?? null,
                    'status'               => $activeCoupon->status,
                ]);
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
                    
                    $response = \Illuminate\Support\Facades\Http::timeout(15)->post(env('SMART_VIEW_COUPON_API_URL') . 'Coupon/Redeem', $payload);

                    \Log::info('Redeem API Response: ' . $response->body());

                    if ($response->failed()) {
                        \Log::error('Redeem API Error: ' . $response->status());
                        $activeCoupon->status = 'Redeem API Error';
                    } else {
                        $responseData = json_decode($response->body());

                        if ($responseData && isset($responseData->responseType) && $responseData->responseType == 0) {
                            $activeCoupon->status = 'Redeemed';
                        } else {
                            $errorMessage = $responseData->message ?? 'Redeem Failed';
                            $activeCoupon->status = !empty($errorMessage) ? Str::limit($errorMessage, 250) : 'Redeem Failed';
                        }
                    }

                    $activeCoupon->save();
                } catch (\Exception $e) {
                    \Log::error('Redeem API Error: ' . $e->getMessage());
                    
                    $activeCoupon->status = 'Redeem Exception';
                    $activeCoupon->save();
                }
            } else {
                \Log::info('[CreatePaymentForOrderService] No ActiveCoupon found — skipping redeem block', [
                    'order_id' => $order->getKey(),
                ]);
            }

            \Log::info('[CreatePaymentForOrderService] Exited activeCoupon if block', [
                'order_id' => $order->getKey(),
            ]);

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
            \Illuminate\Support\Facades\Log::info('CreatePaymentService: Dispatching SendOrderNotificationsJob.', ['order_id' => $order->id]);
            \App\Jobs\SendOrderNotificationsJob::dispatch($order, $shipping_data, $paymentMethod);
        } else {
            \Log::info('[CreatePaymentForOrderService] Condition NOT MET — skipping coupon/order processing block', [
                'order_id'      => $order->getKey(),
                'paymentStat'   => $paymentStat,
                'paymentMethod' => $paymentMethod,
            ]);
        }

        \Log::info('[CreatePaymentForOrderService] Exited if block', [
            'order_id' => $order->getKey(),
        ]);


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
        \Illuminate\Support\Facades\Log::info('CreatePaymentService: Finished execution.', ['order_id' => $order->id]);
    }
}

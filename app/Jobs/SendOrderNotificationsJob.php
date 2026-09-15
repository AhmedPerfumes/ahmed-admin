<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Botble\Ecommerce\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderConfirmedMail;
use Illuminate\Support\Facades\Log;

class SendOrderNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $order;
    public $shippingData;
    public $paymentMethod;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order, $shippingData, $paymentMethod)
    {
        $this->order = $order;
        $this->shippingData = $shippingData;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('SendOrderNotificationsJob: handle() started for order ID ' . $this->order->id);

        // 1. Send SMS
        Log::info('SendOrderNotificationsJob: Starting SMS.');
        $this->sendSms();

        // 2. Send WhatsApp
        Log::info('SendOrderNotificationsJob: Starting WhatsApp.');
        $this->sendWhatsApp();

        // 3. Send Email
        Log::info('SendOrderNotificationsJob: Starting Email.');
        $this->sendEmail();

        Log::info('SendOrderNotificationsJob: handle() finished.');
    }

    protected function sendSms()
    {
        try {
            $passw = "11F2";
            $pass = "$";
            $p = "E89_6C3";
            $password = $passw . $pass . $p;

            $message = '"Dear ' . $this->shippingData->name . ', Thank you for your order ' . $this->order->code . '. Your order is being processed. Please wait for confirmation call! Your Total Bill = ' . floatval($this->order->amount) . 'AED"';

            $phone = ltrim($this->shippingData->phone, $this->shippingData->phone[0]);

            Http::get("https://myinboxmedia.ae/api/mim/SendSMS", [
                'userid' => 'MIM2300278',
                'pwd' => $password,
                'mobile' => '971' . $phone,
                'sender' => 'Ahmedper',
                'msg' => $message,
                'msgtype' => 16,
            ]);
            Log::info('SendOrderNotificationsJob: SMS sent successfully.');
        } catch (\Exception $e) {
            Log::error('Send SMS Error: ' . $e->getMessage());
        }
    }

    protected function sendWhatsApp()
    {
        try {
            $phone = ltrim($this->shippingData->phone, $this->shippingData->phone[0]);
            
            Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post('https://waba.myinboxmedia.in/api/sendwaba', [
                "ProfileId" => "MIM2400074",
                "APIKey" => "#JpXt4fbMCFj",
                "MobileNumber" => "971" . $phone,
                "templateName" => "ordernotificationsuccess",
                "Parameters" => [
                    $this->order->code,
                    floatval($this->order->amount)      
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
            ]);
            Log::info('SendOrderNotificationsJob: WhatsApp sent successfully.');
        } catch (\Exception $e) {
            Log::error('Send WhatsApp Error: ' . $e->getMessage());
        }
    }

    protected function sendEmail()
    {
        try {
            Mail::mailer('smtp')->to($this->shippingData->email)
                ->cc(env('MAIL_FROM_ADDRESS', 'estore@ahmedalmaghribi.com'))
                ->send(new OrderConfirmedMail($this->order, $this->shippingData, $this->paymentMethod));
            Log::info('SendOrderNotificationsJob: Email sent successfully.');
        } catch (\Exception $e) {
            Log::error('Send Email Error: ' . $e->getMessage());
        }
    }
}

<?php

namespace App\Observers;

use Botble\Ecommerce\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Botble\Ecommerce\Enums\OrderStatusEnum;

class OrderObserver
{
    public function updated(Order $order)
    {
        $shippedStatus = OrderStatusEnum::SHIPPED()->getValue();

        if ($order->status == $shippedStatus || !empty($order->awb)) {
            
            if ($order->isDirty('status') || $order->isDirty('awb')) {
                $this->sendShippedWhatsapp($order);
            }
        }
    }

    protected function sendShippedWhatsapp(Order $order)
    {
        $raw_mobile = (string) $order->user->phone ?? null;
        $clean_mobile = $raw_mobile;
        
        if (substr($raw_mobile, 0, 1) === '0') {
            $clean_mobile = substr($raw_mobile, 1);
        }
        
        $phone = "971" . $clean_mobile;
        
        if (!$phone) {
            return;
        }

        $awb = "https://www.smsaexpress.com/ae/trackingdetails?tracknumbers%5B0%5D=" . $order->awb;
        $orderCode = $order->code;

        $curl = curl_init();

        $wa_payload = [
            "ProfileId" => "MIM2400074",
            "APIKey"    => "#JpXt4fbMCFj",
            "MobileNumber" => (string)$phone, 
            
            "templateName" => "smsatracking", 
            
            "Parameters" => [
                (string)$orderCode,
                (string)$awb
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
            Log::error("WhatsApp API Error for Order {$orderCode}: " . curl_error($curl));
        } else {
            Log::info("WhatsApp Sent for Order {$orderCode} | AWB: {$awb} | Status: $http_status | Response: " . $response);
        }

        curl_close($curl);
    }
}
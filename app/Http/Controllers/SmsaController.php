<?php

namespace App\Http\Controllers;

use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\OrderAddress;
use Botble\Ecommerce\Models\StoreLocator;
use Yajra\DataTables\DataTables;
use Illuminate\Http\Request;

class SmsaController extends Controller
{
    public function index()
    {
        return view('smsa');
    }

    public function getData()
    {
        // echo Order::select(['ec_orders.id as id', 'ec_orders.status as statuss', 'ec_orders.code as code', 'ec_customers.name as customer_name', 'ec_orders.created_at as created_at'])->leftJoin('ec_customers', 'ec_customers.id', '=', 'ec_orders.user_id')->orderBy('ec_orders.created_at', 'DESC')->toSql();
        $orders = Order::select(['ec_orders.id as id', 'ec_orders.status as statuss', 'ec_orders.amount as amount', 'ec_order_addresses.awb as awb', 'ec_orders.code as code', 'ec_order_addresses.name as customer_name', 'ec_orders.created_at as created_at', 'payments.payment_channel as payment_method'])
        ->leftJoin('ec_customers', 'ec_customers.id', '=', 'ec_orders.user_id')
        ->leftJoin('ec_order_addresses', 'ec_order_addresses.order_id', '=', 'ec_orders.id')
        ->leftJoin('payments', 'payments.order_id', '=', 'ec_orders.id')
        ->orderBy('ec_orders.created_at', 'DESC')
        ->get();
        // echo $orders;die;
        return DataTables::of($orders)
            ->editColumn('created_at', function ($row) {
                return \Carbon\Carbon::parse($row->created_at)->format('d M, Y'); // Format the date
            })
            ->editColumn('amount', function ($row) {
                return $row->payment_method == 'cod' ? $row->amount : 0;
            })
            ->editColumn('awb', function ($row) {
                return $row->awb ? '<a href="'.route('smsa.track', $row->awb).'" ><i class="fa-solid fa-track"></i> '.$row->awb.'</a>' : '';
            })
            ->addColumn('action', function($row) {
                return !$row->awb ? '<a href="'.route('smsa.edit', $row->id).'" class="btn btn-sm btn-primary"><i class="fa-solid fa-truck-fast"></i> Ship</a>' : '';
            })
            ->addColumn('check', function($row) {
                return '<input type="checkbox" id="'.$row->id.'" value= "'.$row->awb.'" class="row-checkbox">';
            })
            ->rawColumns(['action', 'check', 'awb']) // Allow HTML in the action columns
            ->make(true);
    }

    public function edit(Request $request, $id)
    {
        $order = Order::select('ec_orders.code', 'ec_orders.amount', 'ec_order_addresses.name', 'ec_order_addresses.phone', 'ec_order_addresses.address', 'ec_order_addresses.state', 'ec_order_addresses.city', 'payments.payment_channel as payment_method')
                ->leftJoin('ec_order_addresses', 'ec_orders.id', '=', 'ec_order_addresses.order_id')
                ->leftJoin('payments', 'payments.order_id', '=', 'ec_orders.id')
                ->where('ec_orders.id', $id)
                ->first();
        $products = OrderProduct::select('ec_order_product.options', 'ec_order_product.qty')->where('order_id', $id)->get();
        return view('smsa_edit', compact('id', 'order', 'products'));
    }

    public function bulkEdit(Request $request)
    {
        $ids = $request['ids'];
        return view('smsa_bulk_edit', compact('ids'));
    }

    public function submit(Request $request)
    {

        $location = StoreLocator::select('name', 'phone', 'address', 'country', 'state', 'city')->first();

        $shipper_data = array(
            'ContactName' => $location->name,
            'ContactPhoneNumber' => $location->phone,
            'Coordinates' => '',
            'Country' => $location->country,
            'District' => $location->state,
            'PostalCode' =>'',
            'City' => $location->city,
            'AddressLine1' => $location->address,
            'AddressLine2' => ''
        );

        $consignee_data = array(
            'ContactName' => ucwords($request['name']),
            'ContactPhoneNumber' => $request['phone'],
            'ContactPhoneNumber2' => '',
            'Coordinates' => '',
            'Country' => $request['country_code'], 
            'District' => $request['state'],
            'PostalCode' => '',
            'City' => $request['city'],
            'AddressLine1' => $request['address'],
            'AddressLine2' => '',
            'ConsigneeID' => ''
        );

        $defaultServiceCode = ($location->country === $request['country_code']) ? 'EDDL' : 'EIDL';

        $shipment_data = array(
            'ConsigneeAddress' => $consignee_data,
            'ShipperAddress' => $shipper_data,
            'OrderNumber' => $request['reference'],
            'DeclaredValue' => (float)$request['declared_value'],
            'CODAmount' => (float)$request['amount'],
            'Parcels' => 1,
            'ShipDate' => date('Y-m-d\TH:i:s'),
            'ShipmentCurrency' => $request['currency'],
            'SMSARetailID' => '0',
            'WaybillType' => 'PDF',
            'Weight' => (float)$request['weight'],
            'WeightUnit' => 'KG',
            'ContentDescription' => $request['products'],
            'VatPaid' => $request['vat_paid'] === 'true',
            'DutyPaid' => $request['duty_paid'] === 'true',
            'ServiceCode' => $defaultServiceCode
        );

        // echo "<pre>";print_r($shipment_data);exit();

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://ecomapis.smsaexpress.com/api/shipment/b2c/new',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($shipment_data),
            CURLOPT_HTTPHEADER => array(
                'apikey: 3af56f2bd2304769814715a9ed9645fd',
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $resp1 = json_decode($response);

        if (isset($resp1->sawb)) {
            OrderAddress::where('order_id', $request['order_id'])->update(['awb' => $resp1->sawb, 'name' => $request['name'], 'address' => $request['address'], 'customs_declared_value' => $request['declared_value'], 'total_cash_on_delivery' => $request['amount'], 'weight_kg' => $request['weight'], 'vat_payment' => $request['vat_paid'], 'duty_payment' => $request['duty_paid'], 'products' => $request['products']]);
            Order::where('id', $request['order_id'])->update(['status' => 'shipped']);
            $this->sendWhatsapp($request['phone'], $request['order_number'], $resp1->sawb);
            // $this->sendSMS($request['phone'], $request['order_number'], $resp1->sawb);

           return redirect('/admin/ecommerce/smsa');
            
        } elseif (isset($resp1->errors)) {
            foreach ($resp1->errors as $key => $value) {
                echo "<div class='alert alert-danger'>";
                echo "<strong>Error!!</strong> Error (" . $request['reference'] . '): ' . $key . ' - ' . $value[0] . '<br>';
                echo "</div>";
            }
        } else {
            echo "<div class='alert alert-danger'>";
            echo "<strong>Error!!</strong> Error: " . $response;
            echo "</div>";
        }
    }

    public function bulkSubmit(Request $request)
    {
        $location = StoreLocator::select('name', 'phone', 'address', 'country', 'state', 'city')->first();

        $shipper_data = array(
            'ContactName' => $location->name,
            'ContactPhoneNumber' => $location->phone,
            'Coordinates' => '',
            'Country' => $location->country,
            'District' => $location->state,
            'PostalCode' =>'',
            'City' => $location->city,
            'AddressLine1' => $location->address,
            'AddressLine2' => ''
        );
        for ($i=0; $i < count($request['order_id']); $i++) {
            $consignee_data = array(
                'ContactName' => ucwords($request['name'][$i]),
                'ContactPhoneNumber' => $request['phone'][$i],
                'ContactPhoneNumber2' => '',
                'Coordinates' => '',
                'Country' => $request['country_code'][$i], 
                'District' => $request['state'][$i],
                'PostalCode' => '',
                'City' => $request['city'][$i],
                'AddressLine1' => $request['address'][$i],
                'AddressLine2' => '',
                'ConsigneeID' => ''
            );

            $defaultServiceCode = ($location->country === $request['country_code'][$i]) ? 'EDDL' : 'EIDL';

            $shipment_data = array(
                'ConsigneeAddress' => $consignee_data,
                'ShipperAddress' => $shipper_data,
                'OrderNumber' => $request['reference'][$i],
                'DeclaredValue' => (float)$request['declared_value'][$i],
                'CODAmount' => (float)$request['amount'][$i],
                'Parcels' => 1,
                'ShipDate' => date('Y-m-d\TH:i:s'),
                'ShipmentCurrency' => $request['currency'][$i],
                'SMSARetailID' => '0',
                'WaybillType' => 'PDF',
                'Weight' => (float)$request['weight'][$i],
                'WeightUnit' => 'KG',
                'ContentDescription' => $request['products'][$i],
                'VatPaid' => $request['vat_paid'] === 'true',
                'DutyPaid' => $request['duty_paid'] === 'true',
                'ServiceCode' => $defaultServiceCode
            );

            // echo "<pre>";print_r($shipment_data);echo "<br>";
            // exit();

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://ecomapis.smsaexpress.com/api/shipment/b2c/new',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($shipment_data),
                CURLOPT_HTTPHEADER => array(
                    'apikey: 3af56f2bd2304769814715a9ed9645fd',
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);

            $resp1 = json_decode($response);

            if (isset($resp1->sawb)) {
                OrderAddress::where('order_id', $request['order_id'][$i])->update(['awb' => $resp1->sawb, 'name' => $request['name'][$i], 'address' => $request['address'][$i], 'customs_declared_value' => $request['declared_value'][$i], 'total_cash_on_delivery' => $request['amount'][$i], 'weight_kg' => $request['weight'][$i], 'vat_payment' => $request['vat_paid'][$i], 'duty_payment' => $request['duty_paid'][$i], 'products' => $request['products'][$i]]);
                Order::where('id', $request['order_id'][$i])->update(['status' => 'shipped']);
                $this->sendWhatsapp($request['phone'][$i], $request['order_number'][$i], $resp1->sawb);
                // $this->sendSMS($request['phone'][$i], $request['order_number'][$i], $resp1->sawb);
                

                echo "<div class='alert alert-success'>";
                echo "<strong>Well done!</strong> AWB number generated successfully for order " . $request['reference'][$i];
                echo "</div>";
                
            } elseif (isset($resp1->errors)) {
                foreach ($resp1->errors as $key => $value) {
                    echo "<div class='alert alert-danger'>";
                    echo "<strong>Error!!</strong> Error (" . $request['reference'][$i] . '): ' . $key . ' - ' . $value[0] . '<br>';
                    echo "</div>";
                }
            } else {
                echo "<div class='alert alert-danger'>";
                echo "<strong>Error!!</strong> Error: " . $response;
                echo "</div>";
            }
        }
        // die;
    }

    public function bulkPrint(Request $request)
    {
        $awbs = explode(',', $request['awbs']);
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <title>AWB PDFs</title>
            <style>
                .pdf-container {
                    margin: 20px 0;
                    border: 1px solid #ccc;
                    padding: 10px;
                }
            </style>
        </head>
        <body>';
        foreach ($awbs as $key => $awb) {
            if(!empty($awb)) {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://ecomapis.smsaexpress.com/api/shipment/b2c/query/'.$awb,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => array(
                        'apikey: 3af56f2bd2304769814715a9ed9645fd',
                        'Content-Type: application/json'
                    ),
                ));

                $response = curl_exec($curl);
                curl_close($curl);

                $resp1 = json_decode($response);

                // echo "<pre>";print_r($resp1->waybills);die;

                if (isset($resp1->waybills) && count($resp1->waybills) > 0) {

                    $pdfData = base64_decode($resp1->waybills[0]->awbFile);

                    $pdfBase64 = base64_encode($pdfData);

                    $html .= '<div class="pdf-container">
                                <iframe src="data:application/pdf;base64,' . $pdfBase64 . '" width="100%" height="700px"></iframe>
                            </div>';
                } elseif (isset($resp1->errors)) {
                    foreach ($resp1->errors as $key => $value) {
                        echo "<div class='alert alert-danger'>";
                        echo "<strong>Error!!</strong> Error (" . $awb . '): ' . '<br>';
                        echo "</div>";
                    }
                } else {
                    echo "<div class='alert alert-danger'>";
                    echo "<strong>Error!!</strong> Error: " . $response;
                    echo "</div>";
                }
            }
        }
        $html .= '</body></html>';
        return response($html)->header('Content-Type', 'text/html');
    }

    public function track($awb)
    {
        if(!empty($awb)) {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://ecomapis.smsaexpress.com/api/track/single/'.$awb,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                    'apikey: 3af56f2bd2304769814715a9ed9645fd',
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);

            $track = json_decode($response);

            // echo "<pre>";print_r($track);die;
        }
        return view('smsa_track', compact('track'));
    }

    private function sendWhatsapp($phone, $orderCode, $awb) {
        $link = "https://www.smsaexpress.com/ae/trackingdetails?tracknumbers%5B0%5D=" . $awb;
        $curl = curl_init();

        $wa_payload = [
            "ProfileId" => "MIM2400074",
            "APIKey" => "#JpXt4fbMCFj",
            "MobileNumber" => (string)$phone,
            "templateName" => "smsatracking",
            "Parameters" => [
                (string)$orderCode,
                (string)$link
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
        
        if (curl_errno($curl)) {
            \Log::error("WhatsApp Error for Order $orderCode: " . curl_error($curl));
        } else {
            \Log::info("WhatsApp Sent for Order $orderCode. Response: " . $response);
        }
        
        curl_close($curl);
    }

    // private function sendSMS($raw_mobile, $orderCode, $awb) {
    //     $link = "https://www.smsaexpress.com/ae/trackingdetails?tracknumbers%5B0%5D=" . $awb;
    //     $clean_mobile = $raw_mobile;
        
    //     if (substr($raw_mobile, 0 , 1 === '0')) {
    //         $clean_mobile = substr($raw_mobile, 1);
    //     }
    //     $phone = "971" . $clean_mobile;
    //     if(!$phone) {
    //         return;
    //     }

    //     $passw = "11F2";
    //     $pass = "$";
    //     $p = "E89_6C3";
    //     $password = $passw.$pass.$p;

    //     $ch = curl_init();
    //     $sms_params = [
    //         'userid' => 'MIM2300278',
    //         'pwd'    => $password,
    //         'mobile' => $phone,
    //         'sender' => 'Ahmedper',
    //         'msg'    => 'Dear Customer, Your order ' . $orderCode . ' has been shipped and is headed to you. Track your delivery here: ' . $link . ' Thank you.',
    //         'msgtype'=> '16'
    //     ];
    //     $sms_url = "https://myinboxmedia.ae/api/mim/SendSMS?" . http_build_query($sms_params);
    //     curl_setopt($ch, CURLOPT_URL, $sms_url);
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    //     curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    //     $result = curl_exec($ch);

    //     if (curl_errno($ch)) {
    //         \Log::error("SMS API Error for Order $orderCode: " . curl_error($ch));
    //     } else {
    //         $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //         \Log::info("SMS Sent for Order $orderCode | AWB: $awb | Status: $http_status | Response: " . $result);
    //     }
    //     curl_close ($ch);
    // }

    public function checkDeliveryStatus(Request $request)
    {
        // 1. Setup Parameters
        $limit = $request->input('limit', 50); // Default to 50 orders per batch
        $lastId = $request->input('last_id', 0); // Cursor for pagination
        $updatedCount = 0;
        $processedCount = 0;
        
        \Log::info("SMSA Batch: Starting process for orders with ID > $lastId (Limit: $limit)");

        // 2. Fetch Orders (ID Cursor Strategy)
        $orders = Order::select('ec_orders.id', 'ec_orders.code', 'ec_order_addresses.awb')
            ->leftJoin('ec_order_addresses', 'ec_orders.id', '=', 'ec_order_addresses.order_id')
            ->where('ec_orders.status', 'shipped')
            ->whereNotNull('ec_order_addresses.awb')
            ->where('ec_order_addresses.awb', '!=', '') // Ensure AWB is not empty string
            ->where('ec_orders.id', '>', $lastId)
            ->orderBy('ec_orders.id', 'ASC') // Critical for cursor to work
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'message' => 'No more orders found to process.',
                'last_processed_id' => $lastId,
                'completed' => true
            ]);
        }

        // 3. Iterate and Check
        foreach ($orders as $order) {
            $processedCount++;
            $currentId = $order->id; // Track current ID for response

            try {
                // Initialize CURL for SMSA Tracking
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://ecomapis.smsaexpress.com/api/track/single/' . trim($order->awb),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 10, // Short timeout to prevent hanging
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPHEADER => array(
                        'apikey: 3af56f2bd2304769814715a9ed9645fd',
                        'Content-Type: application/json'
                    ),
                ));

                $response = curl_exec($curl);
                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);

                // API Throttle: Sleep 0.2 seconds to be polite to SMSA servers
                usleep(200000); 

                if ($httpCode === 200) {
                    $trackData = json_decode($response);

                    // 4. Validate "isDelivered" Flag
                    // We check if the property exists and is strictly true
                    if (isset($trackData->isDelivered) && $trackData->isDelivered === true) {
                        
                        // Update Database
                        Order::where('id', $order->id)->update([
                            'status' => 'completed',
                            'completed_at' => \Carbon\Carbon::now(),
                        ]);

                        $updatedCount++;
                        \Log::info("SMSA Batch: Order #{$order->code} (ID: {$order->id}) marked as COMPLETED.");
                    } else {
                        // Optional: Log that it is still in transit
                        \Log::info("SMSA Batch: Order #{$order->code} still in transit.");
                    }
                } else {
                    \Log::error("SMSA Batch: API Error for Order #{$order->code}. HTTP Code: $httpCode");
                }

            } catch (\Exception $e) {
                \Log::error("SMSA Batch: Exception for Order #{$order->code}: " . $e->getMessage());
            }

            // Update the last processed ID
            $lastId = $order->id;
        }

        // 5. Return Response
        return response()->json([
            'status' => 'success',
            'message' => "Processed $processedCount orders. Updated $updatedCount orders.",
            'last_processed_id' => $lastId, // INPUT THIS into the next request
            'updates_made' => $updatedCount,
            'next_url_suggestion' => route('smsa.check_status') . "?limit=$limit&last_id=$lastId"
        ]);
    }
}
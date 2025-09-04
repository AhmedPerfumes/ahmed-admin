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
use Botble\Ecommerce\Models\DiscountCustomer;
use App\Models\Promotion;

class OrderController extends Controller
{
    public function storeOrder(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {

        $validator = Validator::make($request->all(), [
            'products'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $barcodes = [];

        foreach ($request->input('products') as $product) {
            $exisProduct = Product::where('id', $product['product_id'])->first();
            // echo $exisProduct->quantity .'<'. $product['quantity'];
            if (!$exisProduct) {
                return response()->json([
                    'notFound' => 'Product not found '.$product['product_name']
                ], 500);
            }
            
            if($exisProduct->quantity < $product['quantity']) {
                return response()->json([
                    'qtyMessage'          => $product['product_name'].' is Out Of Stock.'
                ]);
            }

            // $url = "https://c21341-ifservice.cloudiax.com/api/ECommerce/StockStatus?itemCode=123456";
            // $url = "https://c21341-ifservice.cloudiax.com/api/ECommerce/StockStatus?itemCode=".$exisProduct->barcode;

            // $ch = curl_init();

            // curl_setopt($ch, CURLOPT_URL, $url);
            // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // // Set the request method to POST
            // curl_setopt($ch, CURLOPT_POST, true);
            // curl_setopt($ch, CURLOPT_HTTPHEADER, [
            //     "Accept: application/json",
            //     "Company: UAE", 
            //     "Authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJVc2VySUQiOiJhZG1pbiIsIkVtcElEIjoiMTAyNDgiLCJDb21wYW55IjoiIiwiV2hzQ29kZSI6IidDdXN0b20nLCdETV8wMScsJ0ZHXzAxJywnRk9DJywnSUNfVUFFJywnUE1fMDEnLCdTUF8wMDEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDQnLCdTUF8wMDUnLCdTUF8wMDYnLCdTUF8wMDcnLCdTUF8wMDgnLCdTUF8wMDknLCdTUF8wMTAnLCdTUF8wMTEnLCdTUF8wMTInLCdTUF8wMTMnLCdTUF8wMTQnLCcwMScsJ0NOMDAxXzAxJywnQ3VzdG9tJywnRE1fMDEnLCdGR18wMScsJ0ZHXzAyJywnRkdfMDMnLCdGT0MnLCdJQ18wMScsJ0lDX1VBRScsJ1BNXzAxJywnU1BfMDAxJywnU1BfMDAxXzEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDNfMScsJ1NQXzAwNCcsJ1NQXzAwNScsJ1NQXzAwNicsJ1NQXzAwNycsJ1NQXzAwOCcsJ1NQXzAwOScsJ1NQXzAxMCcsJ1NQXzAxMScsJ1NQXzAxMicsJ1NQXzAxMycsJ1NQXzAxNCcsJ1NQXzAxNScsJ1NQXzAxNicsJ1NQXzAxNycsJ1NQXzAxOScsJ1NQXzAyMCcsJ1NQXzAyMF8xJywnU1BfMDIxJywnU1BfMDIyJywnU1BfMDIzJywnU1BfMDI0JywnU1BfMDI1JywnU1BfMDI2JywnU1BfMDI3JywnU1BfMDI4JywnU1BfMDI4XzEnLCdTUF8wMjhfMicsJ1NQXzAyOScsJ1NQXzAzMCcsJ1NQXzAzMScsJ1ZOXzAwMScsJ0N1c3RvbScsJ0RNXzAxJywnRkdfMDEnLCdGT0MnLCdJQ19VQUUnLCdQTV8wMScsJ1NQXzAwMScsJ1NQXzAwMicsJ1NQXzAwMycsJ1NQXzAwNCcsJ1NQXzAwNScsJ1NQXzAwNicsJ1NQXzAwNycsJ1NQXzAwOCcsJzAxJywnQ3VzdG9tJywnRE1fMDEnLCdGR18wMScsJ0ZPQycsJ0lDXzAxJywnSUNfTW92JywnSUNfT0FQJywnSUNfVUFFJywnUE1fMDEnLCdTUF8wMDEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDQnLCdTUF8wMDUnLCdTUF8wMDYnLCdTUF8wMDcnLCdTUF8wMDgnLCdTUF8wMDknLCdTUF8wMTAnLCdTUF8wMTEnLCdTUF8wMTInLCdTUF8wMTMnLCdTUF8wMTQnLCdTUF8wMTUnLCdTUF8wMTYnLCdTUF8wMTcnLCdTUF8wMTgnLCdTUF8wMTknLCdTUF8wMjAnLCdTUF8wMjEnLCdTUF8wMjInLCdTUF8wMjMnLCdTUF8wMjQnLCdTUF8wMjUnLCdTUF8wMjYnLCdTUF8wMjcnLCdTUF8wMjgnLCdTUF8wMjknLCdTUF8wMzAnLCdTUF8wMzEnLCdTUF8wMzInLCdTUF8wMzMnLCdTUF8wMzQnLCdTUF8wMzUnLCdTUF8wMzYnLCdTUF8wMzcnLCdTUF8wMzgnLCdTUF8wMzknLCdTUF8wNDAnLCdTUF8wNDEnLCdTUF8wNDInLCdTUF8wNDMnLCdTUF8wNDQnLCdTUF8wNDUnLCdTUF8wNDYnLCdTUF8wNDcnLCdTUF8wNDgnLCdTUF8wNDknLCdTUF8wNTAnLCdTUF8wNTEnLCdTUF8wNTInLCdTUF8wNTMnLCdTUF8wNTQnLCdTUF8wNTUnLCdTUF8wNTYnLCdTUF8wNTcnLCdTUF8wNTgnLCdTUF8wNTknLCdTUF8wNjAnLCdTUF8wNjEnLCdUWVNfMDEnLCcwMScsJ0NOMDAxXzAxJywnQ04wMDJfMDEnLCdDTjAwM18wMScsJ0NOMDA0XzAxJywnQ04wMDVfMDEnLCdDTjAwNl8wMScsJ0N1c3RvbScsJ0RNXzAxJywnRkdfMDEnLCdGR18wMicsJ0ZPQycsJ0lDX09NTicsJ0lDX1RZUycsJ0lDX1VBRScsJ1BNXzAxJywnU01QXzAxJywnU1BfMDAxJywnU1BfMDAyJywnU1BfMDAzJywnU1BfMDA0JywnU1BfMDA1JywnU1BfMDA2JywnU1BfMDA3JywnU1BfMDA4JywnU1BfMDA5JywnU1BfMDEwJywnU1BfMDExJywnU1BfMDEyJywnU1BfMDEzJywnU1BfMDE1JywnU1BfMDE2JywnU1BfMDE3JywnU1BfMDE4JywnU1BfMDE5JywnU1BfMDIwJywnU1BfMDIxJywnU1BfMDIyJywnMDEnLCdBbWF6b24nLCdBVF8wMScsJ0JLXzAxJywnQlJBTkQnLCdDMDIwMjM1NicsJ0NOMDAxXzAxJywnQ04wMDJfMDEnLCdDTjAwM18wMScsJ0NOMDA0XzAxJywnQ04wMDVfMDEnLCdDTjAwNl8wMScsJ0NOMDA3XzAxJywnQ04wMDhfMDEnLCdDV19TTTAwMCcsJ0NXX1NNMDAxJywnQ1dfU00wMDInLCdDV19TTTAwMycsJ0NXX1NNMDA0JywnQ1dfU00wMDUnLCdDV19TTTAwNicsJ0NXX1NNMDA3JywnQ1dfU00wMDgnLCdDV19TTTAwOScsJ0NXX1NNMDEwJywnRE1fMDEnLCdETV8wMicsJ0RNXzAzJywnRE1fMDQnLCdETV8wNScsJ0RNXzA2JywnRUNfMDEnLCdGR18wMScsJ0ZPQycsJ0dGXzAxJywnSUNfQU1QJywnSUNfQkhSJywnSUNfS1NBJywnSUNfTW92JywnSUNfT01OJywnSUNfUUFUJywnSVQnLCdJVDAyJywnUEtfMDEnLCdQTV8wMScsJ1BNXzAyJywnUUNfMDEnLCdSJkQnLCdTS18wMScsJ1NMXzAxJywnU01QXzAxJywnU1BfMDAxJywnU1BfMDAyJywnU1BfMDAzJywnU1BfMDA0JywnU1BfMDA1JywnU1BfMDA2JywnU1BfMDA3JywnU1BfMDA4JywnU1BfMDA5JywnU1BfMDEwJywnU1BfMDExJywnU1BfMDEyJywnU1BfMDEzJywnU1BfMDE0JywnU1BfMDE1JywnU1BfMDE2JywnU1BfMDE3JywnU1BfMDE4JywnU1BfMDE5JywnU1BfMDIwJywnU1BfMDIxJywnU1BfMDIyJywnU1BfMDIzJywnU1BfMDI0JywnU1BfMDI1JywnU1BfMDI2JywnU1BfMDI3JywnU1BfMDI4JywnU1BfMDI5JywnU1BfMDMwJywnU1BfMDMxJywnU1BfMDMyJywnU1BfMDMyXzEnLCdTUF8wMzMnLCdTUF8wMzQnLCdTUF8wMzUnLCdTUF8wMzYnLCdTUF8wMzcnLCdTUF8wMzgnLCdTUF8wMzknLCdTUF8wNDAnLCdTUF8wNDEnLCdTUF8wNDInLCdTUF8wNDMnLCdTUF8wNDQnLCdTUF8wNDUnLCdTUF8wNDYnLCdTUF8wNDcnLCdTUF8wNDgnLCdTUF8wNDknLCdTUF8wNTAnLCdTUF8wNTEnLCdTUF8wNTInLCdTUF8wNTMnLCdTUF8wNTQnLCdTUF8wNTUnLCdTUF8wNTYnLCdTUF8wNTcnLCdTUF8wNTgnLCdTUF8wNTknLCdTUF8wNjAnLCdTUF8wNjEnLCdTUF8wNjInLCdTUF8wNjMnLCdTUF8wNjQnLCdTUF8wNjUnLCdTUF8wNjYnLCdTUF8wNjcnLCdTUF8wNjgnLCdTUF8wNjknLCdTUF8wNzAnLCdTUF8wNzEnLCdTUF8wNzInLCdTUF8wNzMnLCdTUF8wNzQnLCdTUF8wNzUnLCdTUF8wNzYnLCdTUF8wNzcnLCdTUF8wNzknLCdTUF8wODAnLCdTUF8wODEnLCdTUF8wODInLCdTUF8wODMnLCdTUF8wODQnLCdTUF8wODUnLCdTUF8wODYnLCdTUF8wODgnLCdTUF8wODknLCdTUF8wOTAnLCdTUF8wOTEnLCdTUF8wOTInLCdXSF8wMScsJ1dIXzAyJywnV0hfMDMnLCdXSF8wNCcsJ1dIXzA1JywnV0hfMDYnLCdXSF9EUk0nLCdXSF9WZW5kJyIsIlN0b3JlSUQiOiInJywnSE8nLCdPRkInLCdITycsJ0hPJywnUCZFJywnU01BJywnQktXJywnQkNDJywnQlNUJywnSERMJywnREFNJywnSklEJywnQlVLJywnUkFNJywnQ0NCJywnSE1UJywnTUhSJywnQU1CJywnQlNTJywnJywnSE8nLCdITycsJycsJ0pETycsJ01ETycsJ0hPJywnSE8nLCcnLCdITycsJ1AmRScsJ0tBUycsJ0tBU1MnLCdKUUInLCdEQVQnLCdEQVRTJywnTk9SJywnQVNNJywnVEJBJywnQVpNJywnQktSJywnU0tEJywnVEdNJywnT0JNJywnSlVNJywnUUJBJywnS09TJywnU1NKJywnTU9OJywnU0FGJywnUUJGJywnS01TJywnS01TUycsJ01BRycsJ1lSTScsJ01VRycsJ01SSicsJ1NRSicsJ01ESCcsJ01ERycsJ01DVCcsJ01DVFMnLCdWTUNUJywnUkhCJywnT0JIJywnQkFTJywnS1NWJywnJywnSE8nLCcnLCdITycsJ0hPJywnUCZFJywnS1NNJywnSlJLJywnS01BJywnS09EJywnR0FUJywnQkxWJywnTUdUJywnTUdDJywnJywnSE8nLCdITycsJ09GTycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdQJkUnLCdTTVQnLCdTS0snLCdTRUInLCdCUksnLCdTTEwnLCdTVVInLCdOSVonLCdTV1EnLCdTT00nLCdTQU0nLCdCUk0nLCdFQlInLCdTQlgnLCdCRFknLCdLQlInLCdBTVInLCdTTk0nLCdBVk0nLCdMV00nLCdKTE4nLCdBS00nLCdBS0InLCdNU04nLCdTTlcnLCdSU1QnLCdCUkEnLCdZQU4nLCdTTE4nLCdTTFUnLCdTQUQnLCdNT00nLCdRVVInLCdCSUQnLCdLQU0nLCdLVUQnLCdTTUwnLCdTTlMnLCdDQ00nLCdNT08nLCdDQ1MnLCdKTFMnLCdPQVMnLCdTU1MnLCdETksnLCdCSEwnLCdNQVQnLCdBTlMnLCdBU0snLCdLQlMnLCdTTVMnLCdGTEonLCdEUU0nLCdFQlMnLCdGQU4nLCdCRFMnLCdBTVMnLCdCREQnLCdPT1MnLCdUTUQnLCdTV1MnLCdNVVMnLCdITycsJycsJycsJycsJycsJycsJycsJycsJycsJycsJ09GUScsJ0hPJywnJywnSE8nLCdITycsJ0hPJywnUCZFJywnSE8nLCdBWlknLCdTSEYnLCdOU1InLCdESEYnLCdNUVInLCdBTUonLCdET00nLCdBTUsnLCdMQkInLCdBV1MnLCdNUksnLCdBRlMnLCdXQVEnLCdRT1MnLCdRUk4nLCdJR1cnLCdFWkQnLCdWSUwnLCdOQVMnLCdTSE4nLCdXQVQnLCcnLCdITycsJ0hPJywnSE8nLCcnLCcnLCcnLCcnLCcnLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0FFQycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ1AmRScsJ0hPJywnSE8nLCcnLCcnLCdITycsJ0hPJywnREZNJywnQlNNJywnQk5ZJywnQ1RNJywnRE1LJywnS0hMJywnQUpDJywnTVpNJywnQUZNJywnQUFNJywnQldNJywnQlNHJywnQlNYJywnQUdNJywnQUJNJywnQUJDJywnTUZDJywnRFJDJywnREFGJywnRkpNJywnQUtIJywnS0hLJywnTU5NJywnUkFLJywnU0hNJywnTVJEJywnU1JDJywnU0JTJywnU01NJywnTUFNJywnVUFRJywnSlJOJywnSlJNJywnU1FNJywnUk1aJywnQVNTJywnQkFSJywnS0hNJywnTU9RJywnRExNJywnQVlSJywnVUNKJywnQUdaJywnUkhNJywnVUNBJywnVUNCJywnRkNDJywnR0JWJywnRFJNJywnU0NIJywnSFRUJywnTVNGJywnSk1NJywnWkNDJywnR1lNJywnRkNNJywnTVNNJywnREhEJywnUklGJywnS0JNJywnSE1EJywnUldEJywnS1dTJywnQUFLJywnQlJTJywnRE9TJywnU0xNJywnREVSJywnU0NEJywnS0xGJywnU0JBJywnTURNJywnSlJGJywnTExaJywnRkpTJywnUkZNJywnRE1CJywnTVJCJywnREhNJywnSURXJywnSkNQJywnRFNTJywnTVNLJywnSE1BJywnRElCJywnRFNRJywnVU1CJywnQUtEJywnSFRTJywnWUFTJywnR0JJJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnRFdTJywnJyIsIlRlcm1pbmFsSUQiOiIiLCJzYWxlc1BlcnNvbklkIjoiIiwiem9uZUlkIjoiJyonIiwiZXhwIjoxNzczNTU5MjYyfQ.JZfGnaPSXmCanQfq3OWPRkYqqzy_rM9LLyLLiTLMFOo"
            // ]);

            // $response = curl_exec($ch);

            // if (curl_errno($ch)) {
            //     echo 'Error: ' . curl_error($ch);
            // }

            // curl_close($ch);
            // $resp = json_decode($response);
            // // print_r($resp->data);die;
            //  if($resp->data < $product['quantity']) {
            //     return response()->json([
            //         'qtyMessage'          => $product['product_name'].' is Out Of Stock.'
            //     ]);
            // }

            // if(!is_null($product['discount'])) {
                // $discountFromDb = DiscountProduct::select('value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();
                $discountFromDb = Promotion::where('type', 'discount')
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->whereHas('discountRules', function ($query) {
                        $query->where('apply_to', 'individual');
                    })
                    ->whereHas('discountRules.individualRules', function ($query) use ($product) {
                        $query->where('product_id', $product['product_id']);
                    })
                    ->with(['discountRules' => function ($query) {
                        $query->where('apply_to', 'individual')
                            ->select('id', 'promotion_id', 'apply_to');
                    }, 'discountRules.individualRules' => function ($query) use ($product) {
                        $query->where('product_id', $product['product_id'])
                            ->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
                    }])
                    ->first();

                if (!$discountFromDb) {
                   // If no individual discount, try to fetch discount for group/all products
                    $discountFromDb = Promotion::where('type', 'discount')
                        ->whereDate('start_date', '<=', now())
                        ->whereDate('end_date', '>=', now())
                        ->whereHas('discountRules', function ($query) {
                            $query->where('apply_to', '!=', 'individual');
                        })
                        ->whereHas('discountRules.products', function ($query) use ($product) {
                            $query->where('product_id', $product['product_id']);
                        })
                        ->with(['discountRules' => function ($query) {
                            $query->where('apply_to', '!=', 'individual')
                                ->select('id', 'promotion_id', 'percentage', 'apply_to');
                        }])
                        ->first();
                }
                $requestHasDiscount = !is_null($product['discount']);
                $dbHasDiscount = !is_null($discountFromDb);

                if ($requestHasDiscount && !$dbHasDiscount) {
                    // Request says there should be a discount, but none found in DB
                    return response()->json([
                        'discountMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                    ]);
                }

                if (!$requestHasDiscount && $dbHasDiscount) {
                    // Request says there should be no discount, but one exists in DB
                    return response()->json([
                        'discountMessage' => 'One or more Products were removed. Please add them again to continue. Request '.$product['product_name']
                    ]);
                }

                // Optional: if you want to compare actual values of discount too
                if ($requestHasDiscount && $dbHasDiscount) {
                    $value = null;

                    if (isset($discountFromDb->discountRules[0])) {
                        $discountRule = $discountFromDb->discountRules[0];

                        if (isset($discountRule->individualRules[0])) {
                            // Individual discount value
                            $value = $discountRule->individualRules[0]->value;
                        } else {
                            // Group or all-products discount value (percentage)
                            $value = $discountRule->percentage;
                        }
                    }
                    $match =
                        $product['discount']['value'] ==  $value &&
                        $product['discount']['start_date'] == $discountFromDb->start_date &&
                        $product['discount']['end_date'] == $discountFromDb->end_date;

                    if (!$match) {
                        return response()->json([
                            'discountMessage' => 'One or more Products were removed. Please add them again to continue. Value '.$product['product_name']
                        ]);
                    }
                }

                // All matched, assign discount
                    // $exisProduct->discount = $discountFromDb;
                // }

                $focFromDb = Promotion::where('type', 'foc')
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->whereHas('focRules', function ($query) {
                        // $query->where('apply_to', '!=', 'individual');
                    })
                    ->whereHas('focRules.products', function ($query) use ($product) {
                        $query->where('product_id', $product['product_id']);
                    })
                    ->with(['focRules' => function ($query) {
                        // $query->where('apply_to', '!=', 'individual')
                            $query->select('id', 'promotion_id', 'min_threshold', 'max_threshold');
                    }])
                    ->first();
                    
                $requestHasFOC = isset($product['type']) && $product['type'] == 'foc';
                $dbHasFOC = !is_null($focFromDb);

                // echo $requestHasFOC.'---'.$dbHasFOC.'---'.$product['product_id'];
                // echo "\n";

                if ($requestHasFOC && !$dbHasFOC) {
                    // Request says there should be a discount, but none found in DB
                    return response()->json([
                        'focMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                    ]);
                }

                if (!$requestHasFOC && $dbHasFOC) {
                    // Request says there should be no discount, but one exists in DB
                    return response()->json([
                        'focMessage' => 'One or more Products were removed. Please add them again to continue. Request '.$product['product_name']
                    ]);
                }

                // Step 1: Determine if request says product is a BOGO free item
                $requestHasBOGO = isset($product['type']) && $product['type'] == 'bogo' && isset($product['is_gift']);

                // Step 2: Only run DB BOGO check if the request is for a BOGO free product
                $bogoFromDb = null;

                if ($requestHasBOGO) {
                    // echo "bogo ".$product['product_name'];
                    // echo "\n";
                    $bogoFromDb = Promotion::where('type', 'buy_x_get_y')
                        ->whereDate('start_date', '<=', now())
                        ->whereDate('end_date', '>=', now())
                        ->whereHas('buyXGetYRules.products', function ($query) use ($product) {
                            $query->where('product_id', $product['product_id']);
                                // ->where('type', 'free'); // Ensure it only matches "get" products
                        })
                        ->first();
                }

                // Step 3: Validate mismatch between request and DB
                $dbHasBOGO = !is_null($bogoFromDb);

                if ($requestHasBOGO && !$dbHasBOGO) {
                    return response()->json([
                        'bogoMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                    ]);
                }

                if (!$requestHasBOGO && $dbHasBOGO) {
                    return response()->json([
                        'bogoMessage' => 'One or more Products were removed. Please add them again to continue. Request ' . $product['product_name']
                    ]);
                }

            array_push($barcodes, $exisProduct->barcode);
        }
        // echo implode(',', $barcodes);
        // die;
        $coupon_code = $request->input('couponCode');
        if(isset($coupon_code) && !empty($request->input('couponCode'))) {
            $coupon = Promotion::select('type', 'start_date', 'end_date', 'coupon_code AS code', 'percentage As value', 'apply_to')->where('type', 'coupon')->where('coupon_code', $request->input('couponCode'))->where('start_date', '<=', now())->where('end_date', '>=', now())->join('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id', 'left')->first();
            if(!$coupon) {
                return response()->json(['couponMessage' => 'Invalid Coupon Code']);
            }
            // $order_address = OrderAddress::where('phone', $request->input('billingAddress.mobile'))->first();
            // // echo $order_address;
            // if($order_address) {
            //     $order = Order::where('id', $order_address->order_id)->first();
            //     $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $order->user_id)->where('discount_id', $coupon->id)->first();
            //     if($customer_discount) {
            //         return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
            //     }
            // }

            $customer = OrderAddress::join('payments', 'payments.order_id', '=', 'ec_order_addresses.order_id')->where('status', 'completed')->where('phone', $request->input('billingAddress.mobile'))->get();

            if(!$customer->isEmpty()) {
                if(strtolower($request->input('couponCode')) == 'welcome10') {
                    return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
                }
                $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $customer[0]->customer_id)->where('discount_id', $coupon->id)->first();
                if($customer_discount) {
                    return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
                }
            }
        }

        $customer_id = $request->input('customer_id');

        if (!$customer_id) {
            $validator = Validator::make($request->all(), [
                'billingAddress.first_name'      => 'required|string|max:255',
                'billingAddress.last_name'      => 'required|string|max:255',
                'billingAddress.email'     => 'required|string|max:255',
                'billingAddress.mobile'     => 'required|numeric',
                'billingAddress.area'     => 'required|string',
                'billingAddress.building'     => 'required|string',
                'billingAddress.emirates'     => 'required|string',
                ]);
    
            if ($validator->fails()) {
                return response()->json($validator->errors());
            }
            
            $exisCustomer = Customer::where('email', $request->billingAddress['email'])->orWhere('phone', $request->billingAddress['mobile'])->first();
    
            if (!$exisCustomer) {
                $customer = Customer::create([
                    'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                    'email'     => $request->input('billingAddress.email'),
                    'phone'     => $request->input('billingAddress.mobile'),
                    'password'  => $request->input('password') ? Hash::make($request->input('password')) : Hash::make('123456')
                ]);

                Address::create([
                    'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                    'email'     => $request->input('billingAddress.email'),
                    'phone'     => $request->input('billingAddress.mobile'),
                    'state' => $request->input('billingAddress.emirates'),
                    'city' => $request->input('billingAddress.emirates'),
                    'country' => $request->input('billingAddress.country'),
                    'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                    'customer_id' => $customer->id,
                ]);

                // $otp = rand(1111, 9999);

                // $ch = curl_init();

                // $passw = "11F2";
                // $pass = "$";
                // $p = "E89_6C3";
                // $password = $passw.$pass.$p;

                // curl_setopt($ch, CURLOPT_URL, "https://myinboxmedia.in/api/mim/SendSMS?userid=MIM2300278&pwd=".$password."&mobile=971".$request->input('billingAddress.mobile')."&sender=Ahmedper&msg=".$otp."".urlencode(' is your OTP for Registration')."&msgtype=16");
                // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

                // $result = curl_exec($ch);
                // if (curl_errno($ch)) {
                //     echo 'Error:' . curl_error($ch);die;
                // }
                // curl_close ($ch);

                // $customer->otp = $otp;
                // $customer->save();

                $customer_id = $customer->id;
            } else {
                $exisAddress = Address::where('customer_id', $exisCustomer->id)->first();
                if(!$exisAddress) {
                    Address::create([
                        'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                        'email'     => $request->input('billingAddress.email'),
                        'phone'     => $request->input('billingAddress.mobile'),
                        'state' => $request->input('billingAddress.emirates'),
                        'city' => $request->input('billingAddress.emirates'),
                        'country' => $request->input('billingAddress.country'),
                        'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                        'customer_id' => $exisCustomer->id,
                    ]);
                }
                $customer_id = $exisCustomer->id;
            }
        }

        // echo "<pre>";print_r(([
        //     'user_id' => $customer_id,
        //     'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
        //     'shipping_option' => $request->input('shipping_option'),
        //     'shipping_amount' => $request->input('shippingPrice') ? : 0,
        //     'tax_amount' => (($request->input('finalPrice') - 3) / 100) * 5 ? : 0,
        //     'sub_total' => $request->input('totalPrice') ? : 0,
        //     'amount' => $request->input('finalPrice') ? : 0,
        //     'coupon_code' => $request->input('coupon_code'),
        //     'discount_amount' => $request->input('discount_amount') ? : 0,
        //     'promotion_amount' => $request->input('promotion_amount') ? : 0,
        //     'discount_description' => $request->input('discount_description'),
        //     'description' => $request->input('note'),
        //     'is_confirmed' => 1,
        //     'is_finished' => 1,
        //     'status' => OrderStatusEnum::PROCESSING,
        //     'lang' => $request->input('locale'),
        // ]));die();
        // echo "<pre>";print_r([
        //     'user_id' => $customer_id,
        //     'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
        //     'shipping_option' => $request->input('shipping_option'),
        //     'shipping_amount' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)),
        //     'shipping_amount_vat' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
        //     'service_amount' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)),
        //     'service_amount_vat' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
        //     'vat' => $request->input('vatTax'),
        //     'tax_amount' => ($request->input('totalPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('codPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)),
        //     'sub_total' => $request->input('totalPrice') ? : 0,
        //     'amount' => $request->input('finalPrice') ? : 0,
        //     'coupon_code' => $request->input('couponCode'),
        //     'discount_amount' => $request->input('discount_amount') ? : 0,
        //     'promotion_amount' => $request->input('promotion_amount') ? : 0,
        //     'discount_description' => $request->input('discount_description'),
        //     'description' => $request->input('note'),
        //     'is_confirmed' => 1,
        //     'is_finished' => 1,
        //     'status' => OrderStatusEnum::PROCESSING,
        //     'lang' => $request->input('locale'),
        //     'cod_charge' => $request->input('codPrice') / (1 + ($request->input('vatTax') / 100)),
        //     'cod_charge_vat' => $request->input('codPriceVat') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
        // ]);die();
        $userId = $customer_id;
        $now = Carbon::now();
        $fiveMinutesAgo = Carbon::now()->subMinutes(5);

        // Optionally, get order contents for matching (e.g. same total or cart hash)
        $total = $request->input('finalPrice'); // Example field

        $existingOrder = Order::where('user_id', $userId)
            ->where('amount', $total)
            ->where('created_at', '>=', $fiveMinutesAgo)
            ->whereHas('payment', function ($query) {
                $query->where('status', 'completed');
            })
            ->first();

        if ($existingOrder) {
            return response()->json([
                'duplicateOrderMessage' => 'Duplicate order detected. Order Id: ' . $existingOrder->code
            ]);
        }
        $order = Order::create([
            'user_id' => $customer_id,
            'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
            'shipping_option' => $request->input('shipping_option'),
            'shipping_amount' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)),
            'shipping_amount_vat' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
            'service_amount' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)),
            'service_amount_vat' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
            'vat' => $request->input('vatTax'),
            'tax_amount' => ($request->input('totalPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('codPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)),
            'sub_total' => $request->input('totalPrice') ? : 0,
            'amount' => $request->input('finalPrice') ? : 0,
            'coupon_code' => $request->input('couponCode'),
            'discount_amount' => $request->input('discount_amount') ? : 0,
            'promotion_amount' => $request->input('promotion_amount') ? : 0,
            'discount_description' => $request->input('discount_description'),
            // 'description' => $request->input('note'),
            'is_confirmed' => 1,
            'is_finished' => 1,
            'status' => OrderStatusEnum::PROCESSING,
            'lang' => $request->input('locale'),
            'cod_charge' => $request->input('codPrice') / (1 + ($request->input('vatTax') / 100)),
            'cod_charge_vat' => $request->input('codPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
        ]);

        // echo "<pre>";print_r($order);die();

        if($order) {

            if($request->input('customer_id')) {
                $loggedInCustomer = Customer::where('id', $request->input('customer_id'))->first();
                $loggedInCustomerAdd = Address::where('customer_id', $loggedInCustomer->id)->first();
                if(!$loggedInCustomerAdd) {
                    Address::create([
                        'name'      => $loggedInCustomer->name,
                        'email'     => $loggedInCustomer->email,
                        'phone'     => $loggedInCustomer->phone,
                        'state' => $request->input('billingAddress.emirates'),
                        'city' => $request->input('billingAddress.emirates'),
                        'country' => $request->input('billingAddress.country'),
                        'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                        'customer_id' => $loggedInCustomer->id,
                    ]);
                    $loggedInCustomerAdd = Address::where('customer_id', $loggedInCustomer->id)->first();
                }
                OrderAddress::query()->create([
                    'name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $loggedInCustomer->name,
                    'phone' => $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $loggedInCustomer->phone,
                    'email' => $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $loggedInCustomer->email,
                    'state' => $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $loggedInCustomerAdd->state,
                    'city' => $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $loggedInCustomerAdd->city,
                    'country' => $request->input('shippingAddress.country') ? $request->input('shippingAddress.country') : $loggedInCustomerAdd->country,
                    'address' => $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $loggedInCustomerAdd->address,
                    'order_id' => $order->id,
                    'type' => $request->input('shippingAddress.first_name') ? 'shipping_address' : 'billing_address',
                ]);

                if($request->input('payment_method') == 'paytabs') {            
                    $data = [
                        "name"=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $loggedInCustomer->name,
                        "email"=> $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $loggedInCustomer->email,
                        "phone"=> $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $loggedInCustomer->phone,
                        "street1"=> $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $loggedInCustomerAdd->address,
                        "city"=> $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $loggedInCustomerAdd->city,
                        "state"=> $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $loggedInCustomerAdd->state,
                        "country"=> "AE",
                        // "zip"=> "54321"
                    ];
                    // $resp = $this->payTabsPayment($request, $data);
                    // return response()->json([
                    //     'redirect_url'     => $resp['redirect_url']
                    // ]);
                }

            } else {
                OrderAddress::query()->create([
                    'name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                    'phone' => $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $request->input('billingAddress.mobile'),
                    'email' => $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $request->input('billingAddress.email'),
                    'state' => $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $request->input('billingAddress.emirates'),
                    'city' => $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $request->input('billingAddress.emirates'),
                    // 'zip_code' => $request->input('shippingAddress.zip_code'),
                    'country' => $request->input('shippingAddress.country') ? $request->input('shippingAddress.country') : $request->input('billingAddress.country'),
                    'address' => $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                    'order_id' => $order->id,
                    'type' => $request->input('shippingAddress.first_name') ? 'shipping_address' : 'billing_address',
                ]);

                if($request->input('payment_method') == 'paytabs') {
                    $data = [
                        "name"=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                        "email"=> $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $request->input('billingAddress.email'),
                        "phone"=> $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $request->input('billingAddress.mobile'),
                        "street1"=> $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                        "city"=> $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $request->input('billingAddress.emirates'),
                        "state"=> $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $request->input('billingAddress.emirates'),
                        "country"=> "AE",
                        // "zip"=> "54321"
                    ];
                    // $resp = $this->payTabsPayment($request, $data);
                    // return response()->json([
                    //     'redirect_url'     => $resp['redirect_url']
                    // ]);
                }
            }
            // die();
            OrderHistory::query()->create([
                'action' => OrderHistoryActionEnum::CREATE_ORDER_FROM_WEBSITE,
                'description' => trans('plugins/ecommerce::order.create_order_from_website'),
                'order_id' => $order->getKey(),
            ]);

            OrderHistory::query()->create([
                'action' => OrderHistoryActionEnum::CREATE_ORDER,
                'description' => trans(
                    'plugins/ecommerce::order.new_order',
                    ['order_id' => $order->code]
                ),
                'order_id' => $order->getKey(),
            ]);

            OrderHistory::query()->create([
                'action' => OrderHistoryActionEnum::CONFIRM_ORDER,
                'description' => trans('plugins/ecommerce::order.order_was_verified_by'),
                'order_id' => $order->getKey(),
                'user_id' => $customer_id,
            ]);

            $prod = array();
    
            foreach ($request->input('products') as $product) {
                
                $quantity = $product['quantity'] ? $product['quantity'] : 1;

                $exisProduct = Product::where('ec_products.id', $product['product_id'])
                // ->join('ec_tax_products', 'ec_products.id', '=', 'ec_tax_products.product_id')->join('ec_taxes', 'ec_taxes.id', '=', 'ec_tax_products.tax_id')
                ->first();

                // $exisProduct->discount = DiscountProduct::select('value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();

                // $coupons = DiscountProduct::select('code', 'value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->get();
                
                // $couponData = [];
                // foreach ($coupons as $coupon) {
                //     $couponData[strtolower($coupon->code)] = [
                //         'code' => strtolower($coupon->code),
                //         'value' => $coupon->value,
                //         'start_date' => $coupon->start_date,
                //         'end_date' => $coupon->end_date,
                //     ];
                // }

                // $exisProduct->coupon = $couponData;

                // Fetch active discount for the product
                $exisProduct->discount = null;

                $individualDiscount = Promotion::where('type', 'discount')
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->whereHas('discountRules', function ($query) {
                        $query->where('apply_to', 'individual');
                    })
                    ->whereHas('discountRules.individualRules', function ($query) use ($product) {
                        $query->where('product_id', $product['product_id']);
                    })
                    ->with(['discountRules' => function ($query) {
                        $query->where('apply_to', 'individual')
                            ->select('id', 'promotion_id', 'apply_to');
                    }, 'discountRules.individualRules' => function ($query) use ($product) {
                        $query->where('product_id', $product['product_id'])
                            ->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
                    }])
                    ->first();

                if ($individualDiscount) {
                    $discountRule = $individualDiscount->discountRules->first();
                    $individualRule = $discountRule ? $discountRule->individualRules->first() : null;
                    if ($individualRule) {
                        $exisProduct->discount = (object) [
                            'value' => intval($individualRule->value),
                            'apply_to' => $discountRule->apply_to,
                            'discount_type' => $individualRule->discount_type,
                            'product_price' => $individualRule->product_price,
                            'discount_amount' => $individualRule->discount_amount,
                            'final_price' => $individualRule->final_price,
                            'start_date' => $individualDiscount->start_date->format('Y-m-d H:i:s'),
                            'end_date' => $individualDiscount->end_date->format('Y-m-d H:i:s'),
                        ];
                    }
                } else {
                    // If no individual discount, try to fetch discount for group/all products
                    $groupDiscount = Promotion::where('type', 'discount')
                        ->whereDate('start_date', '<=', now())
                        ->whereDate('end_date', '>=', now())
                        ->whereHas('discountRules', function ($query) {
                            $query->where('apply_to', '!=', 'individual');
                        })
                        ->whereHas('discountRules.products', function ($query) use ($product) {
                            $query->where('product_id', $product['product_id']);
                        })
                        ->with(['discountRules' => function ($query) {
                            $query->where('apply_to', '!=', 'individual')
                                ->select('id', 'promotion_id', 'percentage', 'apply_to');
                        }])
                        ->first();

                    if ($groupDiscount) {
                        $discountRule = $groupDiscount->discountRules->first();
                        if ($discountRule) {
                            $exisProduct->discount = (object) [
                                'value' => intval($discountRule->percentage),
                                'apply_to' => $discountRule->apply_to,
                                'discount_type' => 'percent',
                                'product_price' => null,
                                'discount_amount' => null,
                                'final_price' => null,
                                'start_date' => $groupDiscount->start_date->format('Y-m-d H:i:s'),
                                'end_date' => $groupDiscount->end_date->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }

                // Fetch active coupons for the product
                $coupons = Promotion::where('type', 'coupon')
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->whereHas('couponRules.products', function ($query) use ($product) {
                        $query->where('product_id', $product['product_id']);
                    })
                    ->with(['couponRules' => function ($query) use ($product) {
                        $query->whereNotNull('coupon_code')
                            ->select('id', 'promotion_id', 'coupon_code', 'percentage')
                            ->with(['products' => function ($subQuery) use ($product) {
                                $subQuery->where('product_id', $product['product_id'])
                                        ->select('id', 'coupon_rule_id', 'product_id');
                            }]);
                    }])
                    ->get();

                $couponData = [];
                foreach ($coupons as $promotion) {
                    foreach ($promotion->couponRules as $couponRule) {
                        if ($couponRule->coupon_code && $couponRule->products->isNotEmpty()) {
                            $couponData[strtolower($couponRule->coupon_code)] = [
                                'code' => strtolower($couponRule->coupon_code),
                                'value' => intval($couponRule->percentage),
                                'start_date' => $promotion->start_date->format('Y-m-d H:i:s'),
                                'end_date' => $promotion->end_date->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }

                $exisProduct->coupon = $couponData;

                $customerCouponData = [];

                if ($coupons->isEmpty()) {
                    $customer_coupons = DiscountCustomer::select('code', 'value', 'start_date', 'end_date')->where('customer_id', $customer_id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();
                    foreach ($customer_coupons as $customer_coupon) {
                        $customerCouponData[strtolower($customer_coupon->code)] = [
                            'code' => strtolower($customer_coupon->code),
                            'value' => $customer_coupon->value,
                            'start_date' => $customer_coupon->start_date,
                            'end_date' => $customer_coupon->end_date,
                        ];
                    }
                    $exisProduct->customer_coupon = $customerCouponData;
                }

                $exisProduct->qty = $quantity;

                // print_r($exisProduct);

                if((isset($product['is_gift']) && $product['is_gift'] == true)) {
                    $exisProduct->is_gift = 1;
                }

                array_push($prod, $exisProduct);

                // $discount_price = '';
                // $sale_price = '';
                if(!is_null($exisProduct->discount)) {
                    if($exisProduct->discount->discount_type == 'percent') {
                         $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = $exisProduct->discount->value;
                        $discount_amount = ($total_amount / 100) * $discount_percent;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'product_name' => $exisProduct->name,
                            'product_image' => $exisProduct->image,
                            'qty' => $quantity,
                            'weight' => $exisProduct->weight,
                            'price' => $price,
                            'total_amount' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'product_options' => [],
                            'options' => json_encode($options),
                            'product_type' => $exisProduct->product_type,
                            'product_category' => $product['category_name'],
                            'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                            'vat' => $request->input('vatTax'),
                        ];   
                    } elseif($exisProduct->discount->discount_type == 'amount') {
                        $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $sale_price = $price - $exisProduct->discount->value;
                        $discount_percent = 0;
                        $discount_amount = $total_amount - ($sale_price * $quantity);
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'product_name' => $exisProduct->name,
                            'product_image' => $exisProduct->image,
                            'qty' => $quantity,
                            'weight' => $exisProduct->weight,
                            'price' => $price,
                            'total_amount' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'product_options' => [],
                            'options' => json_encode($options),
                            'product_type' => $exisProduct->product_type,
                            'product_category' => $product['category_name'],
                            'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                            'vat' => $request->input('vatTax'),
                        ];
                        
                    }
                } elseif(!empty($product['coupon']) && !is_null($exisProduct->coupon) && !empty($exisProduct->coupon) && isset($exisProduct->coupon) && isset($exisProduct->coupon[strtolower($request->input('couponCode'))]) && $exisProduct->coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $total_amount = $price * $quantity;
                    $discount_percent = $exisProduct->coupon[strtolower($request->input('couponCode'))]['value'];
                    $discount_amount = ($total_amount / 100) * $discount_percent;
                    $net_amount = $total_amount - $discount_amount;
                    $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                    $gross_amount = $net_amount + $tax_amount;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'product_name' => $exisProduct->name,
                        'product_image' => $exisProduct->image,
                        'qty' => $quantity,
                        'weight' => $exisProduct->weight,
                        'price' => $price,
                        'total_amount' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'product_options' => [],
                        'options' => json_encode($options),
                        'product_type' => $exisProduct->product_type,
                        'product_category' => $product['category_name'],
                        'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                        'vat' => $request->input('vatTax'),
                        'campaign' => strtolower($request->input('couponCode')) == 'welcome10' ? 'first_order_discount_2025' : $request->input('couponCode'),
                    ];
                } elseif(!is_null($exisProduct->customer_coupon) && !empty($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]) && $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $total_amount = $price * $quantity;
                    $discount_percent = $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['value'];
                    $discount_amount = ($total_amount / 100) * $discount_percent;
                    $net_amount = $total_amount - $discount_amount;
                    $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                    $gross_amount = $net_amount + $tax_amount;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'product_name' => $exisProduct->name,
                        'product_image' => $exisProduct->image,
                        'qty' => $quantity,
                        'weight' => $exisProduct->weight,
                        'price' => $price,
                        'total_amount' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'product_options' => [],
                        'options' => json_encode($options),
                        'product_type' => $exisProduct->product_type,
                        'product_category' => $product['category_name'],
                        'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                        'vat' => $request->input('vatTax'),
                        'campaign' => strtolower($request->input('couponCode')) == 'welcome10' ? 'first_order_discount_2025' : $request->input('couponCode'),
                    ];
                }
                // elseif(!is_null($exisProduct->sale_price)) {
                //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //     $total_amount = $price * $quantity;
                //     $sale_price = $exisProduct->sale_price / (1 + ($request->input('vatTax') / 100));
                //     $discount_percent = 0;
                //     $discount_amount = $total_amount - ($sale_price * $quantity);
                //     $net_amount = $total_amount - $discount_amount;
                //     $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //     $gross_amount = $net_amount + $tax_amount;
                //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                //     $orderProduct = [
                //         'order_id' => $order->id,
                //         'product_id' => $product['product_id'],
                //         'product_name' => $exisProduct->name,
                //         'product_image' => $exisProduct->image,
                //         'qty' => $quantity,
                //         'weight' => $exisProduct->weight,
                //         'price' => $price,
                //         'total_amount' => $total_amount,
                //         'discount_percent' => $discount_percent,
                //         'discount_amount' => $discount_amount,
                //         'net_amount' => $net_amount,
                //         'tax_amount' => $tax_amount,
                //         'gross_amount' => $gross_amount,
                //         'product_options' => [],
                //         'options' => json_encode($options),
                //         'product_type' => $exisProduct->product_type,
                //         'product_category' => $product['category_name'],
                //         'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                //         'vat' => $request->input('vatTax'),
                //     ];
                // }
                elseif(isset($product['is_gift']) && $product['is_gift'] == true) {
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $total_amount = 0.00;
                    $discount_percent = 100;
                    $discount_amount = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $net_amount = 0.00;
                    $tax_amount = 0.00;
                    $gross_amount = 0.00;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'product_name' => $exisProduct->name,
                        'product_image' => $exisProduct->image,
                        'qty' => $quantity,
                        'weight' => $exisProduct->weight,
                        'price' => $price,
                        'total_amount' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'product_options' => [],
                        'options' => json_encode($options),
                        'product_type' => $exisProduct->product_type,
                        'product_category' => '',
                        'product_subcategory' => '',
                        'vat' => $request->input('vatTax'),
                        'is_gift' => 1,
                        'campaign' => $product['campaign'],
                    ];
                }
                else {
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $total_amount = $price * $quantity;
                    $discount_percent = 0;
                    $discount_amount = 0.00;
                    $net_amount = $total_amount - $discount_amount;
                    $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                    $gross_amount = $net_amount + $tax_amount;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'product_name' => $exisProduct->name,
                        'product_image' => $exisProduct->image,
                        'qty' => $quantity,
                        'weight' => $exisProduct->weight,
                        'price' => $price,
                        'total_amount' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'product_options' => [],
                        'options' => json_encode($options),
                        'product_type' => $exisProduct->product_type,
                        'product_category' => $product['category_name'],
                        'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                        'vat' => $request->input('vatTax'),
                    ];
                }

                OrderProduct::query()->create($orderProduct);

                Product::query()
                    ->where('id', $product['product_id'])
                    ->where('with_storehouse_management', 1)
                    ->where('quantity', '>=', $quantity)
                    ->decrement('quantity', $quantity);
                
                // $url = "https://c21341-ifservice.cloudiax.com/api/ECommerce/StockStatus?itemCode=123456";
                // // $url = "https://c21341-ifservice.cloudiax.com/api/ECommerce/StockStatus?itemCode=".$exisProduct->barcode;

                // $ch = curl_init();

                // curl_setopt($ch, CURLOPT_URL, $url);
                // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // // Set the request method to POST
                // curl_setopt($ch, CURLOPT_POST, true);
                // curl_setopt($ch, CURLOPT_HTTPHEADER, [
                //     "Accept: application/json",
                //     "Company: UAE", 
                //     "Authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJVc2VySUQiOiJhZG1pbiIsIkVtcElEIjoiMTAyNDgiLCJDb21wYW55IjoiIiwiV2hzQ29kZSI6IidDdXN0b20nLCdETV8wMScsJ0ZHXzAxJywnRk9DJywnSUNfVUFFJywnUE1fMDEnLCdTUF8wMDEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDQnLCdTUF8wMDUnLCdTUF8wMDYnLCdTUF8wMDcnLCdTUF8wMDgnLCdTUF8wMDknLCdTUF8wMTAnLCdTUF8wMTEnLCdTUF8wMTInLCdTUF8wMTMnLCdTUF8wMTQnLCcwMScsJ0NOMDAxXzAxJywnQ3VzdG9tJywnRE1fMDEnLCdGR18wMScsJ0ZHXzAyJywnRkdfMDMnLCdGT0MnLCdJQ18wMScsJ0lDX1VBRScsJ1BNXzAxJywnU1BfMDAxJywnU1BfMDAxXzEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDNfMScsJ1NQXzAwNCcsJ1NQXzAwNScsJ1NQXzAwNicsJ1NQXzAwNycsJ1NQXzAwOCcsJ1NQXzAwOScsJ1NQXzAxMCcsJ1NQXzAxMScsJ1NQXzAxMicsJ1NQXzAxMycsJ1NQXzAxNCcsJ1NQXzAxNScsJ1NQXzAxNicsJ1NQXzAxNycsJ1NQXzAxOScsJ1NQXzAyMCcsJ1NQXzAyMF8xJywnU1BfMDIxJywnU1BfMDIyJywnU1BfMDIzJywnU1BfMDI0JywnU1BfMDI1JywnU1BfMDI2JywnU1BfMDI3JywnU1BfMDI4JywnU1BfMDI4XzEnLCdTUF8wMjhfMicsJ1NQXzAyOScsJ1NQXzAzMCcsJ1NQXzAzMScsJ1ZOXzAwMScsJ0N1c3RvbScsJ0RNXzAxJywnRkdfMDEnLCdGT0MnLCdJQ19VQUUnLCdQTV8wMScsJ1NQXzAwMScsJ1NQXzAwMicsJ1NQXzAwMycsJ1NQXzAwNCcsJ1NQXzAwNScsJ1NQXzAwNicsJ1NQXzAwNycsJ1NQXzAwOCcsJzAxJywnQ3VzdG9tJywnRE1fMDEnLCdGR18wMScsJ0ZPQycsJ0lDXzAxJywnSUNfTW92JywnSUNfT0FQJywnSUNfVUFFJywnUE1fMDEnLCdTUF8wMDEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDQnLCdTUF8wMDUnLCdTUF8wMDYnLCdTUF8wMDcnLCdTUF8wMDgnLCdTUF8wMDknLCdTUF8wMTAnLCdTUF8wMTEnLCdTUF8wMTInLCdTUF8wMTMnLCdTUF8wMTQnLCdTUF8wMTUnLCdTUF8wMTYnLCdTUF8wMTcnLCdTUF8wMTgnLCdTUF8wMTknLCdTUF8wMjAnLCdTUF8wMjEnLCdTUF8wMjInLCdTUF8wMjMnLCdTUF8wMjQnLCdTUF8wMjUnLCdTUF8wMjYnLCdTUF8wMjcnLCdTUF8wMjgnLCdTUF8wMjknLCdTUF8wMzAnLCdTUF8wMzEnLCdTUF8wMzInLCdTUF8wMzMnLCdTUF8wMzQnLCdTUF8wMzUnLCdTUF8wMzYnLCdTUF8wMzcnLCdTUF8wMzgnLCdTUF8wMzknLCdTUF8wNDAnLCdTUF8wNDEnLCdTUF8wNDInLCdTUF8wNDMnLCdTUF8wNDQnLCdTUF8wNDUnLCdTUF8wNDYnLCdTUF8wNDcnLCdTUF8wNDgnLCdTUF8wNDknLCdTUF8wNTAnLCdTUF8wNTEnLCdTUF8wNTInLCdTUF8wNTMnLCdTUF8wNTQnLCdTUF8wNTUnLCdTUF8wNTYnLCdTUF8wNTcnLCdTUF8wNTgnLCdTUF8wNTknLCdTUF8wNjAnLCdTUF8wNjEnLCdUWVNfMDEnLCcwMScsJ0NOMDAxXzAxJywnQ04wMDJfMDEnLCdDTjAwM18wMScsJ0NOMDA0XzAxJywnQ04wMDVfMDEnLCdDTjAwNl8wMScsJ0N1c3RvbScsJ0RNXzAxJywnRkdfMDEnLCdGR18wMicsJ0ZPQycsJ0lDX09NTicsJ0lDX1RZUycsJ0lDX1VBRScsJ1BNXzAxJywnU01QXzAxJywnU1BfMDAxJywnU1BfMDAyJywnU1BfMDAzJywnU1BfMDA0JywnU1BfMDA1JywnU1BfMDA2JywnU1BfMDA3JywnU1BfMDA4JywnU1BfMDA5JywnU1BfMDEwJywnU1BfMDExJywnU1BfMDEyJywnU1BfMDEzJywnU1BfMDE1JywnU1BfMDE2JywnU1BfMDE3JywnU1BfMDE4JywnU1BfMDE5JywnU1BfMDIwJywnU1BfMDIxJywnU1BfMDIyJywnMDEnLCdBbWF6b24nLCdBVF8wMScsJ0JLXzAxJywnQlJBTkQnLCdDMDIwMjM1NicsJ0NOMDAxXzAxJywnQ04wMDJfMDEnLCdDTjAwM18wMScsJ0NOMDA0XzAxJywnQ04wMDVfMDEnLCdDTjAwNl8wMScsJ0NOMDA3XzAxJywnQ04wMDhfMDEnLCdDV19TTTAwMCcsJ0NXX1NNMDAxJywnQ1dfU00wMDInLCdDV19TTTAwMycsJ0NXX1NNMDA0JywnQ1dfU00wMDUnLCdDV19TTTAwNicsJ0NXX1NNMDA3JywnQ1dfU00wMDgnLCdDV19TTTAwOScsJ0NXX1NNMDEwJywnRE1fMDEnLCdETV8wMicsJ0RNXzAzJywnRE1fMDQnLCdETV8wNScsJ0RNXzA2JywnRUNfMDEnLCdGR18wMScsJ0ZPQycsJ0dGXzAxJywnSUNfQU1QJywnSUNfQkhSJywnSUNfS1NBJywnSUNfTW92JywnSUNfT01OJywnSUNfUUFUJywnSVQnLCdJVDAyJywnUEtfMDEnLCdQTV8wMScsJ1BNXzAyJywnUUNfMDEnLCdSJkQnLCdTS18wMScsJ1NMXzAxJywnU01QXzAxJywnU1BfMDAxJywnU1BfMDAyJywnU1BfMDAzJywnU1BfMDA0JywnU1BfMDA1JywnU1BfMDA2JywnU1BfMDA3JywnU1BfMDA4JywnU1BfMDA5JywnU1BfMDEwJywnU1BfMDExJywnU1BfMDEyJywnU1BfMDEzJywnU1BfMDE0JywnU1BfMDE1JywnU1BfMDE2JywnU1BfMDE3JywnU1BfMDE4JywnU1BfMDE5JywnU1BfMDIwJywnU1BfMDIxJywnU1BfMDIyJywnU1BfMDIzJywnU1BfMDI0JywnU1BfMDI1JywnU1BfMDI2JywnU1BfMDI3JywnU1BfMDI4JywnU1BfMDI5JywnU1BfMDMwJywnU1BfMDMxJywnU1BfMDMyJywnU1BfMDMyXzEnLCdTUF8wMzMnLCdTUF8wMzQnLCdTUF8wMzUnLCdTUF8wMzYnLCdTUF8wMzcnLCdTUF8wMzgnLCdTUF8wMzknLCdTUF8wNDAnLCdTUF8wNDEnLCdTUF8wNDInLCdTUF8wNDMnLCdTUF8wNDQnLCdTUF8wNDUnLCdTUF8wNDYnLCdTUF8wNDcnLCdTUF8wNDgnLCdTUF8wNDknLCdTUF8wNTAnLCdTUF8wNTEnLCdTUF8wNTInLCdTUF8wNTMnLCdTUF8wNTQnLCdTUF8wNTUnLCdTUF8wNTYnLCdTUF8wNTcnLCdTUF8wNTgnLCdTUF8wNTknLCdTUF8wNjAnLCdTUF8wNjEnLCdTUF8wNjInLCdTUF8wNjMnLCdTUF8wNjQnLCdTUF8wNjUnLCdTUF8wNjYnLCdTUF8wNjcnLCdTUF8wNjgnLCdTUF8wNjknLCdTUF8wNzAnLCdTUF8wNzEnLCdTUF8wNzInLCdTUF8wNzMnLCdTUF8wNzQnLCdTUF8wNzUnLCdTUF8wNzYnLCdTUF8wNzcnLCdTUF8wNzknLCdTUF8wODAnLCdTUF8wODEnLCdTUF8wODInLCdTUF8wODMnLCdTUF8wODQnLCdTUF8wODUnLCdTUF8wODYnLCdTUF8wODgnLCdTUF8wODknLCdTUF8wOTAnLCdTUF8wOTEnLCdTUF8wOTInLCdXSF8wMScsJ1dIXzAyJywnV0hfMDMnLCdXSF8wNCcsJ1dIXzA1JywnV0hfMDYnLCdXSF9EUk0nLCdXSF9WZW5kJyIsIlN0b3JlSUQiOiInJywnSE8nLCdPRkInLCdITycsJ0hPJywnUCZFJywnU01BJywnQktXJywnQkNDJywnQlNUJywnSERMJywnREFNJywnSklEJywnQlVLJywnUkFNJywnQ0NCJywnSE1UJywnTUhSJywnQU1CJywnQlNTJywnJywnSE8nLCdITycsJycsJ0pETycsJ01ETycsJ0hPJywnSE8nLCcnLCdITycsJ1AmRScsJ0tBUycsJ0tBU1MnLCdKUUInLCdEQVQnLCdEQVRTJywnTk9SJywnQVNNJywnVEJBJywnQVpNJywnQktSJywnU0tEJywnVEdNJywnT0JNJywnSlVNJywnUUJBJywnS09TJywnU1NKJywnTU9OJywnU0FGJywnUUJGJywnS01TJywnS01TUycsJ01BRycsJ1lSTScsJ01VRycsJ01SSicsJ1NRSicsJ01ESCcsJ01ERycsJ01DVCcsJ01DVFMnLCdWTUNUJywnUkhCJywnT0JIJywnQkFTJywnS1NWJywnJywnSE8nLCcnLCdITycsJ0hPJywnUCZFJywnS1NNJywnSlJLJywnS01BJywnS09EJywnR0FUJywnQkxWJywnTUdUJywnTUdDJywnJywnSE8nLCdITycsJ09GTycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdQJkUnLCdTTVQnLCdTS0snLCdTRUInLCdCUksnLCdTTEwnLCdTVVInLCdOSVonLCdTV1EnLCdTT00nLCdTQU0nLCdCUk0nLCdFQlInLCdTQlgnLCdCRFknLCdLQlInLCdBTVInLCdTTk0nLCdBVk0nLCdMV00nLCdKTE4nLCdBS00nLCdBS0InLCdNU04nLCdTTlcnLCdSU1QnLCdCUkEnLCdZQU4nLCdTTE4nLCdTTFUnLCdTQUQnLCdNT00nLCdRVVInLCdCSUQnLCdLQU0nLCdLVUQnLCdTTUwnLCdTTlMnLCdDQ00nLCdNT08nLCdDQ1MnLCdKTFMnLCdPQVMnLCdTU1MnLCdETksnLCdCSEwnLCdNQVQnLCdBTlMnLCdBU0snLCdLQlMnLCdTTVMnLCdGTEonLCdEUU0nLCdFQlMnLCdGQU4nLCdCRFMnLCdBTVMnLCdCREQnLCdPT1MnLCdUTUQnLCdTV1MnLCdNVVMnLCdITycsJycsJycsJycsJycsJycsJycsJycsJycsJycsJ09GUScsJ0hPJywnJywnSE8nLCdITycsJ0hPJywnUCZFJywnSE8nLCdBWlknLCdTSEYnLCdOU1InLCdESEYnLCdNUVInLCdBTUonLCdET00nLCdBTUsnLCdMQkInLCdBV1MnLCdNUksnLCdBRlMnLCdXQVEnLCdRT1MnLCdRUk4nLCdJR1cnLCdFWkQnLCdWSUwnLCdOQVMnLCdTSE4nLCdXQVQnLCcnLCdITycsJ0hPJywnSE8nLCcnLCcnLCcnLCcnLCcnLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0FFQycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ1AmRScsJ0hPJywnSE8nLCcnLCcnLCdITycsJ0hPJywnREZNJywnQlNNJywnQk5ZJywnQ1RNJywnRE1LJywnS0hMJywnQUpDJywnTVpNJywnQUZNJywnQUFNJywnQldNJywnQlNHJywnQlNYJywnQUdNJywnQUJNJywnQUJDJywnTUZDJywnRFJDJywnREFGJywnRkpNJywnQUtIJywnS0hLJywnTU5NJywnUkFLJywnU0hNJywnTVJEJywnU1JDJywnU0JTJywnU01NJywnTUFNJywnVUFRJywnSlJOJywnSlJNJywnU1FNJywnUk1aJywnQVNTJywnQkFSJywnS0hNJywnTU9RJywnRExNJywnQVlSJywnVUNKJywnQUdaJywnUkhNJywnVUNBJywnVUNCJywnRkNDJywnR0JWJywnRFJNJywnU0NIJywnSFRUJywnTVNGJywnSk1NJywnWkNDJywnR1lNJywnRkNNJywnTVNNJywnREhEJywnUklGJywnS0JNJywnSE1EJywnUldEJywnS1dTJywnQUFLJywnQlJTJywnRE9TJywnU0xNJywnREVSJywnU0NEJywnS0xGJywnU0JBJywnTURNJywnSlJGJywnTExaJywnRkpTJywnUkZNJywnRE1CJywnTVJCJywnREhNJywnSURXJywnSkNQJywnRFNTJywnTVNLJywnSE1BJywnRElCJywnRFNRJywnVU1CJywnQUtEJywnSFRTJywnWUFTJywnR0JJJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnRFdTJywnJyIsIlRlcm1pbmFsSUQiOiIiLCJzYWxlc1BlcnNvbklkIjoiIiwiem9uZUlkIjoiJyonIiwiZXhwIjoxNzczNTU5MjYyfQ.JZfGnaPSXmCanQfq3OWPRkYqqzy_rM9LLyLLiTLMFOo"
                // ]);

                // $response = curl_exec($ch);

                // if (curl_errno($ch)) {
                //     echo 'Error: ' . curl_error($ch);
                // }

                // curl_close($ch);

                // echo $response;
            }
            // die($exisProduct->barcode);

            // $url = "https://c21341-ifservice.cloudiax.com/api/ECommerce/StockStatus?itemCode=".implode(',', $barcodes);

            // // die($url);

            // $ch = curl_init();

            // curl_setopt($ch, CURLOPT_URL, $url);
            // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // // Set the request method to POST
            // curl_setopt($ch, CURLOPT_POST, true);
            // curl_setopt($ch, CURLOPT_HTTPHEADER, [
            //     "Accept: application/json",
            //     "Company: UAE", 
            //     "Authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJVc2VySUQiOiJhZG1pbiIsIkVtcElEIjoiMTAyNDgiLCJDb21wYW55IjoiIiwiV2hzQ29kZSI6IidDdXN0b20nLCdETV8wMScsJ0ZHXzAxJywnRk9DJywnSUNfVUFFJywnUE1fMDEnLCdTUF8wMDEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDQnLCdTUF8wMDUnLCdTUF8wMDYnLCdTUF8wMDcnLCdTUF8wMDgnLCdTUF8wMDknLCdTUF8wMTAnLCdTUF8wMTEnLCdTUF8wMTInLCdTUF8wMTMnLCdTUF8wMTQnLCcwMScsJ0NOMDAxXzAxJywnQ3VzdG9tJywnRE1fMDEnLCdGR18wMScsJ0ZHXzAyJywnRkdfMDMnLCdGT0MnLCdJQ18wMScsJ0lDX1VBRScsJ1BNXzAxJywnU1BfMDAxJywnU1BfMDAxXzEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDNfMScsJ1NQXzAwNCcsJ1NQXzAwNScsJ1NQXzAwNicsJ1NQXzAwNycsJ1NQXzAwOCcsJ1NQXzAwOScsJ1NQXzAxMCcsJ1NQXzAxMScsJ1NQXzAxMicsJ1NQXzAxMycsJ1NQXzAxNCcsJ1NQXzAxNScsJ1NQXzAxNicsJ1NQXzAxNycsJ1NQXzAxOScsJ1NQXzAyMCcsJ1NQXzAyMF8xJywnU1BfMDIxJywnU1BfMDIyJywnU1BfMDIzJywnU1BfMDI0JywnU1BfMDI1JywnU1BfMDI2JywnU1BfMDI3JywnU1BfMDI4JywnU1BfMDI4XzEnLCdTUF8wMjhfMicsJ1NQXzAyOScsJ1NQXzAzMCcsJ1NQXzAzMScsJ1ZOXzAwMScsJ0N1c3RvbScsJ0RNXzAxJywnRkdfMDEnLCdGT0MnLCdJQ19VQUUnLCdQTV8wMScsJ1NQXzAwMScsJ1NQXzAwMicsJ1NQXzAwMycsJ1NQXzAwNCcsJ1NQXzAwNScsJ1NQXzAwNicsJ1NQXzAwNycsJ1NQXzAwOCcsJzAxJywnQ3VzdG9tJywnRE1fMDEnLCdGR18wMScsJ0ZPQycsJ0lDXzAxJywnSUNfTW92JywnSUNfT0FQJywnSUNfVUFFJywnUE1fMDEnLCdTUF8wMDEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDQnLCdTUF8wMDUnLCdTUF8wMDYnLCdTUF8wMDcnLCdTUF8wMDgnLCdTUF8wMDknLCdTUF8wMTAnLCdTUF8wMTEnLCdTUF8wMTInLCdTUF8wMTMnLCdTUF8wMTQnLCdTUF8wMTUnLCdTUF8wMTYnLCdTUF8wMTcnLCdTUF8wMTgnLCdTUF8wMTknLCdTUF8wMjAnLCdTUF8wMjEnLCdTUF8wMjInLCdTUF8wMjMnLCdTUF8wMjQnLCdTUF8wMjUnLCdTUF8wMjYnLCdTUF8wMjcnLCdTUF8wMjgnLCdTUF8wMjknLCdTUF8wMzAnLCdTUF8wMzEnLCdTUF8wMzInLCdTUF8wMzMnLCdTUF8wMzQnLCdTUF8wMzUnLCdTUF8wMzYnLCdTUF8wMzcnLCdTUF8wMzgnLCdTUF8wMzknLCdTUF8wNDAnLCdTUF8wNDEnLCdTUF8wNDInLCdTUF8wNDMnLCdTUF8wNDQnLCdTUF8wNDUnLCdTUF8wNDYnLCdTUF8wNDcnLCdTUF8wNDgnLCdTUF8wNDknLCdTUF8wNTAnLCdTUF8wNTEnLCdTUF8wNTInLCdTUF8wNTMnLCdTUF8wNTQnLCdTUF8wNTUnLCdTUF8wNTYnLCdTUF8wNTcnLCdTUF8wNTgnLCdTUF8wNTknLCdTUF8wNjAnLCdTUF8wNjEnLCdUWVNfMDEnLCcwMScsJ0NOMDAxXzAxJywnQ04wMDJfMDEnLCdDTjAwM18wMScsJ0NOMDA0XzAxJywnQ04wMDVfMDEnLCdDTjAwNl8wMScsJ0N1c3RvbScsJ0RNXzAxJywnRkdfMDEnLCdGR18wMicsJ0ZPQycsJ0lDX09NTicsJ0lDX1RZUycsJ0lDX1VBRScsJ1BNXzAxJywnU01QXzAxJywnU1BfMDAxJywnU1BfMDAyJywnU1BfMDAzJywnU1BfMDA0JywnU1BfMDA1JywnU1BfMDA2JywnU1BfMDA3JywnU1BfMDA4JywnU1BfMDA5JywnU1BfMDEwJywnU1BfMDExJywnU1BfMDEyJywnU1BfMDEzJywnU1BfMDE1JywnU1BfMDE2JywnU1BfMDE3JywnU1BfMDE4JywnU1BfMDE5JywnU1BfMDIwJywnU1BfMDIxJywnU1BfMDIyJywnMDEnLCdBbWF6b24nLCdBVF8wMScsJ0JLXzAxJywnQlJBTkQnLCdDMDIwMjM1NicsJ0NOMDAxXzAxJywnQ04wMDJfMDEnLCdDTjAwM18wMScsJ0NOMDA0XzAxJywnQ04wMDVfMDEnLCdDTjAwNl8wMScsJ0NOMDA3XzAxJywnQ04wMDhfMDEnLCdDV19TTTAwMCcsJ0NXX1NNMDAxJywnQ1dfU00wMDInLCdDV19TTTAwMycsJ0NXX1NNMDA0JywnQ1dfU00wMDUnLCdDV19TTTAwNicsJ0NXX1NNMDA3JywnQ1dfU00wMDgnLCdDV19TTTAwOScsJ0NXX1NNMDEwJywnRE1fMDEnLCdETV8wMicsJ0RNXzAzJywnRE1fMDQnLCdETV8wNScsJ0RNXzA2JywnRUNfMDEnLCdGR18wMScsJ0ZPQycsJ0dGXzAxJywnSUNfQU1QJywnSUNfQkhSJywnSUNfS1NBJywnSUNfTW92JywnSUNfT01OJywnSUNfUUFUJywnSVQnLCdJVDAyJywnUEtfMDEnLCdQTV8wMScsJ1BNXzAyJywnUUNfMDEnLCdSJkQnLCdTS18wMScsJ1NMXzAxJywnU01QXzAxJywnU1BfMDAxJywnU1BfMDAyJywnU1BfMDAzJywnU1BfMDA0JywnU1BfMDA1JywnU1BfMDA2JywnU1BfMDA3JywnU1BfMDA4JywnU1BfMDA5JywnU1BfMDEwJywnU1BfMDExJywnU1BfMDEyJywnU1BfMDEzJywnU1BfMDE0JywnU1BfMDE1JywnU1BfMDE2JywnU1BfMDE3JywnU1BfMDE4JywnU1BfMDE5JywnU1BfMDIwJywnU1BfMDIxJywnU1BfMDIyJywnU1BfMDIzJywnU1BfMDI0JywnU1BfMDI1JywnU1BfMDI2JywnU1BfMDI3JywnU1BfMDI4JywnU1BfMDI5JywnU1BfMDMwJywnU1BfMDMxJywnU1BfMDMyJywnU1BfMDMyXzEnLCdTUF8wMzMnLCdTUF8wMzQnLCdTUF8wMzUnLCdTUF8wMzYnLCdTUF8wMzcnLCdTUF8wMzgnLCdTUF8wMzknLCdTUF8wNDAnLCdTUF8wNDEnLCdTUF8wNDInLCdTUF8wNDMnLCdTUF8wNDQnLCdTUF8wNDUnLCdTUF8wNDYnLCdTUF8wNDcnLCdTUF8wNDgnLCdTUF8wNDknLCdTUF8wNTAnLCdTUF8wNTEnLCdTUF8wNTInLCdTUF8wNTMnLCdTUF8wNTQnLCdTUF8wNTUnLCdTUF8wNTYnLCdTUF8wNTcnLCdTUF8wNTgnLCdTUF8wNTknLCdTUF8wNjAnLCdTUF8wNjEnLCdTUF8wNjInLCdTUF8wNjMnLCdTUF8wNjQnLCdTUF8wNjUnLCdTUF8wNjYnLCdTUF8wNjcnLCdTUF8wNjgnLCdTUF8wNjknLCdTUF8wNzAnLCdTUF8wNzEnLCdTUF8wNzInLCdTUF8wNzMnLCdTUF8wNzQnLCdTUF8wNzUnLCdTUF8wNzYnLCdTUF8wNzcnLCdTUF8wNzknLCdTUF8wODAnLCdTUF8wODEnLCdTUF8wODInLCdTUF8wODMnLCdTUF8wODQnLCdTUF8wODUnLCdTUF8wODYnLCdTUF8wODgnLCdTUF8wODknLCdTUF8wOTAnLCdTUF8wOTEnLCdTUF8wOTInLCdXSF8wMScsJ1dIXzAyJywnV0hfMDMnLCdXSF8wNCcsJ1dIXzA1JywnV0hfMDYnLCdXSF9EUk0nLCdXSF9WZW5kJyIsIlN0b3JlSUQiOiInJywnSE8nLCdPRkInLCdITycsJ0hPJywnUCZFJywnU01BJywnQktXJywnQkNDJywnQlNUJywnSERMJywnREFNJywnSklEJywnQlVLJywnUkFNJywnQ0NCJywnSE1UJywnTUhSJywnQU1CJywnQlNTJywnJywnSE8nLCdITycsJycsJ0pETycsJ01ETycsJ0hPJywnSE8nLCcnLCdITycsJ1AmRScsJ0tBUycsJ0tBU1MnLCdKUUInLCdEQVQnLCdEQVRTJywnTk9SJywnQVNNJywnVEJBJywnQVpNJywnQktSJywnU0tEJywnVEdNJywnT0JNJywnSlVNJywnUUJBJywnS09TJywnU1NKJywnTU9OJywnU0FGJywnUUJGJywnS01TJywnS01TUycsJ01BRycsJ1lSTScsJ01VRycsJ01SSicsJ1NRSicsJ01ESCcsJ01ERycsJ01DVCcsJ01DVFMnLCdWTUNUJywnUkhCJywnT0JIJywnQkFTJywnS1NWJywnJywnSE8nLCcnLCdITycsJ0hPJywnUCZFJywnS1NNJywnSlJLJywnS01BJywnS09EJywnR0FUJywnQkxWJywnTUdUJywnTUdDJywnJywnSE8nLCdITycsJ09GTycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdQJkUnLCdTTVQnLCdTS0snLCdTRUInLCdCUksnLCdTTEwnLCdTVVInLCdOSVonLCdTV1EnLCdTT00nLCdTQU0nLCdCUk0nLCdFQlInLCdTQlgnLCdCRFknLCdLQlInLCdBTVInLCdTTk0nLCdBVk0nLCdMV00nLCdKTE4nLCdBS00nLCdBS0InLCdNU04nLCdTTlcnLCdSU1QnLCdCUkEnLCdZQU4nLCdTTE4nLCdTTFUnLCdTQUQnLCdNT00nLCdRVVInLCdCSUQnLCdLQU0nLCdLVUQnLCdTTUwnLCdTTlMnLCdDQ00nLCdNT08nLCdDQ1MnLCdKTFMnLCdPQVMnLCdTU1MnLCdETksnLCdCSEwnLCdNQVQnLCdBTlMnLCdBU0snLCdLQlMnLCdTTVMnLCdGTEonLCdEUU0nLCdFQlMnLCdGQU4nLCdCRFMnLCdBTVMnLCdCREQnLCdPT1MnLCdUTUQnLCdTV1MnLCdNVVMnLCdITycsJycsJycsJycsJycsJycsJycsJycsJycsJycsJ09GUScsJ0hPJywnJywnSE8nLCdITycsJ0hPJywnUCZFJywnSE8nLCdBWlknLCdTSEYnLCdOU1InLCdESEYnLCdNUVInLCdBTUonLCdET00nLCdBTUsnLCdMQkInLCdBV1MnLCdNUksnLCdBRlMnLCdXQVEnLCdRT1MnLCdRUk4nLCdJR1cnLCdFWkQnLCdWSUwnLCdOQVMnLCdTSE4nLCdXQVQnLCcnLCdITycsJ0hPJywnSE8nLCcnLCcnLCcnLCcnLCcnLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0FFQycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ1AmRScsJ0hPJywnSE8nLCcnLCcnLCdITycsJ0hPJywnREZNJywnQlNNJywnQk5ZJywnQ1RNJywnRE1LJywnS0hMJywnQUpDJywnTVpNJywnQUZNJywnQUFNJywnQldNJywnQlNHJywnQlNYJywnQUdNJywnQUJNJywnQUJDJywnTUZDJywnRFJDJywnREFGJywnRkpNJywnQUtIJywnS0hLJywnTU5NJywnUkFLJywnU0hNJywnTVJEJywnU1JDJywnU0JTJywnU01NJywnTUFNJywnVUFRJywnSlJOJywnSlJNJywnU1FNJywnUk1aJywnQVNTJywnQkFSJywnS0hNJywnTU9RJywnRExNJywnQVlSJywnVUNKJywnQUdaJywnUkhNJywnVUNBJywnVUNCJywnRkNDJywnR0JWJywnRFJNJywnU0NIJywnSFRUJywnTVNGJywnSk1NJywnWkNDJywnR1lNJywnRkNNJywnTVNNJywnREhEJywnUklGJywnS0JNJywnSE1EJywnUldEJywnS1dTJywnQUFLJywnQlJTJywnRE9TJywnU0xNJywnREVSJywnU0NEJywnS0xGJywnU0JBJywnTURNJywnSlJGJywnTExaJywnRkpTJywnUkZNJywnRE1CJywnTVJCJywnREhNJywnSURXJywnSkNQJywnRFNTJywnTVNLJywnSE1BJywnRElCJywnRFNRJywnVU1CJywnQUtEJywnSFRTJywnWUFTJywnR0JJJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnRFdTJywnJyIsIlRlcm1pbmFsSUQiOiIiLCJzYWxlc1BlcnNvbklkIjoiIiwiem9uZUlkIjoiJyonIiwiZXhwIjoxNzczNTU5MjYyfQ.JZfGnaPSXmCanQfq3OWPRkYqqzy_rM9LLyLLiTLMFOo"
            // ]);

            // $response = curl_exec($ch);

            // if (curl_errno($ch)) {
            //     echo 'Error: ' . curl_error($ch);
            // }

            // curl_close($ch);

            // echo $response;

            if ($couponCode = $request->input('couponCode')) {
                // Discount::getFacadeRoot()->afterOrderPlaced($couponCode, $request->input('customer_id') ? $request->input('customer_id') : $customer_id);

                $now = Carbon::now();

                $coupon = DB::table('coupon_rules')
                ->join('promotions', 'promotions.id', '=', 'coupon_rules.promotion_id')
                ->where('coupon_code', $couponCode)
                ->where('type', 'coupon')
                ->where('start_date', '<=', $now)
                ->Where('end_date', '>', $now)
                ->select('coupon_rules.id', 'coupon_rules.promotion_id')
                ->first();

                if ($coupon) {
                    DB::table('coupon_rules')->where('id', $coupon->id)->increment('total_used');
                    $promotionId = $coupon->promotion_id;

                    DB::table('ec_customer_used_coupons')->insert([
                        'customer_id' => $request->input('customer_id') ?? $customer_id,
                        'discount_id' => $promotionId
                    ]);
                }
            }

            if($request->input('customer_id')) {
                $loggedInCustomer = Customer::where('id', $request->input('customer_id'))->first();
            } else {
                $loggedInCustomer = null;
            }

            $invoice = Invoice::query()->create([
                'reference_type' => 'Botble\Ecommerce\Models\Order',
                'reference_id' => $order->id,
                'customer_name' => $loggedInCustomer ? $loggedInCustomer->name : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                'customer_email' => $loggedInCustomer ? $loggedInCustomer->email : $request->input('billingAddress.email'),
                'customer_phone' => $loggedInCustomer ? $loggedInCustomer->phone : $request->input('billingAddress.mobile'),
                'customer_address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                'sub_total' => $request->input('totalPrice') ? : 0,
                'tax_amount' => ($request->input('totalPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)),
                'shipping_amount' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)),
                'shipping_amount_vat' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
                'service_amount' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)),
                'service_amount_vat' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
                'vat' => $request->input('vatTax'),
                'discount_amount' => $request->input('discount_amount') ? : 0,
                'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
                'coupon_code' => $request->input('couponCode'),
                'discount_description' => $request->input('discount_description'),
                'amount' => $request->input('finalPrice'),
                'payment_id' => $order->payment_id,
                'status' => $request->input('payment_status'),
            ]);

            foreach ($request->input('products') as $product) {
                
                $quantity = $product['quantity'] ? $product['quantity'] : 1;

                $exisProduct = Product::where('id', $product['product_id'])->first();

                // $exisProduct->discount = DiscountProduct::select('value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();

                // $coupons = DiscountProduct::select('code', 'value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->get();

                // // Store in a temporary property or a new array
                // $couponData = [];
                // foreach ($coupons as $coupon) {
                //     $couponData[strtolower($coupon->code)] = [
                //         'code' => strtolower($coupon->code),
                //         'value' => $coupon->value,
                //         'start_date' => $coupon->start_date,
                //         'end_date' => $coupon->end_date,
                //     ];
                // }

                // $exisProduct->coupon = $couponData;

                // Fetch active discount for the product
                $exisProduct->discount = null;

                $individualDiscount = Promotion::where('type', 'discount')
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->whereHas('discountRules', function ($query) {
                        $query->where('apply_to', 'individual');
                    })
                    ->whereHas('discountRules.individualRules', function ($query) use ($product) {
                        $query->where('product_id', $product['product_id']);
                    })
                    ->with(['discountRules' => function ($query) {
                        $query->where('apply_to', 'individual')
                            ->select('id', 'promotion_id', 'apply_to');
                    }, 'discountRules.individualRules' => function ($query) use ($product) {
                        $query->where('product_id', $product['product_id'])
                            ->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
                    }])
                    ->first();

                if ($individualDiscount) {
                    $discountRule = $individualDiscount->discountRules->first();
                    $individualRule = $discountRule ? $discountRule->individualRules->first() : null;
                    if ($individualRule) {
                        $exisProduct->discount = (object) [
                            'value' => intval($individualRule->value),
                            'apply_to' => $discountRule->apply_to,
                            'discount_type' => $individualRule->discount_type,
                            'product_price' => $individualRule->product_price,
                            'discount_amount' => $individualRule->discount_amount,
                            'final_price' => $individualRule->final_price,
                            'start_date' => $individualDiscount->start_date->format('Y-m-d H:i:s'),
                            'end_date' => $individualDiscount->end_date->format('Y-m-d H:i:s'),
                        ];
                    }
                } else {
                    // If no individual discount, try to fetch discount for group/all products
                    $groupDiscount = Promotion::where('type', 'discount')
                        ->whereDate('start_date', '<=', now())
                        ->whereDate('end_date', '>=', now())
                        ->whereHas('discountRules', function ($query) {
                            $query->where('apply_to', '!=', 'individual');
                        })
                        ->whereHas('discountRules.products', function ($query) use ($product) {
                            $query->where('product_id', $product['product_id']);
                        })
                        ->with(['discountRules' => function ($query) {
                            $query->where('apply_to', '!=', 'individual')
                                ->select('id', 'promotion_id', 'percentage', 'apply_to');
                        }])
                        ->first();

                    if ($groupDiscount) {
                        $discountRule = $groupDiscount->discountRules->first();
                        if ($discountRule) {
                            $exisProduct->discount = (object) [
                                'value' => intval($discountRule->percentage),
                                'apply_to' => $discountRule->apply_to,
                                'discount_type' => 'percent',
                                'product_price' => null,
                                'discount_amount' => null,
                                'final_price' => null,
                                'start_date' => $groupDiscount->start_date->format('Y-m-d H:i:s'),
                                'end_date' => $groupDiscount->end_date->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }

                // Fetch active coupons for the product
                $coupons = Promotion::where('type', 'coupon')
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->whereHas('couponRules.products', function ($query) use ($product) {
                        $query->where('product_id', $product['product_id']);
                    })
                    ->with(['couponRules' => function ($query) use ($product) {
                        $query->whereNotNull('coupon_code')
                            ->select('id', 'promotion_id', 'coupon_code', 'percentage')
                            ->with(['products' => function ($subQuery) use ($product) {
                                $subQuery->where('product_id', $product['product_id'])
                                        ->select('id', 'coupon_rule_id', 'product_id');
                            }]);
                    }])
                    ->get();

                $couponData = [];
                foreach ($coupons as $promotion) {
                    foreach ($promotion->couponRules as $couponRule) {
                        if ($couponRule->coupon_code && $couponRule->products->isNotEmpty()) {
                            $couponData[strtolower($couponRule->coupon_code)] = [
                                'code' => strtolower($couponRule->coupon_code),
                                'value' => intval($couponRule->percentage),
                                'start_date' => $promotion->start_date->format('Y-m-d H:i:s'),
                                'end_date' => $promotion->end_date->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }

                $exisProduct->coupon = $couponData;

                $customerCouponData = [];

                if ($coupons->isEmpty()) {
                    $customer_coupons = DiscountCustomer::select('code', 'value', 'start_date', 'end_date')->where('customer_id', $customer_id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();
                    foreach ($customer_coupons as $customer_coupon) {
                        $customerCouponData[strtolower($customer_coupon->code)] = [
                            'code' => strtolower($customer_coupon->code),
                            'value' => $customer_coupon->value,
                            'start_date' => $customer_coupon->start_date,
                            'end_date' => $customer_coupon->end_date,
                        ];
                    }
                    $exisProduct->customer_coupon = $customerCouponData;
                }

                if(!is_null($exisProduct->discount)) {
                    if($exisProduct->discount->discount_type == 'percent') {
                        $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = $exisProduct->discount->value;
                        $discount_amount = ($total_amount / 100) * $discount_percent;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'invoice_id' => $invoice->id,
                            'reference_type' => 'Botble\Ecommerce\Models\Product',
                            'reference_id' => $exisProduct->id,
                            'name' => $exisProduct->name,
                            'description' => $exisProduct->description,
                            'image' => $exisProduct->image,
                            'qty' => $quantity,
                            'price' => $price,
                            'sub_total' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'amount' => $gross_amount,
                            'options' => json_encode($options),
                        ];   
                    } elseif($exisProduct->discount->discount_type == 'amount') {
                        $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $sale_price = $price - $exisProduct->discount->value;
                        $discount_percent = 0;
                        $discount_amount = $total_amount - ($sale_price * $quantity);
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'invoice_id' => $invoice->id,
                            'reference_type' => 'Botble\Ecommerce\Models\Product',
                            'reference_id' => $exisProduct->id,
                            'name' => $exisProduct->name,
                            'description' => $exisProduct->description,
                            'image' => $exisProduct->image,
                            'qty' => $quantity,
                            'price' => $price,
                            'sub_total' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'amount' => $gross_amount,
                            'options' => json_encode($options),
                        ];
                    }
                } elseif(!empty($product['coupon']) && !is_null($exisProduct->coupon) && !empty($exisProduct->coupon) && isset($exisProduct->coupon) && isset($exisProduct->coupon[strtolower($request->input('couponCode'))]) && $exisProduct->coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $total_amount = $price * $quantity;
                    $discount_percent = $exisProduct->coupon[strtolower($request->input('couponCode'))]['value'];
                    $discount_amount = ($total_amount / 100) * $discount_percent;
                    $net_amount = $total_amount - $discount_amount;
                    $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                    $gross_amount = $net_amount + $tax_amount;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'invoice_id' => $invoice->id,
                        'reference_type' => 'Botble\Ecommerce\Models\Product',
                        'reference_id' => $exisProduct->id,
                        'name' => $exisProduct->name,
                        'description' => $exisProduct->description,
                        'image' => $exisProduct->image,
                        'qty' => $quantity,
                        'price' => $price,
                        'sub_total' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'amount' => $gross_amount,
                        'options' => json_encode($options),
                    ];
                } elseif(!is_null($exisProduct->customer_coupon) && !empty($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]) && $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $total_amount = $price * $quantity;
                    $discount_percent = $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['value'];
                    $discount_amount = ($total_amount / 100) * $discount_percent;
                    $net_amount = $total_amount - $discount_amount;
                    $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                    $gross_amount = $net_amount + $tax_amount;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'invoice_id' => $invoice->id,
                        'reference_type' => 'Botble\Ecommerce\Models\Product',
                        'reference_id' => $exisProduct->id,
                        'name' => $exisProduct->name,
                        'description' => $exisProduct->description,
                        'image' => $exisProduct->image,
                        'qty' => $quantity,
                        'price' => $price,
                        'sub_total' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'amount' => $gross_amount,
                        'options' => json_encode($options),
                    ];
                }
                // elseif(!is_null($exisProduct->sale_price)) {
                //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //     $total_amount = $price * $quantity;
                //     $sale_price = $exisProduct->sale_price / (1 + ($request->input('vatTax') / 100));
                //     $discount_percent = 0;
                //     $discount_amount = $total_amount - ($sale_price * $quantity);
                //     $net_amount = $total_amount - $discount_amount;
                //     $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //     $gross_amount = $net_amount + $tax_amount;
                //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                //     $orderProduct = [
                //         'invoice_id' => $invoice->id,
                //         'reference_type' => 'Botble\Ecommerce\Models\Product',
                //         'reference_id' => $exisProduct->id,
                //         'name' => $exisProduct->name,
                //         'description' => $exisProduct->description,
                //         'image' => $exisProduct->image,
                //         'qty' => $quantity,
                //         'price' => $price,
                //         'sub_total' => $total_amount,
                //         'discount_percent' => $discount_percent,
                //         'discount_amount' => $discount_amount,
                //         'net_amount' => $net_amount,
                //         'tax_amount' => $tax_amount,
                //         'gross_amount' => $gross_amount,
                //         'amount' => $gross_amount,
                //         'options' => json_encode($options),
                //     ];
                // }
                elseif(isset($product['is_gift']) && $product['is_gift'] == true) {
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $total_amount = 0.00;
                    $discount_percent = 100;
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $net_amount = 0.00;
                    $tax_amount = 0.00;
                    $gross_amount = 0.00;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'invoice_id' => $invoice->id,
                        'reference_type' => 'Botble\Ecommerce\Models\Product',
                        'reference_id' => $exisProduct->id,
                        'name' => $exisProduct->name,
                        'description' => $exisProduct->description,
                        'image' => $exisProduct->image,
                        'qty' => $quantity,
                        'price' => $price,
                        'sub_total' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'amount' => $gross_amount,
                        'options' => json_encode($options)
                    ];
                }
                else {
                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $total_amount = $price * $quantity;
                    $discount_percent = 0;
                    $discount_amount = 0.00;
                    $net_amount = $total_amount - $discount_amount;
                    $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                    $gross_amount = $net_amount + $tax_amount;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'invoice_id' => $invoice->id,
                        'reference_type' => 'Botble\Ecommerce\Models\Product',
                        'reference_id' => $exisProduct->id,
                        'name' => $exisProduct->name,
                        'description' => $exisProduct->description,
                        'image' => $exisProduct->image,
                        'qty' => $quantity,
                        'price' => $price,
                        'sub_total' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'amount' => $gross_amount,
                        'options' => json_encode($options),
                    ];
                }

                InvoiceItem::query()->create($orderProduct);
            }

            if($request->input('payment_method') == 'paytabs') {
                $resp = $this->payTabsPayment($request, $data, $order);
                if($resp['redirect_url']) {
                    return response()->json([
                        'message'          => 'Redirecting to Paytabs...',
                        'order_id'         => $order->code,
                        'payment_method'   => $request->input('payment_method'),
                        'total'            => $order->amount,
                        'sub_total'        => $order->sub_total,
                        'shipping_amount'  => $order->shipping_amount,
                        'products'         => $prod,
                        'redirect_url'     => $resp['redirect_url']
                    ]);
                }
            }

            // $request['payment_status'] = 'completed';
            $createPaymentForOrderService->execute(
                $order,
                $request->input('payment_method'),
                'completed',
                $customer_id
            );

            return response()->json([
                'message'          => 'Order created successfully',
                'order_id'         => $order->code,
                'id'                => $order->id,
                'customer_name'=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                'payment_method'   => $request->input('payment_method'),
                'total'            => $order->amount,
                'sub_total'        => $order->sub_total,
                'shipping_amount'  => $order->shipping_amount,
                'products'         => $prod
            ]);
        }
    }

    public function payTabsPayment(Request $request, $shippingData, $order) {
        $paymentStr = '';
        foreach ($request->input('products') as $product) {
            $quantity = $product['quantity'] ? $product['quantity'] : 1;
            $exisProduct = Product::select('name')->where('ec_products.id', $product['product_id'])->first();
            $paymentStr .= $exisProduct->name. ' ('.$quantity.'), ';
        }

        // $card_discounts = [];
        // if($request->input('payment_method') == 'paytabs_discount') {
        //     $card_discounts = [
        //         [
        //             "discount_cards" => "4111, 5200",
        //             "discount_percent" => "10.00",
        //             "discount_title" => "10% AED discount on cards start with 4111, 5200"
        //         ],
        //     ];
        // }

        $data = [
            "tran_type"=> "sale",
            "tran_class"=> "ecom",
            "cart_id"=> explode('#', $order->code)[1],
            "cart_currency"=> "AED",
            "cart_amount"=> $request->input('finalPrice'),
            "cart_description"=> $paymentStr,
            "paypage_lang"=> "en",
            "customer_details"=> [
                "name"=> $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                "email"=> $request->input('billingAddress.email'),
                "phone"=> $request->input('billingAddress.mobile'),
                "street1"=> $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                "city"=> $request->input('billingAddress.emirates'),
                "state"=> $request->input('billingAddress.emirates'),
                "country"=> "AE",
                // "zip"=> "12345"
            ],
            "shipping_details"=> [
                "name"=> $shippingData['name'],
                "email"=> $shippingData['email'],
                "phone"=> $shippingData['phone'],
                "street1"=> $shippingData['street1'],
                "city"=> $shippingData['city'],
                "state"=> $shippingData['state'],
                "country"=> "AE",
                // "zip"=> "54321"
            ],
            // "callback"=> "https://phpstack-667016-4904984.cloudwaysapps.com/public/api/payTabsPaymentRedirect",
            // "return"=> "https://phpstack-667016-4904984.cloudwaysapps.com/public/api/payTabsPaymentRedirect"
            // "callback"=> "https://d2dd-217-165-51-241.ngrok-free.app/api/payTabsPaymentCallback",
            "return"=> "http://localhost/ahmed-admin/public/api/payTabsPaymentRedirect?order_number=".base64_encode($order->code),
            // "card_discounts" => $card_discounts
        ];

        $PROFILE_ID = 48012;
        // $PROFILE_ID = 48353;
        $SERVER_KEY = 'SBJNLMDM92-HZKWN6WW6D-NTDHZ9RBMJ';
        // $SERVER_KEY = 'S6JNLMDMDL-HZM2DZDHLN-GW2NZ6DKK2';

        $BASE_URL = 'https://secure.paytabs.com/payment/request';

        $data['profile_id'] = $PROFILE_ID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $BASE_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data, true),
            CURLOPT_HTTPHEADER => array(
                'authorization:' . $SERVER_KEY,
                'Content-Type:application/json'
            ),
        ));

        $response = json_decode(curl_exec($curl), true);
        curl_close($curl);
        // print_r($response);
        return $response;
    }

    public function payTabsPaymentRedirect(Request $request) {
        // echo "<pre>";print_r($request->all());die;
        // $customer = Customer::where('email', $request->input('customerEmail'))->first();
        // $order = Order::where('user_id', $customer->id)->orderBy('id', 'desc')->first();
        $order = Order::where('code', base64_decode($request->query('order_number')))->orderBy('id', 'desc')->first();
        // echo "<pre>";print_r($order);
        $createPaymentForOrderService->execute(
            $order,
            'paytabs',
            $request['respStatus'],
            $order->user_id,
            $request->input('tranRef'),
            $request['respMessage'],
        );

        // $paymentStatus = $request['respStatus'] == 'A' ? 'completed' : 'failed';

        header('Location: http://localhost:3000/'.$order->lang.'/shop-order-payment-complete?q='.base64_encode($order->code));exit();
    }

    // public function payTabsPaymentCallback(Request $request, CreatePaymentForOrderService $createPaymentForOrderService)
    // {
    //     // echo "<pre>";print_r($request->all());die;
    //     // Validate and log PayTabs response
    //     // \Log::info('PayTabs Callback:', $request->all());
    //     $order = Order::where('code', '#'.$request->input('cart_id'))->orderBy('id', 'desc')->first();
    //     // Verify the transaction using PayTabs API (optional but recommended)
    //     // Process order status update, etc.

    //     $createPaymentForOrderService->execute(
    //         $order,
    //         'paytabs',
    //         $request['payment_result']['response_status'],
    //         $order->user_id,
    //         $request->input('tran_ref'),
    //         $request['payment_result']['response_message'],
    //         $request->input('tran_total'),
    //     );

    //     return response()->json(['status' => 'received']);
    // }

    public function trackOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_number'      => 'required',
            'billing_email'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order = Order::select('ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.amount', 'ec_orders.sub_total', 'ec_orders.shipping_amount', 'payments.payment_channel', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'ec_orders.tax_amount', 'payments.status AS payment_status', 'ec_orders.cod_charge')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id')->join('payments', 'payments.order_id', 'ec_orders.id')->where('ec_orders.code', $request->input('order_number'))->where('ec_order_addresses.email', $request->input('billing_email'))->first();

        if(!$order) {
            return response()->json(['message' => 'Order not found']);
        }

        $prod = OrderProduct::where('ec_order_product.order_id', $order->id)->get();

        return response()->json([
            'message'          => 'Tracking Details Fetched successfully',
            'order_id'         => $order->code,
            'payment_method'   => $order->payment_channel,
            'total'            => $order->amount,
            'sub_total'        => $order->sub_total,
            'shipping_amount'  => $order->shipping_amount,
            'status'           => $order->status,
            'created_at'       => $order->created_at,
            'service_amount'   => $order->service_amount,
            'vat_amount'       => $order->vat,
            'tax_amount'       => $order->tax_amount,
            'payment_status'   => $order->payment_status,
            'products'         => $prod,
            'cod_charge'   => $order->cod_charge,
        ]);
    }

    public function orderDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_number'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order = Order::select('ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.amount', 'ec_orders.sub_total', 'ec_orders.shipping_amount', 'payments.payment_channel', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'ec_orders.tax_amount', 'payments.status AS payment_status', 'ec_orders.cod_charge','ec_order_addresses.name')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id', 'left')->join('payments', 'payments.order_id', 'ec_orders.id', 'left')->where('ec_orders.code', $request->input('order_number'))->first();

        if(!$order) {
            return response()->json(['message' => 'Order not found']);
        }

        $prod = OrderProduct::where('ec_order_product.order_id', $order->id)->get();

        return response()->json([
            'message'          => 'Details Fetched successfully',
            'order_id'         => $order->code,
            'payment_method'   => $order->payment_channel,
            'total'            => $order->amount,
            'sub_total'        => $order->sub_total,
            'shipping_amount'  => $order->shipping_amount,
            'status'           => $order->status,
            'created_at'       => $order->created_at,
            'service_amount'   => $order->service_amount,
            'vat_amount'       => $order->vat,
            'tax_amount'       => $order->tax_amount,
            'payment_status'   => $order->payment_status,
            'id'                =>   $order->id,
            'customer_name'=> $order->name,
            'products'         => $prod,
            'cod_charge'   => $order->cod_charge
        ]);
    }

    public function validateCoupon(Request $request) {
         $validator = Validator::make($request->all(), [
            'couponCode'      => 'required',
            'mobile_number' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $coupon = Promotion::select('promotions.id', 'type', 'start_date', 'end_date', 'coupon_code AS code', 'percentage As value', 'apply_to')->where('type', 'coupon')->where('coupon_code', $request->input('couponCode'))->where('start_date', '<=', now())->where('end_date', '>=', now())->join('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id', 'left')->first();

        if(!$coupon) {
            return response()->json(['message' => 'Invalid Coupon Code']);
        }

        $cust_mobile_verification = Customer::where('phone', $request->input('mobile_number'))->first();

        if(!$cust_mobile_verification) {
             $mobile_verification = MobileVerification::where('phone', $request->input('mobile_number'))->first();

            if(!$mobile_verification) {
                return response()->json(['message' => 'Verify Mobile Number First']);
            }
        }

        $customer = OrderAddress::join('payments', 'payments.order_id', '=', 'ec_order_addresses.order_id')->where('status', 'completed')->where('phone', $request->input('mobile_number'))->get();

        // echo "<pre>";print_r($customer);die;

        if(!$customer->isEmpty()) {
            if(strtolower($request->input('couponCode')) == 'welcome10') {
                return response()->json(['message' => 'You Have Already Used this Coupon Code']);
            }
            $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $customer[0]->customer_id)->where('discount_id', $coupon->id)->first();
            if($customer_discount) {
                return response()->json(['message' => 'You Have Already Used this Coupon Code']);
            }
        }

        $coupon->value = intval($coupon->value);

        return response()->json([
            'message'          => 'Details Fetched successfully',
            'coupon'            => $coupon
        ]);
    }

    public function customerDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $customer = Customer::select('id', 'name', 'email', 'phone')->where('id', $request->input('customer_id'))->first();

        if(!$customer) {
            return response()->json(['message' => 'Customer Not Found']);
        }

        return response()->json([
            'message' => 'Details Fetched successfully',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_mobile' => $customer->phone
        ]);
    }

    public function customerUpdate(Request $request)
    {

        if($request->flag == 'fpassword') {
            $validator = Validator::make($request->all(), [
            'customer_id'      => 'required',
            'customer_password' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }

            $customer = Customer::find($request->input('customer_id'));

            if (!$customer) {
                return response()->json(['message' => 'Customer Not Found']);
            }

            $customer->password = Hash::make($request->input('customer_password'));
            $customer->save();

            return response()->json([
                'message' => 'Password Updated Successfully',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_mobile' => $customer->phone
            ]);
        } else {
            $validator = Validator::make($request->all(), [
                'customer_id'      => 'required',
                'customer_name' => 'required',
                'customer_email' => 'required|email|unique:ec_customers,email,' . $request->input('customer_id'),
                'customer_mobile' => 'required|unique:ec_customers,phone,' . $request->input('customer_id'),
                // 'customer_password' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }            

            $customer = Customer::find($request->input('customer_id'));

            if (!$customer) {
                return response()->json(['message' => 'Customer Not Found']);
            }

            $customer->name = $request->input('customer_name');
            $customer->email = $request->input('customer_email');
            $customer->phone = $request->input('customer_mobile');
            if(isset($request->customer_password) && !empty($request->customer_password)) {
                $customer->password = Hash::make($request->input('customer_password'));
            }
            $customer->save();

            $addresses = Address::where('customer_id', $request->input('customer_id'))->get();

            if(!$addresses->isEmpty()) {
                foreach ($addresses as $key => $address) {
                    $address->name = $request->input('customer_name');
                    $address->email = $request->input('customer_email');
                    $address->phone = $request->input('customer_mobile');
                    $address->save();
                }   
            }

            return response()->json([
                'message' => 'Customer Updated Successfully',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_mobile' => $customer->phone
            ]);
        }
    }

    public function customerAddressDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $address = Address::where('customer_id', $request->input('customer_id'))->get();

        if($address->isEmpty()) {
            return response()->json(['message' => 'Customer Address Not Found']);
        }

        if ($address->count() == 1) {
            $original = $address->first()->replicate(); // clone the model
            $original->id = -1; // change ID
            $address->push($original); // add to collection
        }

        return response()->json([
            'message' => 'Details Fetched Successfully',
            'addresses' => $address
        ]);
    }

    public function customerAddressUpdate(Request $request)
    {
        if($request->input('address_id') == -1) {
            $validator = Validator::make($request->all(), [
                'address_id'      => 'required',
                'state' => 'required',
                'city' => 'required',
                'address' => 'required',
                'customer_id' => 'required',
                'name' => 'required',
                'email' => 'required|email',
                'mobile' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }
            
            $address = Address::create([
                'name'      => $request->input('name'),
                'email'     => $request->input('email'),
                'phone'     => $request->input('mobile'),
                'state' => $request->input('state'),
                'city' => $request->input('city'),
                'address' => $request->input('address'),
                'customer_id' => $request->input('customer_id'),
                'is_default' => $request->input('is_default')
            ]);

            return response()->json([
                'message' => 'Customer Address Updated Successfully',
                'addresses' => $address
            ]);
        }

        $validator = Validator::make($request->all(), [
            'address_id'      => 'required',
            'state' => 'required',
            'city' => 'required',
            'address' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $address = Address::find($request->input('address_id'));

        if (!$address) {
            return response()->json(['message' => 'Customer Address Not Found']);
        }

        $address->state = $request->input('state');
        $address->city = $request->input('city');
        $address->address = $request->input('address');
        $address->is_default = $request->input('is_default');
        $address->save();

        return response()->json([
            'message' => 'Customer Address Updated Successfully',
            'addresses' => $address
        ]);
    }

    public function customerOrders(Request $request)
    {
        // Customer/user ID (required for total filtering)
        $customerId = $request->input('customer_id');

        if (!$customerId) {
            return response()->json(['message' => 'Customer Id is Required']);
        }

        // Main columns
        $columns = [
            'ec_orders.id',
            'ec_orders.code',
            'ec_orders.created_at',
            'ec_orders.status',
            // 'ec_orders.amount',
            // 'ec_orders.tax_amount',
            'payments.payment_channel'
        ];

        // Total: All records for the given customer
        $total = Order::where('ec_orders.user_id', $customerId)->count();

        // Filtered Query
        $filteredQuery = Order::select('ec_orders.id')
            ->leftJoin('payments', 'ec_orders.payment_id', '=', 'payments.id')
            ->where('ec_orders.user_id', $customerId);

        $dataQuery = Order::select(
                'ec_orders.id',
                'ec_orders.code',
                'ec_orders.created_at',
                'ec_orders.status',
                // 'ec_orders.amount',
                // 'ec_orders.tax_amount',
                'payments.payment_channel'
            )
            ->leftJoin('payments', 'ec_orders.payment_id', '=', 'payments.id')
            ->where('ec_orders.user_id', $customerId);

        // Search filters
        if ($request->filled('code')) {
            $filteredQuery->where('ec_orders.code', 'like', '%' . $request->code . '%');
            $dataQuery->where('ec_orders.code', 'like', '%' . $request->code . '%');
        }

        if ($request->filled('status')) {
            $filteredQuery->where('ec_orders.status', 'like', '%' . $request->status . '%');
            $dataQuery->where('ec_orders.status', 'like', '%' . $request->status . '%');
        }

        if ($request->filled('created_at')) {
            $filteredQuery->whereDate('ec_orders.created_at', $request->created_at);
            $dataQuery->whereDate('ec_orders.created_at', $request->created_at);
        }

        if ($request->filled('payment_channel')) {
            $filteredQuery->where('payments.payment_channel', 'like', '%' . $request->payment_channel . '%');
            $dataQuery->where('payments.payment_channel', 'like', '%' . $request->payment_channel . '%');
        }

        // Sorting
        $orderBy = $request->input('orderBy', 'ec_orders.id');
        $orderDir = $request->input('orderDir', 'desc');
        if (in_array($orderBy, $columns)) {
            $dataQuery->orderBy($orderBy, $orderDir);
        }

        // Pagination
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $filteredTotal = $filteredQuery->distinct('ec_orders.id')->count('ec_orders.id');

        $orders = $dataQuery
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        // Add link column
        $orders->transform(function ($order) {
            $order->link = '/order-tracking';
            return $order;
        });

        return response()->json([
            'data' => $orders,
            'total' => $total,
            'filtered' => $filteredTotal
        ]);
    }

    public function customerOrderDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order_products = OrderProduct::select('id', 'product_name', 'product_image', 'price', 'qty', 'total_amount', 'discount_percent', 'discount_amount', 'net_amount', 'tax_amount', 'gross_amount', 'is_gift')->where('order_id', $request->input('order_id'))->get();

        if($order_products->isEmpty()) {
            return response()->json(['message' => 'Order Products Not Found']);
        }

        $order_address = OrderAddress::select('id', 'name', 'phone', 'email', 'state', 'city', 'address')->where('order_id', $request->input('order_id'))->get();

        return response()->json([
            'message' => 'Details Fetched successfully',
            'order_products' => $order_products,
            'order_address' => $order_address,
        ]);
    }

    public function customerCouponDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // General coupons
        $generalCoupons = collect(DiscountModel::select('code', 'value', 'start_date', 'end_date')
        ->where('target', '!=', 'customer')
        ->whereNotNull('code')
        ->whereDate('start_date', '<=', now())
        ->whereDate('end_date', '>=', now())
        ->get()
        ->map(function ($coupon) {
            return [
                'code' => $coupon->code,
                'value' => $coupon->value,
                'start_date' => Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                'end_date' => Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
            ];
        }));

        // Customer-specific coupons
        $customerCoupons = collect();
        if ($request->input('customer_id') != '-1') {
            $customerCoupons = DiscountCustomer::select('code', 'value', 'start_date', 'end_date')
            ->leftJoin('ec_discounts', 'ec_discounts.id', 'ec_discount_customers.discount_id')
            ->where('target', 'customer')
            ->where('customer_id', $request->input('customer_id'))
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get()
            ->map(function ($coupon) {
                return [
                    'code' => $coupon->code,
                    'value' => $coupon->value,
                    'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                    'end_date' => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
                ];
            });
        }

        // Merge and return
        $mergedCoupons = $generalCoupons->merge($customerCoupons);

        return response()->json([
            'message' => 'Details Fetched Successfully',
            'coupons' => $mergedCoupons
        ]);
    }

    public function customerPasswordCheck(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required',
            'customer_password' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }
        
        $customer = Customer::find($request->input('customer_id'));

        if (!$customer) {
            return response()->json(['message' => 'Customer Not Found']);
        }

        $customer_password = Hash::check($request->input('customer_password'), $customer->password);

        if (!Hash::check($request->input('customer_password'), $customer->password)) {
            return response()->json(['message' => 'Incorrect Password']);
        }

        return response()->json([
            'message' => 'Customer Found Successfully',
        ]);
    }

    public function getCoupons(Request $request)
    {
        $coupons = DiscountModel::select('code', 'value', 'start_date', 'end_date')->where('target', '!=', 'customer')
        // ->where('customer_id', $customer->id)
        ->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discount_customers', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();

        // Manually transform into an array with formatted strings
        $formattedCoupons = $coupons->map(function ($coupon) {
            return [
                'code'       => $coupon->code,
                'value'      => $coupon->value,
                'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                'end_date'   => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
                // 'type'       => 'customer',
            ];
        })->toArray();

        // $customer->coupon = $formattedCoupons;
        $response = response()->json(['coupons' => $formattedCoupons])->header('Cache-Control', 'public, max-age=86400, s-maxage=172800')->setEtag(md5(json_encode(['coupons' => $formattedCoupons])));  // Cache 1 Day in the browser, 2 Days at Cloudflare

        if ($response->isNotModified(request())) {
            return $response;
        }

        return $response;
    }
}

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
use App\Models\CouponRule;
use App\Models\CashbackProduct;
use App\Models\Cart;
use App\Models\CartProduct;
use Botble\Payment\Models\Payment;
use App\Models\PaymentCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class OrderController extends Controller
{
    public function storeOrder(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        // die;
        $validator = Validator::make($request->all(), [
            'products'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $barcodes = [];

        foreach ($request->input('products') as $product) {
            $exisProduct = Product::where('id', $product['product_id'])->first();

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
            //  if(isset($resp->data) && $resp->data < $product['quantity']) {
            //     return response()->json([
            //         'qtyMessage'          => $product['product_name'].' is Out Of Stock.'
            //     ]);
            // }

            // if(isset($product['discount']) && !is_null($product['discount'])) {
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
            // if ($requestHasDiscount && $dbHasDiscount) {
            //     $value = null;

            //     if (isset($discountFromDb->discountRules[0])) {
            //         $discountRule = $discountFromDb->discountRules[0];

            //         if (isset($discountRule->individualRules[0])) {
            //             // Individual discount value
            //             $value = $discountRule->individualRules[0]->value;
            //         } else {
            //             // Group or all-products discount value (percentage)
            //             $value = $discountRule->percentage;
            //         }
            //     }
            //     $match =
            //         $product['discount']['value'] ==  $value &&
            //         $product['discount']['start_date'] == $discountFromDb->start_date &&
            //         $product['discount']['end_date'] == $discountFromDb->end_date;

            //     if (!$match) {
            //         return response()->json([
            //             'discountMessage' => 'One or more Products were removed. Please add them again to continue. Value '.$product['product_name']
            //         ]);
            //     }
            // }

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
        // die('000');
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

        $cashback = Promotion::select('promotions.name', 'cashback_rules.id', 'cashback_percentage', 'cashback_amount', 'duration')->where('type', 'cashback')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('cashback_rules', 'promotions.id', '=', 'cashback_rules.promotion_id')->first();
        if($cashback) {
            $coupon_code = !is_null($cashback->cashback_percentage) ? 'CASHBACK'.intval($cashback->cashback_percentage) : 'CASHBACK'.intval($cashback->cashback_amount);
            $coupon_type = !is_null($cashback->cashback_percentage) ? 'percent' : 'amount';
            $cashback_product_ids = CashbackProduct::select('product_id')->where('cashback_rule_id', $cashback->id)->pluck('product_id')->toArray();
            // echo "<pre>";print_r($cashback_products);
        } else {
            $cashback_product_ids = [];
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
                'duplicateOrderMessage' => 'You order has been placed already. Order Id: ' . $existingOrder->code
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
            'description' => $request->input('note'),
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

                // $customerCouponData = [];

                // if ($coupons->isEmpty()) {
                // $customer_coupons = DiscountCustomer::select('code', 'value', 'start_date', 'end_date')->where('customer_id', $customer_id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();
                // foreach ($customer_coupons as $customer_coupon) {
                //     $customerCouponData[strtolower($customer_coupon->code)] = [
                //         'code' => strtolower($customer_coupon->code),
                //         'value' => $customer_coupon->value,
                //         'start_date' => $customer_coupon->start_date,
                //         'end_date' => $customer_coupon->end_date,
                //     ];
                // }
                // $exisProduct->customer_coupon = $customerCouponData;

                $customerCoupons = Promotion::select('coupon_code AS code', 'percentage', 'amount', 'start_date', 'end_date', 'apply_to AS target', 'coupon_type')
                    ->leftJoin('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id')
                    ->leftJoin('coupon_customers', 'coupon_rules.id', 'coupon_customers.coupon_rule_id')
                    ->where('type', 'coupon')
                    ->where('apply_to', 'customer')
                    ->where('customer_id', $customer_id)
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->get()
                    ->mapWithKeys(function ($coupon) {
                        return [
                            strtolower($coupon->code) => [
                                'code' => strtolower($coupon->code),
                                'value' => !is_null($coupon->percentage) && $coupon->coupon_type == 'percent' ? intval($coupon->percentage) : intval($coupon->amount),
                                'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                                'end_date' => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
                                'type' => $coupon->target,
                                'coupon_type' => $coupon->coupon_type
                            ],
                        ];
                    })
                    ->toArray();

                $exisProduct->customer_coupon = empty($customerCoupons) ? [] : $customerCoupons;
                // }

                $exisProduct->qty = $quantity;

                // echo $exisProduct->name;
                // echo "<br>";
                // print_r($exisProduct->customer_coupon);
                // echo '---';

                if((isset($product['is_gift']) && $product['is_gift'] == true)) {
                    $exisProduct->is_gift = 1;
                }

                if((isset($product['is_customer_coupon']) && $product['is_customer_coupon'] == true)) {
                    $exisProduct->is_customer_coupon = 1;
                }

                array_push($prod, $exisProduct);

                // $discount_price = '';
                // $sale_price = '';
                if(!is_null($exisProduct->discount)) {
                    if($exisProduct->discount->discount_type == 'percent') {
                        // echo "Discount Percent";
                        // echo "\n";
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
                        // echo "Discount Amount";
                        // echo "\n";
                        $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $sale_price = $exisProduct->discount->final_price / (1 + ($request->input('vatTax') / 100));
                        $discount_percent = 0;
                        $discount_amount = $total_amount - ($sale_price * $quantity);
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;
                        // echo "Price ".$price;
                        // echo "\n";
                        // echo "Total Amount ".$total_amount;
                        // echo "\n";
                        // echo "Sales Price ".$sale_price;
                        // echo "\n";
                        // echo "Discount Percent ".$discount_percent;
                        // echo "\n";
                        // echo "Discount Amount ".$discount_amount;
                        // echo "\n";
                        // echo "Net Amount ".$net_amount;
                        // echo "\n";
                        // echo "Tax Amount ".$tax_amount;
                        // echo "\n";
                        // echo "Gross Amount ".$gross_amount;
                        // echo "\n";
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
                    // echo 'Coupon';
                    // echo '\n ';
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
                } elseif(isset($product['is_customer_coupon']) && !isset($product['is_gift']) && is_null($exisProduct->sale_price) && !is_null($exisProduct->customer_coupon) && !empty($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]) && $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                    if($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['coupon_type'] == 'percent') {
                        // echo 'Customer Coupon Percent';
                        // echo '\n ';
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
                            'campaign' => $request->input('couponCode'),
                        ];
                    } elseif($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['coupon_type'] == 'amount') {
                        // echo 'Customer Coupon Amount';
                        // echo '\n ';
                        $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $sale_price = $price - ($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['value'] / (1 + ($request->input('vatTax') / 100)));
                        $discount_percent = 0;
                        $discount_amount = $total_amount - ($sale_price * $quantity);
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;

                        // echo "Price ".$price;
                        // echo "\n";
                        // echo "Total Amount ".$total_amount;
                        // echo "\n";
                        // echo "Sales Price ".$sale_price;
                        // echo "\n";
                        // echo "Discount Percent ".$discount_percent;
                        // echo "\n";
                        // echo "Discount Amount ".$discount_amount;
                        // echo "\n";
                        // echo "Net Amount ".$net_amount;
                        // echo "\n";
                        // echo "Tax Amount ".$tax_amount;
                        // echo "\n";
                        // echo "Gross Amount ".$gross_amount;
                        // echo "\n";
                        // echo $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['value'];
                        
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
                            'campaign' => $request->input('couponCode'),
                        ];
                    }
                }
                // elseif(!is_null($exisProduct->sale_price)) {
                //     // echo 'Sale Price';
                //     // echo '\n ';
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
                    // echo 'FOC';
                    // echo '\n ';
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
                    // echo 'Else';
                    // echo '\n ';
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

                // echo $response;

                if($cashback) {
                    $customer_cash_back_coupon = DB::table('coupon_customers')->where('customer_id', $customer_id)->where('cashback_rule_id', $cashback->id)->first();

                    if (in_array($product['product_id'], $cashback_product_ids) && !$customer_cash_back_coupon) {
                        $start_date = now();
                        $exist_coupon_rule = Promotion::select('coupon_rules.id')->where('coupon_code', $coupon_code)->where('type', 'coupon')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id')->first();

                        if (!$exist_coupon_rule) {
                            $promotion = Promotion::create([
                                'name'      => $coupon_code,
                                'type'     => 'coupon',
                                'start_date'     => $start_date,
                                'end_date' => Carbon::parse($start_date)->addDays($cashback->duration),
                            ]);
                            if($promotion) {
                                $coupon_rule = CouponRule::create([
                                    'promotion_id'      => $promotion->id,
                                    'coupon_code'     => $coupon_code,
                                    'apply_to' => 'customer',
                                    'coupon_type' => $coupon_type,
                                    'percentage' => $cashback->cashback_percentage,
                                    'amount' => $cashback->cashback_amount,
                                ]);
                                if($coupon_rule) {
                                    DB::table('coupon_customers')->insert([
                                        'coupon_rule_id' => $coupon_rule->id,
                                        'cashback_rule_id' => $cashback->id,
                                        'customer_id' => $customer_id,
                                        'created_at' => now()
                                    ]);
                                }
                            }
                        } else {
                            DB::table('coupon_customers')->insert([
                                'coupon_rule_id' => $exist_coupon_rule->id,
                                'cashback_rule_id' => $cashback->id,
                                'customer_id' => $customer_id,
                                'created_at' => now()
                            ]);
                        }
                        
                        // if($promotion) {
                        //     $coupon_rule = CouponRule::create([
                        //         'promotion_id'      => $promotion->id,
                        //         'coupon_code'     => $coupon_code,
                        //         'apply_to' => 'customer',
                        //         'coupon_type' => $coupon_type,
                        //         'percentage' => $cashback->cashback_percentage,
                        //         'amount' => $cashback->cashback_amount,
                        //     ]);

                        //     if($coupon_rule) {
                        //         DB::table('coupon_customers')->insert([
                        //             'coupon_rule_id' => $coupon_rule->id,
                        //             'cashback_rule_id' => $cashback->id,
                        //             'customer_id' => $customer_id,
                        //             'created_at' => now()
                        //         ]);
                        //     }
                        // }
                    }
                }
            }
            // die(';;;');

            // $url = "https://c21341-ifservice.cloudiax.com/api/ECommerce/StockStatus?itemCode=".implode(',', $barcodes);

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

                // $customerCouponData = [];

                // if ($coupons->isEmpty()) {
                // $customer_coupons = DiscountCustomer::select('code', 'value', 'start_date', 'end_date')->where('customer_id', $customer_id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();
                // foreach ($customer_coupons as $customer_coupon) {
                //     $customerCouponData[strtolower($customer_coupon->code)] = [
                //         'code' => strtolower($customer_coupon->code),
                //         'value' => $customer_coupon->value,
                //         'start_date' => $customer_coupon->start_date,
                //         'end_date' => $customer_coupon->end_date,
                //     ];
                // }
                // $exisProduct->customer_coupon = $customerCouponData;

                $customerCoupons = Promotion::select('coupon_code AS code', 'percentage', 'amount', 'start_date', 'end_date', 'apply_to AS target', 'coupon_type')
                    ->leftJoin('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id')
                    ->leftJoin('coupon_customers', 'coupon_rules.id', 'coupon_customers.coupon_rule_id')
                    ->where('type', 'coupon')
                    ->where('apply_to', 'customer')
                    ->where('customer_id', $customer_id)
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->get()
                    ->mapWithKeys(function ($coupon) {
                        return [
                            strtolower($coupon->code) => [
                                'code' => strtolower($coupon->code),
                                'value' => !is_null($coupon->percentage) && $coupon->coupon_type == 'percent' ? intval($coupon->percentage) : intval($coupon->amount),
                                'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                                'end_date' => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
                                'type' => $coupon->target,
                                'coupon_type' => $coupon->coupon_type
                            ],
                        ];
                    })
                    ->toArray();

                $exisProduct->customer_coupon = empty($customerCoupons) ? [] : $customerCoupons;
                // }

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
                            // 'description' => $exisProduct->description,
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
                        $sale_price = $exisProduct->discount->final_price / (1 + ($request->input('vatTax') / 100));
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
                            // 'description' => $exisProduct->description,
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
                        // 'description' => $exisProduct->description,
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
                } elseif(isset($product['is_customer_coupon']) && !isset($product['is_gift']) && is_null($exisProduct->sale_price) && !is_null($exisProduct->customer_coupon) && !empty($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]) && $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                        if($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['coupon_type'] == 'percent') {
                        // echo 'Customer Coupon Percent';
                        // echo '\n ';
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
                            // 'description' => $exisProduct->description,
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
                    } elseif($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['coupon_type'] == 'amount') {
                         // echo 'Customer Coupon Amount';
                        // echo '\n ';
                        $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $sale_price = $price - ($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['value'] / (1 + ($request->input('vatTax') / 100)));
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
                            // 'description' => $exisProduct->description,
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
                    $discount_amount = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    $net_amount = 0.00;
                    $tax_amount = 0.00;
                    $gross_amount = 0.00;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'invoice_id' => $invoice->id,
                        'reference_type' => 'Botble\Ecommerce\Models\Product',
                        'reference_id' => $exisProduct->id,
                        'name' => $exisProduct->name,
                        // 'description' => $exisProduct->description,
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
                        // 'description' => $exisProduct->description,
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
                'customer_name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                'payment_method'   => $request->input('payment_method'),
                'total'            => $order->amount,
                'sub_total'        => $order->sub_total,
                'shipping_amount'  => $order->shipping_amount,
                'products'         => $prod
            ]);
        }
    }

    // public function payTabsPayment(Request $request, $shippingData, $order) {
    //     $paymentStr = '';
    //     foreach ($request->input('products') as $product) {
    //         $quantity = $product['quantity'] ? $product['quantity'] : 1;
    //         $exisProduct = Product::select('name')->where('ec_products.id', $product['product_id'])->first();
    //         $paymentStr .= $exisProduct->name. ' ('.$quantity.'), ';
    //     }

    //     $data = [
    //         "tran_type"=> "sale",
    //         "tran_class"=> "ecom",
    //         "cart_id"=> explode('#', $order->code)[1],
    //         "cart_currency"=> "AED",
    //         "cart_amount"=> $request->input('finalPrice'),
    //         "cart_description"=> $paymentStr,
    //         "paypage_lang"=> "en",
    //         "customer_details"=> [
    //             "name"=> $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
    //             "email"=> $request->input('billingAddress.email'),
    //             "phone"=> $request->input('billingAddress.mobile'),
    //             "street1"=> $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
    //             "city"=> $request->input('billingAddress.emirates'),
    //             "state"=> $request->input('billingAddress.emirates'),
    //             "country"=> "AE",
    //             // "zip"=> "12345"
    //         ],
    //         "shipping_details"=> [
    //             "name"=> $shippingData['name'],
    //             "email"=> $shippingData['email'],
    //             "phone"=> $shippingData['phone'],
    //             "street1"=> $shippingData['street1'],
    //             "city"=> $shippingData['city'],
    //             "state"=> $shippingData['state'],
    //             "country"=> "AE",
    //             // "zip"=> "54321"
    //         ],
    //         // "callback"=> "https://admin.ahmedalmaghribi.com/public/api/payTabsPaymentRedirect?order_number=".base64_encode($order->code),
    //         "return"=> "http://localhost/ahmed-admin/public/api/payTabsPaymentRedirect?order_number=".base64_encode($order->code)
    //     ];

    //     $PROFILE_ID = config('paytabs.profile_id');
    //     $SERVER_KEY = config('paytabs.server_key');

    //     $BASE_URL = config('paytabs.base_url');

    //     $data['profile_id'] = $PROFILE_ID;
    //     $curl = curl_init();
    //     curl_setopt_array($curl, array(
    //         CURLOPT_URL => $BASE_URL,
    //         CURLOPT_RETURNTRANSFER => true,
    //         CURLOPT_ENCODING => '',
    //         CURLOPT_MAXREDIRS => 10,
    //         CURLOPT_TIMEOUT => 0,
    //         CURLOPT_CUSTOMREQUEST => 'POST',
    //         CURLOPT_POSTFIELDS => json_encode($data, true),
    //         CURLOPT_HTTPHEADER => array(
    //             'authorization:' . $SERVER_KEY,
    //             'Content-Type:application/json'
    //         ),
    //         // CURLOPT_SSL_VERIFYPEER => false,  // 👈 Add this
    //         // CURLOPT_SSL_VERIFYHOST => false,  // 👈 And this
    //         CURLOPT_SSL_VERIFYPEER => true,
    //         CURLOPT_CAINFO => base_path('certs/cacert.pem'),
    //     ));

    //     $response = json_decode(curl_exec($curl), true);
    //     curl_close($curl);
    //     // print_r($response);die;
    //     return $response;

    //     // $responseRaw = curl_exec($curl);
    //     // curl_close($curl);

    //     // echo "Raw response:\n";
    //     // var_dump($responseRaw); // Check if there is anything returned at all
    //     // $response = json_decode($responseRaw, true);
    //     // print_r($response); // Still might be null if response is not valid JSON
    //     // die;

    //     // $responseRaw = curl_exec($curl);

    //     // if (curl_errno($curl)) {
    //     //     echo 'Curl error: ' . curl_error($curl) . "\n";
    //     // }

    //     // $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    //     // echo "HTTP Status Code: $httpCode\n";

    //     // curl_close($curl);

    //     // die;
    // }

    // public function payTabsPaymentRedirect(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
    //     // echo "<pre>";print_r($request->all());die;
    //     // $customer = Customer::where('email', $request->input('customerEmail'))->first();
    //     // $order = Order::where('user_id', $customer->id)->orderBy('id', 'desc')->first();
    //     $order = Order::where('code', base64_decode($request->query('order_number')))->orderBy('id', 'desc')->first();
    //     // echo "<pre>";print_r($order);
    //     $createPaymentForOrderService->execute(
    //         $order,
    //         'paytabs',
    //         $request['respStatus'],
    //         // $customer->id,
    //         $order->user_id,
    //         $request->input('tranRef'),
    //         $request['respMessage'],
    //     );

    //     header('Location: http://localhost:3000/'.$order->lang.'/shop-order-payment-complete?q='.base64_encode($order->code));exit();
    // }

    public function payTabsPayment(Request $request, $shippingData, \App\Models\PaymentCart $paymentCart) {
        $paymentStr = '';
        foreach ($request->input('products') as $product) {
            $quantity = $product['quantity'] ? $product['quantity'] : 1;
            $exisProduct = Product::select('name')->where('ec_products.id', $product['product_id'])->first();
            $paymentStr .= $exisProduct->name. ' ('.$quantity.'), ';
        }

        $data = [
            "tran_type"=> "sale",
            "tran_class"=> "ecom",
            "cart_id"           => $paymentCart->id,
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
            // // The URL for the secure server-to-server confirmation
           // The BACKEND URL. PayTabs sends a secure POST request here with ALL data.
            // "callback"  => url('/api/finalize-payment'), 
    
            // The FRONTEND URL. The user's browser goes here.
            // "return"    => env('FRONTEND_URL', 'http://localhost:3000') . '/order/processing?cartId=' . $paymentCart->id
            // Set the return URL to point to the API with the cart_id
            // "return"    => url('/api/finalize-payment')
            "return"=> "https://howard-nonvisualized-unimpartially.ngrok-free.dev/ahmed-admin/public/api/finalize-payment?cartId=" . $paymentCart->id
        ];

        $PROFILE_ID = config('paytabs.profile_id');
        $SERVER_KEY = config('paytabs.server_key');

        $BASE_URL = config('paytabs.base_url');

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
            // CURLOPT_SSL_VERIFYPEER => false,  // 👈 Add this
            // CURLOPT_SSL_VERIFYHOST => false,  // 👈 And this
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => base_path('certs/cacert.pem'),
        ));

        $response = json_decode(curl_exec($curl), true);
        curl_close($curl);
        // print_r($response);die;
        return $response;

        // $responseRaw = curl_exec($curl);
        // curl_close($curl);

        // echo "Raw response:\n";
        // var_dump($responseRaw); // Check if there is anything returned at all
        // $response = json_decode($responseRaw, true);
        // print_r($response); // Still might be null if response is not valid JSON
        // die;

        // $responseRaw = curl_exec($curl);

        // if (curl_errno($curl)) {
        //     echo 'Curl error: ' . curl_error($curl) . "\n";
        // }

        // $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        // echo "HTTP Status Code: $httpCode\n";

        // curl_close($curl);

        // die;
    }

    public function payTabsPaymentRedirect(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        // echo "<pre>";print_r($request->all());die;
        // $customer = Customer::where('email', $request->input('customerEmail'))->first();
        // $order = Order::where('user_id', $customer->id)->orderBy('id', 'desc')->first();
        $order = Order::where('code', base64_decode($request->query('order_number')))->orderBy('id', 'desc')->first();
        // echo "<pre>";print_r($order);
        $createPaymentForOrderService->execute(
            $order,
            'paytabs',
            $request['respStatus'],
            // $customer->id,
            $order->user_id,
            $request->input('tranRef'),
            $request['respMessage'],
        );

        header('Location: http://localhost:3000/'.$order->lang.'/shop-order-payment-complete?q='.base64_encode($order->code));exit();
    }

    public function trackOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_number'      => 'required',
            'billing_email'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order = Order::select('ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.deliveryStatus', 'ec_orders.amount', 'ec_orders.sub_total', 'ec_orders.shipping_amount', 'payments.payment_channel', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'ec_orders.tax_amount', 'payments.status AS payment_status', 'ec_orders.cod_charge')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id')->join('payments', 'payments.order_id', 'ec_orders.id')->where('ec_orders.code', $request->input('order_number'))->where('ec_order_addresses.email', $request->input('billing_email'))->first();

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
            'delivery_status'  => $order->deliveryStatus,
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

        $order = Order::select('ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.amount', 'ec_orders.sub_total', 'ec_orders.shipping_amount', 'payments.payment_channel', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'ec_orders.tax_amount', 'payments.status AS payment_status', 'ec_orders.cod_charge', 'ec_order_addresses.name')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id', 'left')->join('payments', 'payments.order_id', 'ec_orders.id', 'left')->where('ec_orders.code', $request->input('order_number'))->first();

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

        $coupon = Promotion::select('promotions.id', 'type', 'start_date', 'end_date', 'coupon_code AS code', 'percentage', 'amount', 'apply_to', 'apply_to AS type', 'coupon_type')->where('type', 'coupon')->where('coupon_code', $request->input('couponCode'))->where('start_date', '<=', now())->where('end_date', '>=', now())->join('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id', 'left')->first();

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

        $customer = OrderAddress::join('payments', 'payments.order_id', '=', 'ec_order_addresses.order_id')->where('status', 'completed')->where('phone', $request->input('mobile_number'))->orderBy('ec_order_addresses.order_id', 'desc')->first();

        // $customer = OrderAddress::select('order_id')->where('phone', $request->input('mobile_number'))->orderBy('order_id', 'desc')->first();

        // $payment = Payment::where('status', 'completed')->where('customer_id', $customer->order_id)->get();

        // echo "<pre>";print_r($customer);die;

        if($customer) {
            if(strtolower($request->input('couponCode')) == 'welcome10') {
                return response()->json(['message' => 'You Have Already Used this Coupon Code']);
            }
            $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $customer->customer_id)->where('discount_id', $coupon->id)->first();
            if($customer_discount) {
                return response()->json(['message' => 'You Have Already Used this Coupon Code']);
            }
        }

        $coupon->value = !is_null($coupon->percentage) && $coupon->coupon_type == 'percent' ? intval($coupon->percentage) : intval($coupon->amount);

        // $coupon->start_date->format('Y-m-d H:i:s');
        // $coupon->end_date->format('Y-m-d H:i:s');

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
            'ec_orders.amount',
            'ec_orders.tax_amount',
            'ec_orders.sub_total',
            'ec_orders.coupon_code',
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
                'ec_orders.amount',
                'ec_orders.tax_amount',
                'ec_orders.sub_total',
                'ec_orders.coupon_code',
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
        $generalCoupons = collect(Promotion::where('type', 'coupon')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            // ->with([
            //     'couponRules.products' => function ($query) {
            //         // $query->select('id', 'coupon_rule_id', 'product_id'); // optional: limit fields
            //     },
            // ])
            ->get()
            ->flatMap(function ($promotion) {
                return collect($promotion->couponRules)
                    ->filter(function ($rule) {
                        return $rule->apply_to !== 'customer' &&
                            $rule->coupon_code !== null;
                    })
                    ->map(function ($rule) use ($promotion) {
                        return [
                            'code' => $rule->coupon_code,
                            'value' => !is_null($rule->percentage) &&  $rule->coupon_type == 'percent' ? intval($rule->percentage) : intval($rule->amount),
                            'start_date' => Carbon::parse($promotion->start_date)->format('Y-m-d H:i:s'),
                            'end_date' => Carbon::parse($promotion->end_date)->format('Y-m-d H:i:s'),
                            'type' => $rule->apply_to, // or $promotion->type if needed
                            'coupon_type' => $rule->coupon_type,
                        ];
                    });
            }));

        // Customer-specific coupons
        $customerCoupons = collect();
        $customerId = $request->input('customer_id');

        if ($customerId && $customerId != '-1') {
            $customerCoupons = Promotion::where('type', 'coupon')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->whereHas('couponRules', function ($query) use ($customerId) {
                    $query->where('apply_to', 'customer')
                        ->whereHas('customers', function ($q) use ($customerId) {
                            $q->where('customer_id', $customerId);
                        });
                })
                ->with([
                    'couponRules.customers' => function ($query) use ($customerId) {
                        $query->where('customer_id', $customerId);
                    }
                ])
                ->get()
                ->flatMap(function ($promotion) {
                    return $promotion->couponRules
                        ->filter(function ($rule) {
                            return $rule->apply_to === 'customer' && $rule->coupon_code;
                        })
                        ->map(function ($rule) use ($promotion) {
                            return [
                                'code' => $rule->coupon_code,
                                'value' => !is_null($rule->percentage) &&  $rule->coupon_type == 'percent' ? intval($rule->percentage) : intval($rule->amount),
                                'start_date' => Carbon::parse($promotion->start_date)->format('Y-m-d H:i:s'),
                                'end_date' => Carbon::parse($promotion->end_date)->format('Y-m-d H:i:s'),
                                'type' => $rule->apply_to,
                                'coupon_type' => $rule->coupon_type,
                            ];
                        });
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

    private function _createFinalOrder(Request $orderRequest, int $customer_id, array $paymentDetails, ?string $paymentCartId = null): ?Order {
        DB::beginTransaction();
        try {
            throw new Exception('Testing rollback');
            $paymentSuccessful = in_array($paymentDetails['status'], ['completed', 'A']);

            // Create the main Order record in the database.
            $orderData = [
                'user_id'               => $customer_id,
                'status'                => $paymentSuccessful ? OrderStatusEnum::PROCESSING : OrderStatusEnum::CANCELED,
                'is_confirmed'          => $paymentSuccessful ? 1 : 0,
                'is_finished'           => 1,
                'shipping_method'       => $orderRequest->input('shipping_method') ?: ShippingMethodEnum::DEFAULT,
                'shipping_option'       => $orderRequest->input('shipping_option'),
                'shipping_amount'       => $orderRequest->input('shippingPrice') / (1 + ($orderRequest->input('vatTax') / 100)),
                'shipping_amount_vat'   => $orderRequest->input('shippingPrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100),
                'service_amount'        => $orderRequest->input('servicePrice') / (1 + ($orderRequest->input('vatTax') / 100)),
                'service_amount_vat'    => $orderRequest->input('servicePrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100),
                'vat'                   => $orderRequest->input('vatTax'),
                'tax_amount'            => ($orderRequest->input('totalPrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100)) + ($orderRequest->input('shippingPrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100)) + ($orderRequest->input('servicePrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100)) + ($orderRequest->input('codPrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100)),
                'sub_total'             => $orderRequest->input('totalPrice') ?: 0,
                'amount'                => $orderRequest->input('finalPrice') ?: 0,
                'coupon_code'           => $orderRequest->input('couponCode'),
                'discount_amount'       => $orderRequest->input('discount_amount') ?: 0,
                'promotion_amount'      => $orderRequest->input('promotion_amount') ?: 0,
                'discount_description'  => $orderRequest->input('discount_description'),
                'description'           => $orderRequest->input('note'),
                'lang'                  => $orderRequest->input('locale'),
                'cod_charge'            => $orderRequest->input('codPrice') / (1 + ($orderRequest->input('vatTax') / 100)),
                'cod_charge_vat'        => $orderRequest->input('codPrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100),
            ];
            if ($paymentCartId) {
                $orderData['payment_cart_id'] = $paymentCartId;
            }

            $order = Order::create($orderData);

            if($order) {
                if($orderRequest->input('customer_id')) {
                    $loggedInCustomer = Customer::where('id', $orderRequest->input('customer_id'))->first();
                    $loggedInCustomerAdd = Address::where('customer_id', $loggedInCustomer->id)->first();
                    if(!$loggedInCustomerAdd) {
                        Address::create([
                            'name'      => $loggedInCustomer->name,
                            'email'     => $loggedInCustomer->email,
                            'phone'     => $loggedInCustomer->phone,
                            'state' => $orderRequest->input('billingAddress.emirates'),
                            'city' => $orderRequest->input('billingAddress.emirates'),
                            'country' => $orderRequest->input('billingAddress.country'),
                            'address' => $orderRequest->input('billingAddress.area').' '.$orderRequest->input('billingAddress.building'),
                            'customer_id' => $loggedInCustomer->id,
                        ]);
                        $loggedInCustomerAdd = Address::where('customer_id', $loggedInCustomer->id)->first();
                    }
                    OrderAddress::query()->create([
                        'name' => $orderRequest->input('shippingAddress.first_name') ? $orderRequest->input('shippingAddress.first_name').' '.$orderRequest->input('shippingAddress.last_name') : $loggedInCustomer->name,
                        'phone' => $orderRequest->input('shippingAddress.mobile') ? $orderRequest->input('shippingAddress.mobile') : $loggedInCustomer->phone,
                        'email' => $orderRequest->input('shippingAddress.email') ? $orderRequest->input('shippingAddress.email') : $loggedInCustomer->email,
                        'state' => $orderRequest->input('shippingAddress.emirates') ? $orderRequest->input('shippingAddress.emirates') : $loggedInCustomerAdd->state,
                        'city' => $orderRequest->input('shippingAddress.emirates') ? $orderRequest->input('shippingAddress.emirates') : $loggedInCustomerAdd->city,
                        'country' => $orderRequest->input('shippingAddress.country') ? $orderRequest->input('shippingAddress.country') : $loggedInCustomerAdd->country,
                        'address' => $orderRequest->input('shippingAddress.area') ? $orderRequest->input('shippingAddress.area').' '.$orderRequest->input('shippingAddress.building') : $loggedInCustomerAdd->address,
                        'order_id' => $order->id,
                        'type' => $orderRequest->input('shippingAddress.first_name') ? 'shipping_address' : 'billing_address',
                    ]);

                    if($orderRequest->input('payment_method') == 'paytabs') {            
                        $data = [
                            "name"=> $orderRequest->input('shippingAddress.first_name') ? $orderRequest->input('shippingAddress.first_name').' '.$orderRequest->input('shippingAddress.last_name') : $loggedInCustomer->name,
                            "email"=> $orderRequest->input('shippingAddress.email') ? $orderRequest->input('shippingAddress.email') : $loggedInCustomer->email,
                            "phone"=> $orderRequest->input('shippingAddress.mobile') ? $orderRequest->input('shippingAddress.mobile') : $loggedInCustomer->phone,
                            "street1"=> $orderRequest->input('shippingAddress.area') ? $orderRequest->input('shippingAddress.area').' '.$orderRequest->input('shippingAddress.building') : $loggedInCustomerAdd->address,
                            "city"=> $orderRequest->input('shippingAddress.emirates') ? $orderRequest->input('shippingAddress.emirates') : $loggedInCustomerAdd->city,
                            "state"=> $orderRequest->input('shippingAddress.emirates') ? $orderRequest->input('shippingAddress.emirates') : $loggedInCustomerAdd->state,
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
                        'name' => $orderRequest->input('shippingAddress.first_name') ? $orderRequest->input('shippingAddress.first_name').' '.$orderRequest->input('shippingAddress.last_name') : $orderRequest->input('billingAddress.first_name').' '.$orderRequest->input('billingAddress.last_name'),
                        'phone' => $orderRequest->input('shippingAddress.mobile') ? $orderRequest->input('shippingAddress.mobile') : $orderRequest->input('billingAddress.mobile'),
                        'email' => $orderRequest->input('shippingAddress.email') ? $orderRequest->input('shippingAddress.email') : $orderRequest->input('billingAddress.email'),
                        'state' => $orderRequest->input('shippingAddress.emirates') ? $orderRequest->input('shippingAddress.emirates') : $orderRequest->input('billingAddress.emirates'),
                        'city' => $orderRequest->input('shippingAddress.emirates') ? $orderRequest->input('shippingAddress.emirates') : $orderRequest->input('billingAddress.emirates'),
                        // 'zip_code' => $orderRequest->input('shippingAddress.zip_code'),
                        'country' => $orderRequest->input('shippingAddress.country') ? $orderRequest->input('shippingAddress.country') : $orderRequest->input('billingAddress.country'),
                        'address' => $orderRequest->input('shippingAddress.area') ? $orderRequest->input('shippingAddress.area').' '.$orderRequest->input('shippingAddress.building') : $orderRequest->input('billingAddress.area').' '.$orderRequest->input('billingAddress.building'),
                        'order_id' => $order->id,
                        'type' => $orderRequest->input('shippingAddress.first_name') ? 'shipping_address' : 'billing_address',
                    ]);

                    if($orderRequest->input('payment_method') == 'paytabs') {
                        $data = [
                            "name"=> $orderRequest->input('shippingAddress.first_name') ? $orderRequest->input('shippingAddress.first_name').' '.$orderRequest->input('shippingAddress.last_name') : $orderRequest->input('billingAddress.first_name').' '.$orderRequest->input('billingAddress.last_name'),
                            "email"=> $orderRequest->input('shippingAddress.email') ? $orderRequest->input('shippingAddress.email') : $orderRequest->input('billingAddress.email'),
                            "phone"=> $orderRequest->input('shippingAddress.mobile') ? $orderRequest->input('shippingAddress.mobile') : $orderRequest->input('billingAddress.mobile'),
                            "street1"=> $orderRequest->input('shippingAddress.area') ? $orderRequest->input('shippingAddress.area').' '.$orderRequest->input('shippingAddress.building') : $orderRequest->input('billingAddress.area').' '.$orderRequest->input('billingAddress.building'),
                            "city"=> $orderRequest->input('shippingAddress.emirates') ? $orderRequest->input('shippingAddress.emirates') : $orderRequest->input('billingAddress.emirates'),
                            "state"=> $orderRequest->input('shippingAddress.emirates') ? $orderRequest->input('shippingAddress.emirates') : $orderRequest->input('billingAddress.emirates'),
                            "country"=> "AE",
                            // "zip"=> "54321"
                        ];
                        // $resp = $this->payTabsPayment($orderRequest, $data);
                        // return response()->json([
                        //     'redirect_url'     => $resp['redirect_url']
                        // ]);
                    }
                }
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
                $cashback = Promotion::select('promotions.name', 'cashback_rules.id', 'cashback_percentage', 'cashback_amount', 'duration')->where('type', 'cashback')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('cashback_rules', 'promotions.id', '=', 'cashback_rules.promotion_id')->first();
                if ($cashback) {
                    $coupon_code = !is_null($cashback->cashback_percentage) ? 'CASHBACK' . intval($cashback->cashback_percentage) : 'CASHBACK' . intval($cashback->cashback_amount);
                    $coupon_type = !is_null($cashback->cashback_percentage) ? 'percent' : 'amount';
                    $cashback_product_ids = CashbackProduct::select('product_id')->where('cashback_rule_id', $cashback->id)->pluck('product_id')->toArray();
                } else {
                    $cashback_product_ids = [];
                }

                foreach ($orderRequest->input('products') as $product) {
                    $quantity = $product['quantity'] ? $product['quantity'] : 1;
                    $exisProduct = Product::where('ec_products.id', $product['product_id'])->first();
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
                    
                    $customerCoupons = Promotion::select('coupon_code AS code', 'percentage', 'amount', 'start_date', 'end_date', 'apply_to AS target', 'coupon_type')
                        ->leftJoin('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id')
                        ->leftJoin('coupon_customers', 'coupon_rules.id', 'coupon_customers.coupon_rule_id')
                        ->where('type', 'coupon')
                        ->where('apply_to', 'customer')
                        ->where('customer_id', $customer_id)
                        ->whereDate('start_date', '<=', now())
                        ->whereDate('end_date', '>=', now())
                        ->get()
                        ->mapWithKeys(function ($coupon) {
                            return [
                                strtolower($coupon->code) => [
                                    'code' => strtolower($coupon->code),
                                    'value' => !is_null($coupon->percentage) && $coupon->coupon_type == 'percent' ? intval($coupon->percentage) : intval($coupon->amount),
                                    'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                                    'end_date' => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
                                    'type' => $coupon->target,
                                    'coupon_type' => $coupon->coupon_type
                                ],
                            ];
                        })
                        ->toArray();

                    $exisProduct->customer_coupon = empty($customerCoupons) ? [] : $customerCoupons;
                    $exisProduct->qty = $quantity;

                    if((isset($product['is_gift']) && $product['is_gift'] == true)) {
                        $exisProduct->is_gift = 1;
                    }

                    if((isset($product['is_customer_coupon']) && $product['is_customer_coupon'] == true)) {
                        $exisProduct->is_customer_coupon = 1;
                    }

                    array_push($prod, $exisProduct);

                    if(!is_null($exisProduct->discount)) {
                        if($exisProduct->discount->discount_type == 'percent') {
                            // echo "Discount Percent";
                            // echo "\n";
                            $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                            $total_amount = $price * $quantity;
                            $discount_percent = $exisProduct->discount->value;
                            $discount_amount = ($total_amount / 100) * $discount_percent;
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
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
                                'vat' => $orderRequest->input('vatTax'),
                            ];   
                        } elseif($exisProduct->discount->discount_type == 'amount') {
                            // echo "Discount Amount";
                            // echo "\n";
                            $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                            $total_amount = $price * $quantity;
                            $sale_price = $exisProduct->discount->final_price / (1 + ($orderRequest->input('vatTax') / 100));
                            $discount_percent = 0;
                            $discount_amount = $total_amount - ($sale_price * $quantity);
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
                            $gross_amount = $net_amount + $tax_amount;
                            // echo "Price ".$price;
                            // echo "\n";
                            // echo "Total Amount ".$total_amount;
                            // echo "\n";
                            // echo "Sales Price ".$sale_price;
                            // echo "\n";
                            // echo "Discount Percent ".$discount_percent;
                            // echo "\n";
                            // echo "Discount Amount ".$discount_amount;
                            // echo "\n";
                            // echo "Net Amount ".$net_amount;
                            // echo "\n";
                            // echo "Tax Amount ".$tax_amount;
                            // echo "\n";
                            // echo "Gross Amount ".$gross_amount;
                            // echo "\n";
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
                                'vat' => $orderRequest->input('vatTax'),
                            ];
                        }
                    } elseif(!empty($product['coupon']) && !is_null($exisProduct->coupon) && !empty($exisProduct->coupon) && isset($exisProduct->coupon) && isset($exisProduct->coupon[strtolower($orderRequest->input('couponCode'))]) && $exisProduct->coupon[strtolower($orderRequest->input('couponCode'))]['code'] == strtolower($orderRequest->input('couponCode'))) {
                        // echo 'Coupon';
                        // echo '\n ';
                        $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = $exisProduct->coupon[strtolower($orderRequest->input('couponCode'))]['value'];
                        $discount_amount = ($total_amount / 100) * $discount_percent;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
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
                            'vat' => $orderRequest->input('vatTax'),
                            'campaign' => strtolower($orderRequest->input('couponCode')) == 'welcome10' ? 'first_order_discount_2025' : $orderRequest->input('couponCode'),
                        ];
                    } elseif(isset($product['is_customer_coupon']) && !isset($product['is_gift']) && is_null($exisProduct->sale_price) && !is_null($exisProduct->customer_coupon) && !empty($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]) && $exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['code'] == strtolower($orderRequest->input('couponCode'))) {
                        if($exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['coupon_type'] == 'percent') {
                            // echo 'Customer Coupon Percent';
                            // echo '\n ';
                            $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                            $total_amount = $price * $quantity;
                            $discount_percent = $exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['value'];
                            $discount_amount = ($total_amount / 100) * $discount_percent;
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
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
                                'vat' => $orderRequest->input('vatTax'),
                                'campaign' => $orderRequest->input('couponCode'),
                            ];
                        } elseif($exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['coupon_type'] == 'amount') {
                            // echo 'Customer Coupon Amount';
                            // echo '\n ';
                            $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                            $total_amount = $price * $quantity;
                            $sale_price = $price - ($exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['value'] / (1 + ($orderRequest->input('vatTax') / 100)));
                            $discount_percent = 0;
                            $discount_amount = $total_amount - ($sale_price * $quantity);
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
                            $gross_amount = $net_amount + $tax_amount;

                            // echo "Price ".$price;
                            // echo "\n";
                            // echo "Total Amount ".$total_amount;
                            // echo "\n";
                            // echo "Sales Price ".$sale_price;
                            // echo "\n";
                            // echo "Discount Percent ".$discount_percent;
                            // echo "\n";
                            // echo "Discount Amount ".$discount_amount;
                            // echo "\n";
                            // echo "Net Amount ".$net_amount;
                            // echo "\n";
                            // echo "Tax Amount ".$tax_amount;
                            // echo "\n";
                            // echo "Gross Amount ".$gross_amount;
                            // echo "\n";
                            // echo $exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['value'];
                            
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
                                'vat' => $orderRequest->input('vatTax'),
                                'campaign' => $orderRequest->input('couponCode'),
                            ];
                        }
                    }
                    elseif(isset($product['is_gift']) && $product['is_gift'] == true) {
                        // echo 'FOC';
                        // echo '\n ';
                        $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                        $total_amount = 0.00;
                        $discount_percent = 100;
                        $discount_amount = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
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
                            'vat' => $orderRequest->input('vatTax'),
                            'is_gift' => 1,
                            'campaign' => $product['campaign'],
                        ];
                    }
                    else {
                        // echo 'Else';
                        // echo '\n ';
                        $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = 0;
                        $discount_amount = 0.00;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
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
                            'vat' => $orderRequest->input('vatTax'),
                        ];
                    }

                    OrderProduct::query()->create($orderProduct);

                    if ($paymentSuccessful) {
                        Product::query()
                            ->where('id', $product['product_id'])
                            ->where('with_storehouse_management', 1)
                            ->where('quantity', '>=', $quantity)
                            ->decrement('quantity', $quantity);
                    }

                    if($cashback) {
                        $customer_cash_back_coupon = DB::table('coupon_customers')->where('customer_id', $customer_id)->where('cashback_rule_id', $cashback->id)->first();

                        if (in_array($product['product_id'], $cashback_product_ids) && !$customer_cash_back_coupon) {
                            $start_date = now();
                            $exist_coupon_rule = Promotion::select('coupon_rules.id')->where('coupon_code', $coupon_code)->where('type', 'coupon')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id')->first();

                            if (!$exist_coupon_rule) {
                                $promotion = Promotion::create([
                                    'name'      => $coupon_code,
                                    'type'     => 'coupon',
                                    'start_date'     => $start_date,
                                    'end_date' => Carbon::parse($start_date)->addDays($cashback->duration),
                                ]);
                                if($promotion) {
                                    $coupon_rule = CouponRule::create([
                                        'promotion_id'      => $promotion->id,
                                        'coupon_code'     => $coupon_code,
                                        'apply_to' => 'customer',
                                        'coupon_type' => $coupon_type,
                                        'percentage' => $cashback->cashback_percentage,
                                        'amount' => $cashback->cashback_amount,
                                    ]);
                                    if($coupon_rule) {
                                        DB::table('coupon_customers')->insert([
                                            'coupon_rule_id' => $coupon_rule->id,
                                            'cashback_rule_id' => $cashback->id,
                                            'customer_id' => $customer_id,
                                            'created_at' => now()
                                        ]);
                                    }
                                }
                            } else {
                                DB::table('coupon_customers')->insert([
                                    'coupon_rule_id' => $exist_coupon_rule->id,
                                    'cashback_rule_id' => $cashback->id,
                                    'customer_id' => $customer_id,
                                    'created_at' => now()
                                ]);
                            }
                            
                            // if($promotion) {
                            //     $coupon_rule = CouponRule::create([
                            //         'promotion_id'      => $promotion->id,
                            //         'coupon_code'     => $coupon_code,
                            //         'apply_to' => 'customer',
                            //         'coupon_type' => $coupon_type,
                            //         'percentage' => $cashback->cashback_percentage,
                            //         'amount' => $cashback->cashback_amount,
                            //     ]);

                            //     if($coupon_rule) {
                            //         DB::table('coupon_customers')->insert([
                            //             'coupon_rule_id' => $coupon_rule->id,
                            //             'cashback_rule_id' => $cashback->id,
                            //             'customer_id' => $customer_id,
                            //             'created_at' => now()
                            //         ]);
                            //     }
                            // }
                        }
                    }
                }

                if ($couponCode = $orderRequest->input('couponCode')) {
                    // Discount::getFacadeRoot()->afterOrderPlaced($couponCode, $orderRequest->input('customer_id') ? $request->input('customer_id') : $customer_id);

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
                            'customer_id' => $orderRequest->input('customer_id') ?? $customer_id,
                            'discount_id' => $promotionId
                        ]);
                    }
                }

                if($orderRequest->input('customer_id')) {
                    $loggedInCustomer = Customer::where('id', $orderRequest->input('customer_id'))->first();
                } else {
                    $loggedInCustomer = null;
                }
                $invoice = Invoice::query()->create([
                    'reference_type' => 'Botble\Ecommerce\Models\Order',
                    'reference_id' => $order->id,
                    'customer_name' => $loggedInCustomer ? $loggedInCustomer->name : $orderRequest->input('billingAddress.first_name').' '.$orderRequest->input('billingAddress.last_name'),
                    'customer_email' => $loggedInCustomer ? $loggedInCustomer->email : $orderRequest->input('billingAddress.email'),
                    'customer_phone' => $loggedInCustomer ? $loggedInCustomer->phone : $orderRequest->input('billingAddress.mobile'),
                    'customer_address' => $orderRequest->input('billingAddress.area').' '.$orderRequest->input('billingAddress.building'),
                    'sub_total' => $orderRequest->input('totalPrice') ? : 0,
                    'tax_amount' => ($orderRequest->input('totalPrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100)) + ($orderRequest->input('shippingPrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100)) + ($orderRequest->input('servicePrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100)),
                    'shipping_amount' => $orderRequest->input('shippingPrice') / (1 + ($orderRequest->input('vatTax') / 100)),
                    'shipping_amount_vat' => $orderRequest->input('shippingPrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100),
                    'service_amount' => $orderRequest->input('servicePrice') / (1 + ($orderRequest->input('vatTax') / 100)),
                    'service_amount_vat' => $orderRequest->input('servicePrice') / (1 + ($orderRequest->input('vatTax') / 100)) * ($orderRequest->input('vatTax') / 100),
                    'vat' => $orderRequest->input('vatTax'),
                    'discount_amount' => $orderRequest->input('discount_amount') ? : 0,
                    'shipping_method' => $orderRequest->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
                    'coupon_code' => $orderRequest->input('couponCode'),
                    'discount_description' => $orderRequest->input('discount_description'),
                    'amount' => $orderRequest->input('finalPrice'),
                    'payment_id' => $order->payment_id,
                    'status' => $orderRequest->input('payment_status'),
                ]);

                foreach ($orderRequest->input('products') as $product) {
                    
                    $quantity = $product['quantity'] ? $product['quantity'] : 1;

                    $exisProduct = Product::where('id', $product['product_id'])->first();

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

                    // $customerCouponData = [];

                    // if ($coupons->isEmpty()) {
                    // $customer_coupons = DiscountCustomer::select('code', 'value', 'start_date', 'end_date')->where('customer_id', $customer_id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();
                    // foreach ($customer_coupons as $customer_coupon) {
                    //     $customerCouponData[strtolower($customer_coupon->code)] = [
                    //         'code' => strtolower($customer_coupon->code),
                    //         'value' => $customer_coupon->value,
                    //         'start_date' => $customer_coupon->start_date,
                    //         'end_date' => $customer_coupon->end_date,
                    //     ];
                    // }
                    // $exisProduct->customer_coupon = $customerCouponData;

                    $customerCoupons = Promotion::select('coupon_code AS code', 'percentage', 'amount', 'start_date', 'end_date', 'apply_to AS target', 'coupon_type')
                        ->leftJoin('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id')
                        ->leftJoin('coupon_customers', 'coupon_rules.id', 'coupon_customers.coupon_rule_id')
                        ->where('type', 'coupon')
                        ->where('apply_to', 'customer')
                        ->where('customer_id', $customer_id)
                        ->whereDate('start_date', '<=', now())
                        ->whereDate('end_date', '>=', now())
                        ->get()
                        ->mapWithKeys(function ($coupon) {
                            return [
                                strtolower($coupon->code) => [
                                    'code' => strtolower($coupon->code),
                                    'value' => !is_null($coupon->percentage) && $coupon->coupon_type == 'percent' ? intval($coupon->percentage) : intval($coupon->amount),
                                    'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                                    'end_date' => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
                                    'type' => $coupon->target,
                                    'coupon_type' => $coupon->coupon_type
                                ],
                            ];
                        })
                        ->toArray();

                    $exisProduct->customer_coupon = empty($customerCoupons) ? [] : $customerCoupons;
                    // }

                    if(!is_null($exisProduct->discount)) {
                        if($exisProduct->discount->discount_type == 'percent') {
                            $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                            $total_amount = $price * $quantity;
                            $discount_percent = $exisProduct->discount->value;
                            $discount_amount = ($total_amount / 100) * $discount_percent;
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
                            $gross_amount = $net_amount + $tax_amount;
                            $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                            $orderProduct = [
                                'invoice_id' => $invoice->id,
                                'reference_type' => 'Botble\Ecommerce\Models\Product',
                                'reference_id' => $exisProduct->id,
                                'name' => $exisProduct->name,
                                // 'description' => $exisProduct->description,
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
                            $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                            $total_amount = $price * $quantity;
                            $sale_price = $exisProduct->discount->final_price / (1 + ($orderRequest->input('vatTax') / 100));
                            $discount_percent = 0;
                            $discount_amount = $total_amount - ($sale_price * $quantity);
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
                            $gross_amount = $net_amount + $tax_amount;
                            $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                            $orderProduct = [
                                'invoice_id' => $invoice->id,
                                'reference_type' => 'Botble\Ecommerce\Models\Product',
                                'reference_id' => $exisProduct->id,
                                'name' => $exisProduct->name,
                                // 'description' => $exisProduct->description,
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
                    } elseif(!empty($product['coupon']) && !is_null($exisProduct->coupon) && !empty($exisProduct->coupon) && isset($exisProduct->coupon) && isset($exisProduct->coupon[strtolower($orderRequest->input('couponCode'))]) && $exisProduct->coupon[strtolower($orderRequest->input('couponCode'))]['code'] == strtolower($orderRequest->input('couponCode'))) {
                        $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = $exisProduct->coupon[strtolower($orderRequest->input('couponCode'))]['value'];
                        $discount_amount = ($total_amount / 100) * $discount_percent;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'invoice_id' => $invoice->id,
                            'reference_type' => 'Botble\Ecommerce\Models\Product',
                            'reference_id' => $exisProduct->id,
                            'name' => $exisProduct->name,
                            // 'description' => $exisProduct->description,
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
                    } elseif(isset($product['is_customer_coupon']) && !isset($product['is_gift']) && is_null($exisProduct->sale_price) && !is_null($exisProduct->customer_coupon) && !empty($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]) && $exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['code'] == strtolower($orderRequest->input('couponCode'))) {
                            if($exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['coupon_type'] == 'percent') {
                            // echo 'Customer Coupon Percent';
                            // echo '\n ';
                            $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                            $total_amount = $price * $quantity;
                            $discount_percent = $exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['value'];
                            $discount_amount = ($total_amount / 100) * $discount_percent;
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
                            $gross_amount = $net_amount + $tax_amount;
                            $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                            $orderProduct = [
                                'invoice_id' => $invoice->id,
                                'reference_type' => 'Botble\Ecommerce\Models\Product',
                                'reference_id' => $exisProduct->id,
                                'name' => $exisProduct->name,
                                // 'description' => $exisProduct->description,
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
                        } elseif($exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['coupon_type'] == 'amount') {
                            // echo 'Customer Coupon Amount';
                            // echo '\n ';
                            $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                            $total_amount = $price * $quantity;
                            $sale_price = $price - ($exisProduct->customer_coupon[strtolower($orderRequest->input('couponCode'))]['value'] / (1 + ($orderRequest->input('vatTax') / 100)));
                            $discount_percent = 0;
                            $discount_amount = $total_amount - ($sale_price * $quantity);
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
                            $gross_amount = $net_amount + $tax_amount;
                            $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                            $orderProduct = [
                                'invoice_id' => $invoice->id,
                                'reference_type' => 'Botble\Ecommerce\Models\Product',
                                'reference_id' => $exisProduct->id,
                                'name' => $exisProduct->name,
                                // 'description' => $exisProduct->description,
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
                    }
                    // elseif(!is_null($exisProduct->sale_price)) {
                    //     $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                    //     $total_amount = $price * $quantity;
                    //     $sale_price = $exisProduct->sale_price / (1 + ($orderRequest->input('vatTax') / 100));
                    //     $discount_percent = 0;
                    //     $discount_amount = $total_amount - ($sale_price * $quantity);
                    //     $net_amount = $total_amount - $discount_amount;
                    //     $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
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
                        $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                        $total_amount = 0.00;
                        $discount_percent = 100;
                        $discount_amount = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                        $net_amount = 0.00;
                        $tax_amount = 0.00;
                        $gross_amount = 0.00;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'invoice_id' => $invoice->id,
                            'reference_type' => 'Botble\Ecommerce\Models\Product',
                            'reference_id' => $exisProduct->id,
                            'name' => $exisProduct->name,
                            // 'description' => $exisProduct->description,
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
                        $price = $exisProduct->price / (1 + ($orderRequest->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = 0;
                        $discount_amount = 0.00;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $orderRequest->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'invoice_id' => $invoice->id,
                            'reference_type' => 'Botble\Ecommerce\Models\Product',
                            'reference_id' => $exisProduct->id,
                            'name' => $exisProduct->name,
                            // 'description' => $exisProduct->description,
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

                if ($paymentSuccessful) {
                    try {
                        // Find the user's cart ID based on their customer ID.
                        $cart = Cart::where('user_id', $customer_id)->first();

                        // Check if the user actually has a cart.
                        if ($cart) {
                            // 1. Delete all products associated with this cart ID from ec_cart_products.
                            CartProduct::where('cart_id', $cart->id)->delete();

                            // 2. Delete the main cart record itself from ec_carts.
                            $cart->delete();

                            Log::info("Cart cleared successfully for customer ID: " . $customer_id);
                        }
                    } catch (\Exception $e) {
                        // Log an error if cart clearing fails, but don't stop the order process.
                        Log::error("Failed to clear cart for customer ID: " . $customer_id . ' - Error: ' . $e->getMessage());
                        // It's usually better to let the order succeed even if cart clearing fails.
                        // You can handle orphaned carts later if needed.
                    }
                }

                app(CreatePaymentForOrderService::class)->execute(
                    $order,
                    $paymentDetails['method'],
                    $paymentDetails['status'],
                    $customer_id,
                    $paymentDetails['transaction_ref'] ?? null, // This will be null for COD
                    $paymentDetails['message'] ?? null          // This will be null for COD
                );
                DB::commit();
                return $order;
            }
        } catch (\Exception $e) {
            //throw $th;
            DB::rollBack();
            Log::error('Order Creation Failed in Exception: ' . $e->getMessage());
            return null;
        }
    }

    public function initiatePayment(Request $request) {

        // --- STEP 1: VALIDATE PRODUCTS, STOCK, PROMOTIONS, AND COUPON ---
        $validator = Validator::make($request->all(), [
            'products' => 'required|array'
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $barcodes = [];
        $prod = array();

        foreach ($request->input('products') as $product) {
            $exisProduct = Product::where('id', $product['product_id'])->first();

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
                return response()->json([
                    'discountMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                ]);
            }

            if (!$requestHasDiscount && $dbHasDiscount) {
                return response()->json([
                    'discountMessage' => 'One or more Products were removed. Please add them again to continue. Request '.$product['product_name']
                ]);
            }
            
            $focFromDb = Promotion::where('type', 'foc')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->whereHas('focRules', function ($query) {
                })
                ->whereHas('focRules.products', function ($query) use ($product) {
                    $query->where('product_id', $product['product_id']);
                })
                ->with(['focRules' => function ($query) {
                        $query->select('id', 'promotion_id', 'min_threshold', 'max_threshold');
                }])
                ->first();
                
            $requestHasFOC = isset($product['type']) && $product['type'] == 'foc';
            $dbHasFOC = !is_null($focFromDb);

            if ($requestHasFOC && !$dbHasFOC) {
                return response()->json([
                    'focMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                ]);
            }

            if (!$requestHasFOC && $dbHasFOC) {
                return response()->json([
                    'focMessage' => 'One or more Products were removed. Please add them again to continue. Request '.$product['product_name']
                ]);
            }

            $requestHasBOGO = isset($product['type']) && $product['type'] == 'bogo' && isset($product['is_gift']);
            $bogoFromDb = null;

            if ($requestHasBOGO) {
                $bogoFromDb = Promotion::where('type', 'buy_x_get_y')
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->whereHas('buyXGetYRules.products', function ($query) use ($product) {
                        $query->where('product_id', $product['product_id']);
                    })
                    ->first();
            }

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

            $quantity = $product['quantity'] ?? 1;
            $productForResponse = clone $exisProduct;
            $productForResponse->qty = $quantity;
            // Add flags if the product is a gift or affected by a customer coupon (from request).
            if (isset($product['is_gift']) && $product['is_gift'] == true) {
                $productForResponse->is_gift = 1;
            }
            if (isset($product['is_customer_coupon']) && $product['is_customer_coupon'] == true) {
                $productForResponse->is_customer_coupon = 1;
            }
            array_push($prod, $productForResponse);
        }
        $coupon_code = $request->input('couponCode');
        if(isset($coupon_code) && !empty($request->input('couponCode'))) {
            $coupon = Promotion::select('type', 'start_date', 'end_date', 'coupon_code AS code', 'percentage As value', 'apply_to')->where('type', 'coupon')->where('coupon_code', $request->input('couponCode'))->where('start_date', '<=', now())->where('end_date', '>=', now())->join('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id', 'left')->first();
            if(!$coupon) {
                return response()->json(['couponMessage' => 'Invalid Coupon Code']);
            }

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

        // $cashback = Promotion::select('promotions.name', 'cashback_rules.id', 'cashback_percentage', 'cashback_amount', 'duration')->where('type', 'cashback')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('cashback_rules', 'promotions.id', '=', 'cashback_rules.promotion_id')->first();
        // if($cashback) {
        //     $coupon_code = !is_null($cashback->cashback_percentage) ? 'CASHBACK'.intval($cashback->cashback_percentage) : 'CASHBACK'.intval($cashback->cashback_amount);
        //     $coupon_type = !is_null($cashback->cashback_percentage) ? 'percent' : 'amount';
        //     $cashback_product_ids = CashbackProduct::select('product_id')->where('cashback_rule_id', $cashback->id)->pluck('product_id')->toArray();
        //     // echo "<pre>";print_r($cashback_products);
        // } else {
        //     $cashback_product_ids = [];
        // }

        // 2. --- FIND OR CREATE CUSTOMER ---
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

        // --- STEP 3: HANDLE PAYMENT BASED ON METHOD ---
        $paymentMethod = $request->input('payment_method');

        // --- HANDLE COD DIRECTLY ---
        if ($paymentMethod === 'cod') {
            $existingOrder = Order::where('user_id', $customer_id)
                ->where('amount', $request->input('finalPrice'))
                ->where('created_at', '>=', Carbon::now()->subMinutes(5))
                ->first();

            if ($existingOrder) {
                return response()->json([
                    'duplicateOrderMessage' => 'You order has been placed already. Order Id: ' . $existingOrder->code
                ]);
            }
            $paymentDetails = [
                'method' => 'cod',
                'status' => 'completed', // For COD, we consider the payment step 'completed' upon order creation.
            ];

            $order = $this->_createFinalOrder($request, $customer_id, $paymentDetails);
            
            if (!$order) {
                return response()->json(['error' => 'Failed to create order. Please try again.'], 500);
            }

            return response()->json([
                'message'  => 'Order created successfully with Cash on Delivery.',
                'order_id' => $order->code,
                'id'                => $order->id,
                'customer_name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                'payment_method'   => $request->input('payment_method'),
                'total'            => $order->amount,
                'sub_total'        => $order->sub_total,
                'shipping_amount'  => $order->shipping_amount,
                'products'         => $prod
            ]);

        } elseif ($paymentMethod === 'paytabs') { 
            // For PayTabs, create a temporary cart and get a redirect URL.
            $paymentCart = PaymentCart::create([
                'customer_id' => $customer_id,
                'cart_data'   => $request->all(),
            ]);

            if (!$paymentCart) {
                return response()->json(['error' => 'Could not initiate payment session.'], 500);
            }

            // 4. --- PREPARE SHIPPING DATA FOR PAYTABS ---
            $cartData = $paymentCart->cart_data;
            if (!empty($cartData['shippingAddress']['first_name'])) {
                $shippingData = [
                    "name"    => $cartData['shippingAddress']['first_name'] . ' ' . $cartData['shippingAddress']['last_name'],
                    "email"   => $cartData['shippingAddress']['email'],
                    "phone"   => $cartData['shippingAddress']['mobile'],
                    "street1" => $cartData['shippingAddress']['area'] . ' ' . $cartData['shippingAddress']['building'],
                    "city"    => $cartData['shippingAddress']['emirates'],
                    "state"   => $cartData['shippingAddress']['emirates'],
                ];
            } else {
                $shippingData = [
                    "name"    => $cartData['billingAddress']['first_name'] . ' ' . $cartData['billingAddress']['last_name'],
                    "email"   => $cartData['billingAddress']['email'],
                    "phone"   => $cartData['billingAddress']['mobile'],
                    "street1" => $cartData['billingAddress']['area'] . ' ' . $cartData['billingAddress']['building'],
                    "city"    => $cartData['billingAddress']['emirates'],
                    "state"   => $cartData['billingAddress']['emirates'],
                ];
            }

            // 5. --- INITIATE PAYMENT AND GET REDIRECT URL ---
            $response = $this->payTabsPayment($request, $shippingData, $paymentCart);

            if (isset($response['redirect_url'])) {
                return response()->json([
                    'message'      => 'Redirecting to Paytabs...',
                    'redirect_url' => $response['redirect_url']
                ]);
            }

            return response()->json(['error' => 'Failed to connect to payment gateway.'], 500);
        }

        // Handle any other invalid payment methods.
        return response()->json(['error' => 'Invalid payment method provided.'], 400);  
    }

    public function finalizePayment(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {

        Log::info('PayTabs Callback Received', $request->all());
        // 1. --- FIND THE TEMPORARY CART ---
        $paymentCart = PaymentCart::find($request->input('cartId'));
        if (!$paymentCart) {
            return response()->json(['error' => 'Invalid session'], 404);
        }

        // 3. --- LOAD THE SAVED CART DATA ---
        $cartData = $paymentCart->cart_data;
        $orderRequest = new Request($cartData); // Create a new R5
        // 
        // equest object from the saved data.

        // STEP 3: Prepare the payment details package from the actual PayTabs response.
        $paymentDetails = [
            'method'          => 'paytabs',
            'status'          => $request->input('respStatus'), // This will be 'A' for an Accepted payment.
            'transaction_ref' => $request->input('tranRef'),   // The unique transaction ID from PayTabs.
            'message'         => $request->input('respMessage'), // The response message (e.g., "Approved").
        ];

        // 4. --- SAFETY CHECK: RE-VALIDATE STOCK ---
        foreach ($orderRequest->input('products') as $product) {
            $dbProduct = Product::find($product['product_id']);
            if ($dbProduct->quantity < $product['quantity']) {
                // This is a serious issue: payment was taken for an out-of-stock item.
                // You should log this error and handle it manually (e.g., refund).
                // For now, we'll stop and mark the cart as failed.
                $paymentCart->status = 'failed_stock';
                $paymentCart->save();
                return response()->json(['error' => 'Stock unavailable for item: ' . $dbProduct->id], 500);
            }
        }

        // STEP 4: Call the reusable helper function to create the final order.
        // We pass the cart data, customer ID, payment details, and the temporary cart's ID.
        $order = $this->_createFinalOrder(
            $orderRequest,
            $paymentCart->customer_id,
            $paymentDetails,
            $paymentCart->id // Pass the temporary cart ID to be saved in the final order.
        );
        
        if (!$order) {
            $failureUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/order-failure?cartId=' . $paymentCart->id;
            return redirect($failureUrl);
        }

        $paymentCart->delete();

        // STEP 5: Redirect the user's browser to the correct success or failure page on the frontend.
        $isSuccess = ($paymentDetails['status'] === 'A');
        $page = $isSuccess ? 'complete' : 'failure';
        
        // Construct the final URL, including the order code for the user to see.
        $redirectUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/shop-order-payment-complete' . '?q=' . base64_encode($order->code);

        return redirect($redirectUrl);
    }


    public function getOrderStatus(string $cartId): JsonResponse {
        // Find the final order using the temporary cart ID we saved.
        $order = Order::where('payment_cart_id', $cartId)->first();

        if ($order) {
            // If the order is found, it means the payment was successful
            // and the backend has created the real order.
            return response()->json([
                'status' => 'completed',
                'order_code' => $order->code,
            ]);
        } else {
            // If no order is found yet, tell the frontend it's still pending.
            return response()->json(['status' => 'pending']);
        }
    }
}

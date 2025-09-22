<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\CartService;
use Botble\Ecommerce\Models\Tax;

class CartController extends Controller
{

    public function getCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        DB::beginTransaction();
        try {
            $tax = Tax::select('percentage')->where('status', 'published')->first();

            $cart = Cart::where('user_id', $request->customer_id)->first();

            if (!$cart) {
               return response()->json(['success' => false, 'message' => 'Cart not found'], 404);
            }

            $prod_arr = array();

            // Sync remaining cart products with latest data
            foreach ($cart->cartProducts as $cartProduct) {
                $latestProduct = CartService::getProductWithDetails($cartProduct->product_id, $cartProduct->qty);

                if (!$latestProduct) {
                    return response()->json(['notFound' => 'Product not found '.$cartProduct->product_id], 500);
                }

                // Update CartProduct with fresh price, discount, totals
                $cartProduct->price = number_format($latestProduct->price, 2, '.', '');
                $total_amount       = $latestProduct->price * $latestProduct->quantity;

                $discount_amount = 0;
                $discount_percent = 0;
                $net_amount = $total_amount;

                if ($latestProduct->discount) {
                    if ($latestProduct->discount->discount_type === 'percent') {
                        $discount_percent = $latestProduct->discount->value;
                        $discount_amount  = ($total_amount / 100) * $discount_percent;
                        $net_amount       = $total_amount - $discount_amount;
                    } elseif ($latestProduct->discount->discount_type === 'amount') {
                        $discount_amount  = $latestProduct->discount->discount_amount;
                        $net_amount       = $total_amount - $discount_amount;
                    }
                }

                $tax_amount   = ($net_amount / 100) * $tax->percentage;
                $gross_amount = $net_amount + $tax_amount;

                $cartProduct->qty              = $latestProduct->quantity;
                $cartProduct->total_amount     = $total_amount;
                $cartProduct->discount_amount  = $discount_amount;
                $cartProduct->discount_percent = $discount_percent;
                $cartProduct->net_amount       = $net_amount;
                $cartProduct->tax_amount       = $tax_amount;
                $cartProduct->gross_amount     = $gross_amount;
                $cartProduct->vat              = $tax->percentage;
                $cartProduct->save();

                $latestProduct->price = number_format($latestProduct->price * (1 + ($tax->percentage / 100)), 2, '.', '');
                array_push($prod_arr, $latestProduct);
            }

            DB::commit();

            return response()->json($prod_arr, 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // Add item to cart
    public function addUpdateCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'products'    => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $tax = Tax::select('percentage')->where('status', 'published')->first();

            // Find or create cart for user
            $cart = Cart::firstOrCreate(
                ['user_id' => $request->customer_id],
                []
            );
            // echo "<pre>";print_r($cart);die;
            $prod_arr = array();
            foreach ($request->products as $item) {
                // Fetch latest product with all details
                $product = CartService::getProductWithDetails($item['product_id'], $item['quantity']);

                if (!$product) {
                    return response()->json(['notFound' => 'Product not found '.$item['product_id']], 500);
                }
                
                $cartProduct = CartProduct::where('cart_id', $cart->id)
                    ->where('product_id', $product->product_id)
                    ->first();

                $total_amount = $product->price * $product->quantity;
                $discount_percent = 0;
                $discount_amount = 0;
                $net_amount = $total_amount;

                // Apply discount logic
                if ($product->discount) {
                    if ($product->discount->discount_type === 'percent') {
                        $discount_percent = $product->discount->value;
                        $discount_amount  = ($total_amount / 100) * $discount_percent;
                        $net_amount       = $total_amount - $discount_amount;
                    } elseif ($product->discount->discount_type === 'amount') {
                        $discount_amount  = $product->discount->discount_amount;
                        $net_amount       = $total_amount - $discount_amount;
                    }
                }

                $tax_amount = ($net_amount / 100) * $tax->percentage;
                $gross_amount = $net_amount + $tax_amount;

                if ($cartProduct) {
                    // Update existing product
                    $cartProduct->qty = $product->quantity;
                    $cartProduct->price = $product->price;
                    $cartProduct->total_amount = $total_amount;
                    $cartProduct->discount_percent = $discount_percent;
                    $cartProduct->discount_amount = $discount_amount;
                    $cartProduct->net_amount = $net_amount;
                    $cartProduct->tax_amount = $tax_amount;
                    $cartProduct->gross_amount = $gross_amount;
                    $cartProduct->vat = $tax->percentage;
                    $cartProduct->save();
                } else {
                    // Insert new product into cart
                    CartProduct::create([
                        'cart_id'            => $cart->id,
                        'product_id'         => $product->product_id,
                        'product_name'       => $product->product_name,
                        'price'              => $product->price,
                        'qty'                => $product->quantity,
                        'total_amount'       => $total_amount,
                        'product_category'   => $product->category_name,
                        'product_subcategory'=> $product->subcategory->subcategory_name ?? null,
                        'discount_percent'   => $discount_percent,
                        'discount_amount'    => $discount_amount,
                        'net_amount'         => $net_amount,
                        'tax_amount'         => $tax_amount,
                        'gross_amount'       => $gross_amount,
                        'vat'                => $tax->percentage,
                    ]);
                }

                $product->price = number_format($product->price * (1 + ($tax->percentage / 100)), 2, '.', '');
                array_push($prod_arr, $product);
            }

            DB::commit();

            return response()->json($prod_arr, 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Remove product from cart
    public function removeFromCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'product_id'    => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $tax = Tax::select('percentage')->where('status', 'published')->first();

            $cart = Cart::where('user_id', $request->customer_id)->first();
            if (!$cart) {
                return response()->json(['success' => false, 'message' => 'Cart not found'], 404);
            }

            CartProduct::where('cart_id', $cart->id)
                ->where('product_id', $request->product_id)
                ->delete();

            // Check if cart is now empty
            if ($cart->cartProducts()->count() === 0) {
                $cart->delete();
                return response()->json(['success' => true, 'message' => 'Cart deleted (empty now)'], 200);
            }

            $prod_arr = array();

            // Sync remaining cart products with latest data
            foreach ($cart->cartProducts as $cartProduct) {
                $latestProduct = CartService::getProductWithDetails($cartProduct->product_id, $cartProduct->qty);
                
                if (!$latestProduct) {
                    return response()->json(['notFound' => 'Product not found '.$cartProduct->product_id], 500);
                }

                // Update CartProduct with fresh price, discount, totals
                $cartProduct->price = number_format($latestProduct->price, 2, '.', '');
                $total_amount       = $latestProduct->price * $latestProduct->quantity;

                $discount_amount = 0;
                $discount_percent = 0;
                $net_amount = $total_amount;

                if ($latestProduct->discount) {
                    if ($latestProduct->discount->discount_type === 'percent') {
                        $discount_percent = $latestProduct->discount->value;
                        $discount_amount  = ($total_amount / 100) * $discount_percent;
                        $net_amount       = $total_amount - $discount_amount;
                    } elseif ($latestProduct->discount->discount_type === 'amount') {
                        $discount_amount  = $latestProduct->discount->discount_amount;
                        $net_amount       = $total_amount - $discount_amount;
                    }
                }

                $tax_amount   = ($net_amount / 100) * $tax->percentage;
                $gross_amount = $net_amount + $tax_amount;

                $cartProduct->qty              = $latestProduct->quantity;
                $cartProduct->total_amount     = $total_amount;
                $cartProduct->discount_amount  = $discount_amount;
                $cartProduct->discount_percent = $discount_percent;
                $cartProduct->net_amount       = $net_amount;
                $cartProduct->tax_amount       = $tax_amount;
                $cartProduct->gross_amount     = $gross_amount;
                $cartProduct->vat              = $tax->percentage;
                $cartProduct->save();

                $latestProduct->price = number_format($latestProduct->price * (1 + ($tax->percentage / 100)), 2, '.', '');
                array_push($prod_arr, $latestProduct);
            }

            DB::commit();

            return response()->json($prod_arr, 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}


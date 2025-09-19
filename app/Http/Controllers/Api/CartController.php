<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Botble\Ecommerce\Models\Tax;

class CartController extends Controller
{

    public function getCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'      => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $cart = Cart::with('cartProducts')->where('user_id', $request->user_id)->first();

        if (!$cart) {
            return response()->json([
                'success' => true,
                'cart' => null
            ], 200);
        }

        return response()->json([
            'success' => true,
            'cart' => $cart
        ], 200);
    }

    // Add item to cart
    public function addToCart(Request $request)
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
            foreach ($request->products as $item) {
                $cartProduct = CartProduct::where('cart_id', $cart->id)
                    ->where('product_id', $item['product_id'])
                    ->first();

                $price = $item['price'] / (1 + ($tax->percentage / 100));

                // If product exists, add new quantity
                $newQty = $cartProduct ? $cartProduct->qty + $item['quantity'] : $item['quantity'];

                $total_amount = $price * $newQty;
                $discount_percent = 0;
                $discount_amount = 0;
                $net_amount = $total_amount;
                $tax_amount = ($net_amount / 100) * $tax->percentage;
                $gross_amount = $net_amount + $tax_amount;

                // Apply discount logic
                if (!empty($item['discount'])) {
                    if ($item['discount']['discount_type'] === 'percent') {
                        $discount_percent = $item['discount']['value'];
                        $discount_amount = ($total_amount / 100) * $item['discount']['value'];
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $tax->percentage;
                        $gross_amount = $net_amount + $tax_amount;
                    } elseif ($item['discount']['discount_type'] === 'amount') {
                        $discount_percent = 0;
                        $sale_price = $item['discount']['final_price'] / (1 + ($tax->percentage / 100));
                        $discount_amount = $total_amount - ($sale_price * $newQty);
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $tax->percentage;
                        $gross_amount = $net_amount + $tax_amount;
                    }
                }

                if ($cartProduct) {
                    // Update existing product
                    $cartProduct->qty = $newQty;
                    $cartProduct->price = $price;
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
                        'product_id'         => $item['product_id'],
                        'product_name'       => $item['product_name'],
                        'price'              => $price,
                        'qty'                => $newQty,
                        'total_amount'       => $total_amount,
                        'product_category'   => $item['category_name'] ?? null,
                        'product_subcategory'=> $item['subcategory_name'] ?? null,
                        'discount_percent'   => $discount_percent,
                        'discount_amount'    => $discount_amount,
                        'net_amount'         => $net_amount,
                        'tax_amount'         => $tax_amount,
                        'gross_amount'       => $gross_amount,
                        'vat'                => $tax->percentage,
                    ]);
                }
            }

            // Recalculate cart totals
            // $cart->sub_total = $cart->cartProducts()->sum('gross_amount');
            // $cart->amount = $cart->sub_total; // later add tax/shipping/discounts
            // $cart->save();

            DB::commit();

            return response()->json([ $cart->load('cartProducts')], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Update product qty in cart
    public function updateCart(Request $request, $productId)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'qty' => 'required|integer|min:1'
        ]);

        $cart = Cart::where('user_id', $request->user_id)->first();
        if (!$cart) {
            return response()->json(['success' => false, 'message' => 'Cart not found'], 404);
        }

        $cartProduct = CartProduct::where('order_id', $cart->id)
            ->where('product_id', $productId)
            ->first();

        if (!$cartProduct) {
            return response()->json(['success' => false, 'message' => 'Product not found in cart'], 404);
        }

        $cartProduct->qty = $request->qty;
        $cartProduct->total_amount = $cartProduct->qty * $cartProduct->price;
        $cartProduct->save();

        $cart->sub_total = $cart->cartProducts()->sum('total_amount');
        $cart->amount = $cart->sub_total;
        $cart->save();

        return response()->json(['success' => true, 'cart' => $cart->load('cartProducts')], 200);
    }

    // Remove product from cart
    public function removeFromCart(Request $request, $productId)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);

        $cart = Cart::where('user_id', $request->user_id)->first();
        if (!$cart) {
            return response()->json(['success' => false, 'message' => 'Cart not found'], 404);
        }

        CartProduct::where('order_id', $cart->id)
            ->where('product_id', $productId)
            ->delete();

        $cart->sub_total = $cart->cartProducts()->sum('total_amount');
        $cart->amount = $cart->sub_total;
        $cart->save();

        return response()->json(['success' => true, 'cart' => $cart->load('cartProducts')], 200);
    }
}


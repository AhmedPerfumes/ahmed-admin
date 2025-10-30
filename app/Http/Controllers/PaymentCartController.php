<?php

namespace App\Http\Controllers;

use App\Models\PaymentCart;
use App\Tables\PaymentCartTable;
use Botble\Base\Http\Responses\BaseHttpResponse;

class PaymentCartController extends Controller
{
    public function index(PaymentCartTable $table)
    {
        return $table->renderTable();
    }

    public function show(PaymentCart $paymentCart)
    {
        page_title()->setTitle('Payment Cart Details');
        return view('admin.payment-carts.show', ['cart' => $paymentCart]);
    }

    public function destroy(PaymentCart $paymentCart, BaseHttpResponse $response)
    {
        $paymentCart->status = 'deleted';
        $paymentCart->save();

        return $response->setMessage('Cart moved to trash successfully!');
    }
}
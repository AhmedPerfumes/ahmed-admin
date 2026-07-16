<table style="text-align:center;background-color:#F7F7F7;width:100%;">
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
                                                                                        <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Hi {{ $shipping_data->name }},</p>
                                                                                        <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Just to let you know - we have received your order {{ $order->code }}, and it is now being processed:</p>
                                                                                        @if($paymentMethod == 'cod')
                                                                                            <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Pay with cash upon delivery.</p>
                                                                                        @endif
                                                                                        <h2 style="color:#C7944B;font-size:18px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;display:block;margin:0 0 18px 0;line-height:130%;">[Order {{ $order->code }}] ({{ date_format(date_create($order->created_at), "F j, Y") }})</h2>
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
                                                                                                    </tr>
                                                                                                    @foreach ($order_products as $value)
                                                                                                        @if($value->discount_percent != 0)
                                                                                                            @if($value->is_gift == 1)
                                                                                                                <tr>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">{{ $value->product_name }}</div>
                                                                                                                    </td>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">{{ $value->qty }}</div>
                                                                                                                    </td>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; 0.00 (Free Gift)</div>
                                                                                                                    </td>
                                                                                                                </tr>
                                                                                                            @else
                                                                                                                <tr>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">{{ $value->product_name }}</div>
                                                                                                                    </td>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">{{ $value->qty }}</div>
                                                                                                                    </td>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; {{ round((($value->price * 1.05 - ($value->price * 1.05 * $value->discount_percent / 100)) * $value->qty), 2) }}</div>
                                                                                                                    </td>
                                                                                                                </tr>
                                                                                                            @endif
                                                                                                        @else
                                                                                                            @if($value->discount_amount != '0')
                                                                                                                <tr>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">{{ $value->product_name }}</div>
                                                                                                                    </td>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">{{ $value->qty }}</div>
                                                                                                                    </td>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; {{ round($value->gross_amount, 2) }}</div>
                                                                                                                    </td>
                                                                                                                </tr>
                                                                                                            @else
                                                                                                                <tr>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">{{ $value->product_name }}</div>
                                                                                                                    </td>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">{{ $value->qty }}</div>
                                                                                                                    </td>
                                                                                                                    <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                        <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; {{ round((($value->price * (1 + $value->vat / 100)) * $value->qty), 2) }}</div>
                                                                                                                    </td>
                                                                                                                </tr>
                                                                                                            @endif
                                                                                                        @endif
                                                                                                    @endforeach
                                                                                                    
                                                                                                    <tr>
                                                                                                        <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border-width:4px 1px 1px 1px;border-style:solid;border-color:#E5E5E5;" colspan="2">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Subtotal:</div>
                                                                                                        </th>
                                                                                                        <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border-width:4px 1px 1px 1px;border-style:solid;border-color:#E5E5E5;">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; {{ $order->sub_total }}</div>
                                                                                                        </td>
                                                                                                    </tr>
                                                                                                    <tr>
                                                                                                        <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Shipping:</div>
                                                                                                        </th>
                                                                                                        <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">
                                                                                                                @if($order->shipping_amount == '0.00')
                                                                                                                    You Got Free Shipping
                                                                                                                @else
                                                                                                                    {{ round(($order->shipping_amount * 1.05), 2) }}&#x62F;&#x2E;&#x625;
                                                                                                                @endif
                                                                                                            </div>
                                                                                                        </td>
                                                                                                    </tr>
                                                                                                    <tr>
                                                                                                        <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Service Fee: </div>
                                                                                                        </th>
                                                                                                        <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; {{ round(($order->service_amount * 1.05), 2) }}</div>
                                                                                                        </td>
                                                                                                    </tr>
                                                                                                    @if($order->cod_charge != '0.00')
                                                                                                        <tr>
                                                                                                            <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                                <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">COD Charges: </div>
                                                                                                            </th>
                                                                                                            <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                                <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; {{ round(($order->cod_charge * 1.05), 2) }}</div>
                                                                                                            </td>
                                                                                                        </tr>
                                                                                                    @endif
                                                                                                    <tr>
                                                                                                        <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Payment method:</div>
                                                                                                        </th>
                                                                                                        <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">
                                                                                                                {{ $paymentMethod == 'cod' ? 'Cash on delivery' : $paymentMethod }}
                                                                                                            </div>
                                                                                                        </td>
                                                                                                    </tr>
                                                                                                    <tr>
                                                                                                        <th style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;" colspan="2">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">Total:</div>
                                                                                                        </th>
                                                                                                        <td style="color:#636363;text-align:left;vertical-align:middle;padding:12px;border:1px solid #E5E5E5;">
                                                                                                            <div style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;">&#x62F;&#x2E;&#x625; {{ $order->amount }}
                                                                                                                <small style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                    <div style="text-align:left;">(includes &#x62F;&#x2E;&#x625; {{ round(($order->tax_amount), 2) }} VAT)</div>
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
                                                                                                        @php
                                                                                                            $billing_data = \Botble\Ecommerce\Models\Address::where('customer_id', $order->user_id)->first();
                                                                                                        @endphp
                                                                                                        @if($billing_data)
                                                                                                            <address style="color:#636363;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;padding:12px;border:1px solid #E5E5E5;">{{ $billing_data->name }}<br> {{ $billing_data->address }}<br>{{ $billing_data->state }}<br>{{ $billing_data->city }}<br>
                                                                                                                <span style="color:#C7944B;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                    <u>
                                                                                                                        <a style="color:#C7944B;" href="tel:+971{{ ltrim($billing_data->phone, '0') }}">+971{{ ltrim($billing_data->phone, '0') }}</a>
                                                                                                                    </u>
                                                                                                                </span><br>
                                                                                                                <span style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                    <a href="mailto:{{ $billing_data->email }}">{{ $billing_data->email }}</a>
                                                                                                                </span>
                                                                                                            </address>
                                                                                                        @endif
                                                                                                    </td>
                                                                                                    <td style="text-align:left;vertical-align:top;width:50%;">
                                                                                                        <h2 style="color:#C7944B;font-size:18px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;display:block;margin:0 0 18px 0;line-height:130%;">Shipping address</h2>
                                                                                                        @if($shipping_data)
                                                                                                            <address style="color:#636363;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;padding:12px;border:1px solid #E5E5E5;">{{ $shipping_data->name }}<br> {{ $shipping_data->address }}<br>{{ $shipping_data->state }}<br>{{ $shipping_data->city }}<br>
                                                                                                                <span style="color:#C7944B;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                    <u>
                                                                                                                        <a style="color:#C7944B;" href="tel:+971{{ ltrim($shipping_data->phone, '0') }}">+971{{ ltrim($shipping_data->phone, '0') }}</a>
                                                                                                                </u>
                                                                                                                </span><br>
                                                                                                                <span style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;">
                                                                                                                    <a href="mailto:{{ $shipping_data->email }}">{{ $shipping_data->email }}</a>
                                                                                                                </span>
                                                                                                            </address>
                                                                                                        @endif
                                                                                                    </td>
                                                                                                </tr>
                                                                                            </tbody>
                                                                                        </table>
                                                                                        <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Thanks for shopping with us.</p>
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
                        </tbody>
                    </table>
                </div>
            </td>
            <td style="text-align:center;direction:ltr;"></td>
        </tr>
    </tbody>
</table>

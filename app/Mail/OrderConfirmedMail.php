<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Botble\Ecommerce\Models\Order;

class OrderConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $shippingData;
    public $paymentMethod;

    /**
     * Create a new message instance.
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
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope()
    {
        return new Envelope(
            subject: 'Your Ahmed Al Maghribi Perfumes order has been received!',
        );
    }

    /**
     * Get the message content definition.
     *
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content()
    {
        return new Content(
            view: 'emails.order_confirmed',
            with: [
                'order' => $this->order,
                'shipping_data' => $this->shippingData,
                'paymentMethod' => $this->paymentMethod,
                'order_products' => \Botble\Ecommerce\Models\OrderProduct::where('order_id', $this->order->id)->get(),
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array
     */
    public function attachments()
    {
        return [];
    }
}

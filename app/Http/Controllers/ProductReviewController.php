<?php

namespace App\Http\Controllers;

use App\Models\ProductReview;
use App\Tables\ProductReviewTable;
use Botble\Base\Http\Responses\BaseHttpResponse;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Illuminate\Support\Facades\Log;

class ProductReviewController extends Controller
{
    public function index(ProductReviewTable $table)
    {
        return $table->renderTable();
    }

    public function show(ProductReview $productReview)
    {
        page_title()->setTitle('Review Details');
        return view('admin.reviews.show', ['review' => $productReview]);
    }

    // v-- ADD THIS NEW FUNCTION TO HANDLE THE APPROVAL --v
    public function approve(ProductReview $productReview)
    {
        // Set the status to the system's "PUBLISHED" value
        $productReview->status = 'published';
        $productReview->save();

        $mail = new PHPMailer(true);
        try {
            // Get product details from the review
            $product = $productReview->product;
            $productName = 'the product';
            if ($product) {
                $productName = $product->name;
            }

            $customerName = htmlspecialchars($productReview->customer_name);
            $safeProductName = htmlspecialchars($productName);
            $safeComment = nl2br(htmlspecialchars($productReview->comment));


            /* Email SMTP Settings */
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = env('MAIL_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = env('MAIL_USERNAME');
            $mail->Password = env('MAIL_PASSWORD');
            $mail->SMTPSecure = env('MAIL_ENCRYPTION');
            $mail->Port = env('MAIL_PORT');

            $mail->setFrom(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'));
            
            // Set recipient from the review details
            $mail->addAddress($productReview->customer_email, $productReview->customer_name);

            $mail->isHTML(true);

            $mail->Subject = "Your review for {$safeProductName} is now live!";
            // --- START: New Email Body ---
            $body = '<table style="text-align:center;background-color:#F7F7F7;width:100%;">
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
                                                                                <h1 style="color:white;font-size:30px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;font-weight:300;text-align:left;margin:0;line-height:150%;">Your review is now live!</h1>
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
                                                                                                    <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Hi '. $customerName .',</p>
                                                                                                    <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Great news! Your review for <strong>'. $safeProductName .'</strong> has been approved and is now published on our website.</p>
                                                                                                    
                                                                                                    <h2 style="color:#C7944B;font-size:18px;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:left;display:block;margin:30px 0 18px 0;line-height:130%;">Your Review</h2>
                                                                                                    
                                                                                                    <div style="margin-bottom:40px;">
                                                                                                        <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 10px 0;"><strong>Rating:</strong> '. $productReview->star .' out of 5 stars</p>
                                                                                                        <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 10px 0;"><strong>Comment:</strong></p>
                                                                                                        <blockquote style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;border-left:4px solid #C7944B;padding-left:15px;font-style:italic;color:#555;">
                                                                                                            '. $safeComment .'
                                                                                                        </blockquote>
                                                                                                    </div>

                                                                                                    <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Thank you for your feedback!</p>
                                                                                                    <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;margin:0 0 16px 0;">Thanks for using <a style="margin-top:0;margin-bottom:0;color:#C7944B;text-decoration:none;" target="_blank" href="http://www.ahmedalmaghribi.com" title="http://www.ahmedalmaghribi.com">www.ahmedalmaghribi.com</a>!</p>
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
                                        <tr>
                                            <td style="vertical-align:top;" align="center">
                                                <table style="width:100%;border-spacing:0;border-collapse:collapse;box-sizing:border-box;" cellpadding="10" cellspacing="0">
                                                    <tbody>
                                                        <tr>
                                                            <td style="vertical-align:top;border-radius:6px;">
                                                                <table style="width:100%;border-spacing:0;border-collapse:collapse;box-sizing:border-box;" cellpadding="10" cellspacing="0">
                                                                    <tbody>
                                                                        <tr>
                                                                            <td style="color:#3C3C3C;text-align:center;vertical-align:middle;border-radius:6px;padding-top:24px;padding-bottom:24px;line-height:150%;" colspan="2">
                                                                                <p style="font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;text-align:center;margin:0 0 16px 0;line-height:150%;">
                                                                                    <span style="font-size:12px;">Ahmed Al Maghribi Perfumes LLC</span>
                                                                                </p>
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
            </table>';
            // --- END: New Email Body ---
            $mail->Body    = $body;

            $mail->send();

        } catch (Exception $e) {
            Log::error("Failed to send review approval email: {$mail->ErrorInfo}");
        }

        // Redirect the admin back to the same page with a success message
        return back()->with('success_message', 'Review has been approved and published successfully!');
    }
    public function destroy(ProductReview $productReview, BaseHttpResponse $response)
    {
        $productReview->status = 'deleted'; // A new, custom status
        $productReview->save();

        // This sends back a standard success message that the admin table understands.
        return $response->setMessage('Review moved to trash successfully!');
    }
}
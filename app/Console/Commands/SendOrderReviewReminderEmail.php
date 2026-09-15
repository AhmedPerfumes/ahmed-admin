<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\OrderAddress;
use App\Models\ProductReview;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class SendOrderReviewReminderEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reviews:send-reminders 
                            {--order= : Send reminder for a specific order ID or code}
                            {--email= : Override recipient email address for testing}
                            {--force : Force send bypassing time window and already-sent check}
                            {--dry-run : Simulate execution without sending emails}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send post-purchase review reminder emails to customers whose orders were delivered 7-20 days ago';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun   = $this->option('dry-run');
        $orderOption = $this->option('order');
        $emailOverride = $this->option('email');
        $isForced   = $this->option('force');

        Log::info('[ReviewReminderCron] Command execution started', [
            'is_dry_run'     => (bool) $isDryRun,
            'order_filter'   => $orderOption,
            'email_override' => $emailOverride,
            'is_forced'      => (bool) $isForced,
        ]);

        // Specific Single Order Test Mode
        if ($orderOption) {
            $cleanCode = ltrim(trim($orderOption), '#');
            $order = Order::where('code', $cleanCode)
                ->orWhere('code', '#' . $cleanCode)
                ->orWhere('id', is_numeric($cleanCode) ? (int)$cleanCode : 0)
                ->first();

            if (!$order) {
                $this->error("Order '{$orderOption}' not found in database.");
                Log::warning("[ReviewReminderCron] Single order lookup failed: '{$orderOption}' not found.");
                return 1;
            }

            $displayCode = str_starts_with($order->code, '#') ? $order->code : '#' . $order->code;
            $this->info("Found Order {$displayCode} (ID: {$order->id}, Status: {$order->status})");

            $address = OrderAddress::where('order_id', $order->id)->first();
            $recipientEmail = $emailOverride ?: ($address ? trim($address->email) : null);
            $recipientName  = $address ? trim($address->name) : 'Valued Customer';

            if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $this->error("No valid recipient email available. Use --email=your@email.com to specify one.");
                Log::warning("[ReviewReminderCron] Order {$displayCode} has no valid recipient email.");
                return 1;
            }

            $orderProducts = OrderProduct::where('order_id', $order->id)->get();
            if ($orderProducts->isEmpty()) {
                $this->error("Order {$displayCode} has no products associated.");
                Log::warning("[ReviewReminderCron] Order {$displayCode} has no products associated.");
                return 1;
            }

            $reviewedProductIds = ProductReview::where('order_id', $order->id)
                ->pluck('product_id')
                ->map(fn($id) => (int)$id)
                ->toArray();

            $unreviewedProducts = $orderProducts->filter(function ($item) use ($reviewedProductIds) {
                return !in_array((int)$item->product_id, $reviewedProductIds);
            });

            if ($unreviewedProducts->isEmpty() && !$isForced) {
                $this->warn("All products in Order {$displayCode} have already been reviewed. Use --force to send anyway.");
                Log::info("[ReviewReminderCron] All products in Order {$displayCode} have already been reviewed. Skipped.");
                return 0;
            }

            $productsToSend = $unreviewedProducts->isNotEmpty() ? $unreviewedProducts : $orderProducts;

            // Generate secure HMAC link
            $token = base64_encode($order->code);
            $signature = hash_hmac('sha256', $order->code, config('app.key'));
            $reviewUrl = $this->buildReviewUrl($order, $token, $signature);

            $this->line("Recipient: {$recipientEmail} ({$recipientName})");
            $this->line("Products: {$productsToSend->count()} item(s)");
            $this->line("Review Landing URL: {$reviewUrl}");

            if ($isDryRun) {
                $this->warn("[DRY-RUN] Test email prepared. Not sending.");
                Log::info("[ReviewReminderCron] [DRY-RUN] Test email prepared for {$recipientEmail} on Order {$displayCode}.");
                return 0;
            }

            $this->info("Sending email via SMTP...");
            $sent = $this->sendReviewEmail($recipientEmail, $recipientName, $order, $productsToSend, $reviewUrl);

            if ($sent) {
                $this->info("SUCCESS: Test email successfully sent to {$recipientEmail}!");
                Log::info("[ReviewReminderCron] Single order test email successfully sent to {$recipientEmail} for Order {$displayCode}.");
                if (!$isForced && empty($emailOverride)) {
                    $order->review_request_sent_at = Carbon::now();
                    $order->save();
                }
            } else {
                $this->error("FAILED: Could not send email. Check Laravel logs for SMTP error details.");
                Log::error("[ReviewReminderCron] Single order test email failed sending to {$recipientEmail} for Order {$displayCode}.");
                return 1;
            }

            return 0;
        }

        $startWindow = Carbon::now()->subDays(20)->startOfDay();
        $endWindow   = Carbon::now()->subDays(7)->endOfDay();
        $launchDate  = config('app.review_launch_date', '2026-09-01');

        $this->info("Starting review reminder dispatch...");
        $this->info("Eligibility Window: {$startWindow->toDateTimeString()} to {$endWindow->toDateTimeString()}");
        $this->info("Launch Date Barrier: {$launchDate}");
        if ($isDryRun) {
            $this->warn("RUNNING IN DRY-RUN MODE (No emails will be sent, no records updated)");
        }

        Log::info('[ReviewReminderCron] Batch scan started', [
            'window_start' => $startWindow->toDateTimeString(),
            'window_end'   => $endWindow->toDateTimeString(),
            'launch_date'  => $launchDate,
            'is_dry_run'   => (bool) $isDryRun,
        ]);

        $query = Order::whereIn('status', ['delivered', 'completed'])
            ->whereBetween('updated_at', [$startWindow, $endWindow])
            ->where('created_at', '>=', $launchDate)
            ->whereNull('review_request_sent_at')
            ->orderBy('id', 'asc');

        $totalEligible = $query->count();
        $this->info("Found {$totalEligible} potential eligible orders.");
        Log::info("[ReviewReminderCron] Found {$totalEligible} potential eligible orders in delivery window.");

        if ($totalEligible === 0) {
            $this->info("No eligible orders to process.");
            Log::info('[ReviewReminderCron] No eligible orders to process. Run completed.');
            return 0;
        }

        $sentCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        // Process in chunks of 50 to avoid memory bloat and long-lived uncommitted transactions
        $query->chunk(50, function ($orders) use ($isDryRun, &$sentCount, &$skippedCount, &$failedCount) {
            foreach ($orders as $order) {
                try {
                    // Fetch order address for recipient details
                    $address = OrderAddress::where('order_id', $order->id)->first();
                    $recipientEmail = $address ? trim($address->email) : null;
                    $recipientName  = $address ? trim($address->name) : 'Valued Customer';

                    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                        $this->warn("Order #{$order->code}: No valid email address found. Skipping.");
                        Log::warning("[ReviewReminderCron] Order #{$order->code}: No valid email address found. Skipping.");
                        $skippedCount++;
                        continue;
                    }

                    // Fetch products for this order
                    $orderProducts = OrderProduct::where('order_id', $order->id)->get();
                    if ($orderProducts->isEmpty()) {
                        $this->warn("Order #{$order->code}: No products found. Skipping.");
                        Log::warning("[ReviewReminderCron] Order #{$order->code}: No products found. Skipping.");
                        $skippedCount++;
                        continue;
                    }

                    // Fetch product IDs already reviewed for this order
                    $reviewedProductIds = ProductReview::where('order_id', $order->id)
                        ->pluck('product_id')
                        ->map(fn($id) => (int)$id)
                        ->toArray();

                    // Edge Case 2: Filter out already reviewed products (Partial reviews handling)
                    $unreviewedProducts = $orderProducts->filter(function ($item) use ($reviewedProductIds) {
                        return !in_array((int)$item->product_id, $reviewedProductIds);
                    });

                    // Edge Case 1: Customer already reviewed all products in this order
                    if ($unreviewedProducts->isEmpty()) {
                        $this->info("Order #{$order->code}: All products already reviewed. Marking as sent and skipping.");
                        Log::info("[ReviewReminderCron] Order #{$order->code}: All products already reviewed. Marking as sent and skipping.");
                        if (!$isDryRun) {
                            $order->review_request_sent_at = Carbon::now();
                            $order->save();
                        }
                        $skippedCount++;
                        continue;
                    }

                    // Generate secure, tamper-proof landing link (Edge Case 4)
                    $token = base64_encode($order->code);
                    $signature = hash_hmac('sha256', $order->code, config('app.key'));
                    $reviewUrl = $this->buildReviewUrl($order, $token, $signature);

                    $displayCode = str_starts_with($order->code, '#') ? $order->code : '#' . $order->code;

                    if ($isDryRun) {
                        $this->line("[DRY-RUN] Would send email to: {$recipientEmail} for Order {$displayCode} ({$unreviewedProducts->count()} unreviewed items)");
                        Log::info("[ReviewReminderCron] [DRY-RUN] Would send email to: {$recipientEmail} for Order {$displayCode}");
                        $sentCount++;
                        continue;
                    }

                    // Send Email via PHPMailer
                    $sent = $this->sendReviewEmail($recipientEmail, $recipientName, $order, $unreviewedProducts, $reviewUrl);

                    if ($sent) {
                        $order->review_request_sent_at = Carbon::now();
                        $order->save();
                        $sentCount++;
                        $this->info("Sent review reminder for Order #{$order->code} to {$recipientEmail}");
                        Log::info("[ReviewReminderCron] Sent review reminder for Order #{$order->code} to {$recipientEmail}");
                    } else {
                        $failedCount++;
                        $this->error("Failed to send review reminder for Order #{$order->code}");
                        Log::error("[ReviewReminderCron] Failed to send review reminder for Order #{$order->code} to {$recipientEmail}");
                    }

                    // Edge Case 3: Throttle rate limits (150ms pause between transmissions)
                    usleep(150000);

                } catch (\Exception $e) {
                    $failedCount++;
                    $this->error("Error processing Order #{$order->code}: {$e->getMessage()}");
                    Log::error("Review Reminder Cron Error for Order #{$order->code}: " . $e->getMessage(), [
                        'order_id' => $order->id,
                        'exception' => $e,
                    ]);
                }
            }
        });

        $this->info("Review reminder run completed.");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Sent', $sentCount],
                ['Skipped / Already Reviewed', $skippedCount],
                ['Failed', $failedCount],
            ]
        );

        Log::info('[ReviewReminderCron] Batch scan completed', [
            'total_sent'    => $sentCount,
            'total_skipped' => $skippedCount,
            'total_failed'  => $failedCount,
        ]);

        return 0;
    }

    /**
     * Send review invitation email using PHPMailer.
     */
    protected function sendReviewEmail(string $recipientEmail, string $recipientName, Order $order, $unreviewedProducts, string $reviewUrl): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host       = env('MAIL_HOST');
            $mail->SMTPAuth   = true;
            $mail->Username   = env('MAIL_USERNAME');
            $mail->Password   = env('MAIL_PASSWORD');
            $mail->SMTPSecure = env('MAIL_ENCRYPTION');
            $mail->Port       = env('MAIL_PORT');

            $mail->setFrom(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME', 'Ahmed Al Maghribi Perfumes'));
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = "How is your recent fragrance experience? (Order #{$order->code})";

            // Build minimal, high-end monochrome HTML body
            $mail->Body = $this->buildEmailHtml($recipientName, $order, $unreviewedProducts, $reviewUrl);

            return $mail->send();
        } catch (Exception $e) {
            Log::error("PHPMailer error sending review reminder to {$recipientEmail}: " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Build secure review landing URL without duplicate locale prefixes.
     */
    protected function buildReviewUrl(Order $order, string $token, string $signature): string
    {
        $frontendBase = rtrim(env('FRONTEND_URL') ?: (env('CUSTOM_URL') ?: 'https://ae.ahmedalmaghribi.com'), '/');
        // Strip trailing /en or /ar if already present in base URL to avoid /en/en/
        $frontendBase = preg_replace('#/(en|ar)$#i', '', $frontendBase);
        return "{$frontendBase}/en/review-order?q=" . urlencode($token) . "&s=" . urlencode($signature);
    }

    /**
     * Minimalist, luxury monochrome email layout.
     * Adheres strictly to:
     * - No flashy colors (monochrome palette: #111827, #6B7280, #E5E7EB, #F9FAFB)
     * - Zero emojis or icons on text/buttons
     */
    protected function buildEmailHtml(string $recipientName, Order $order, $unreviewedProducts, string $reviewUrl): string
    {
        $safeName = htmlspecialchars($recipientName);
        $formattedOrderCode = str_starts_with($order->code, '#') ? $order->code : '#' . $order->code;
        $orderCode = htmlspecialchars($formattedOrderCode);

        // Product items rows
        $productRows = '';
        foreach ($unreviewedProducts as $item) {
            $productTitle = htmlspecialchars($item->product_name);
            $imgSrc = $item->product_image ? (str_starts_with($item->product_image, 'http') ? $item->product_image : 'https://admin.ahmedalmaghribi.com/storage/' . ltrim($item->product_image, '/')) : 'https://admin.ahmedalmaghribi.com/public/storage/ahmedlogo.png';

            $productRows .= '
            <tr>
                <td style="padding: 14px 0; border-bottom: 1px solid #F3F4F6;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td width="64" valign="middle" style="padding-right: 16px;">
                                <img src="' . htmlspecialchars($imgSrc) . '" width="56" height="56" alt="' . $productTitle . '" style="border-radius: 4px; object-fit: cover; border: 1px solid #E5E7EB; display: block;">
                            </td>
                            <td valign="middle">
                                <p style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; font-weight: 600; color: #111827; margin: 0 0 4px 0; line-height: 1.4;">
                                    ' . $productTitle . '
                                </p>
                                <p style="font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #6B7280; margin: 0;">
                                    Delivered &bull; Ready for your thoughts
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>';
        }

        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Your Fragrance</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F9FAFB; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #F9FAFB; padding: 40px 15px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 580px; background-color: #FFFFFF; border: 1px solid #E5E7EB; border-radius: 6px; overflow: hidden;">
                    
                    <!-- Header / Logo -->
                    <tr>
                        <td align="center" style="padding: 36px 30px 24px 30px; border-bottom: 1px solid #F3F4F6;">
                            <img src="https://admin.ahmedalmaghribi.com/public/storage/ahmedlogo.png" width="50" alt="Ahmed Al Maghribi Perfumes" style="display: block; outline: none; border: none;">
                            <p style="font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: #9CA3AF; margin: 12px 0 0 0; font-weight: 500;">
                                Ahmed Al Maghribi Perfumes
                            </p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 36px 36px 28px 36px;">
                            <h1 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0 0 16px 0; letter-spacing: -0.3px; line-height: 1.3;">
                                Share Your Experience
                            </h1>
                            <p style="font-size: 14px; color: #4B5563; line-height: 1.6; margin: 0 0 12px 0;">
                                Dear {$safeName},
                            </p>
                            <p style="font-size: 14px; color: #4B5563; line-height: 1.6; margin: 0 0 24px 0;">
                                We hope you are enjoying the distinct scents of your recent order (<strong>{$orderCode}</strong>). Your candid feedback provides invaluable insight to fellow fragrance enthusiasts and helps us continually refine our art.
                            </p>

                            <!-- Items Section -->
                            <div style="background-color: #FAFAFA; border: 1px solid #F3F4F6; border-radius: 4px; padding: 12px 18px; margin-bottom: 30px;">
                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                    {$productRows}
                                </table>
                            </div>

                            <!-- Single Clear Action Button (No icons, no flashy colors) -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td align="center">
                                        <a href="{$reviewUrl}" target="_blank" style="background-color: #111827; color: #FFFFFF; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; text-decoration: none; padding: 14px 34px; border-radius: 4px; display: inline-block;">
                                            Review Your Order
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 12px; color: #9CA3AF; text-align: center; margin: 0; line-height: 1.5;">
                                This direct link takes less than a minute. No sign-in required.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 30px; background-color: #F9FAFB; border-top: 1px solid #F3F4F6; text-align: center;">
                            <p style="font-size: 11px; color: #9CA3AF; margin: 0 0 6px 0; line-height: 1.5;">
                                &copy; {$year} Ahmed Al Maghribi Perfumes. All rights reserved.
                            </p>
                            <p style="font-size: 11px; color: #9CA3AF; margin: 0; line-height: 1.5;">
                                If you did not make this purchase or believe this was sent in error, please disregard this email.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}

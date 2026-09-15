<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\OrderAddress;
use App\Models\ProductReview;
use App\Services\LinkextWhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SendWhatsAppReviewReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reviews:send-whatsapp-reminders
                            {--order= : Send reminder for a specific order ID or code}
                            {--phone= : Override recipient phone number for testing}
                            {--force : Force send bypassing time window and already-sent check}
                            {--dry-run : Simulate execution without sending WhatsApp messages}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send follow-up review reminders via WhatsApp to UAE customers 3+ days after the email reminder (Smart Escalation)';

    /**
     * Execute the console command.
     */
    public function handle(LinkextWhatsAppService $whatsAppService)
    {
        $isDryRun      = $this->option('dry-run');
        $orderOption   = $this->option('order');
        $phoneOverride = $this->option('phone');
        $isForced      = $this->option('force');

        Log::info('[WhatsAppReviewReminderCron] Command execution started', [
            'is_dry_run'     => (bool) $isDryRun,
            'order_filter'   => $orderOption,
            'phone_override' => $phoneOverride,
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
                Log::warning("[WhatsAppReviewReminderCron] Single order lookup failed: '{$orderOption}' not found.");
                return 1;
            }

            $displayCode = str_starts_with($order->code, '#') ? $order->code : '#' . $order->code;
            $this->info("Found Order {$displayCode} (ID: {$order->id}, Status: {$order->status})");

            $address = OrderAddress::where('order_id', $order->id)->first();
            if ($address && !empty($address->country) && !in_array(strtoupper(trim($address->country)), ['AE', 'ARE', 'UNITED ARAB EMIRATES'])) {
                $this->error("Order {$displayCode} destination is not UAE (Country: {$address->country}). WhatsApp review reminders are only supported for UAE.");
                Log::warning("[WhatsAppReviewReminderCron] Order {$displayCode} is not UAE destination: {$address->country}");
                return 1;
            }

            $recipientPhone = $phoneOverride ?: ($address ? trim($address->phone) : null);
            $recipientName  = $address ? trim($address->name) : 'Valued Customer / عميلنا العزيز';

            $formattedPhone = $whatsAppService->formatPhoneNumber($recipientPhone, 'AE');
            if (empty($formattedPhone)) {
                $this->error("No valid UAE mobile number (+9715...) available for Order {$displayCode}. Use --phone=97150XXXXXXX to specify one.");
                Log::warning("[WhatsAppReviewReminderCron] Order {$displayCode} has no valid UAE mobile number. Raw: '{$recipientPhone}'");
                return 1;
            }

            $orderProducts = OrderProduct::where('order_id', $order->id)->get();
            if ($orderProducts->isEmpty()) {
                $this->error("Order {$displayCode} has no products associated.");
                Log::warning("[WhatsAppReviewReminderCron] Order {$displayCode} has no products associated.");
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
                Log::info("[WhatsAppReviewReminderCron] All products in Order {$displayCode} already reviewed. Skipped.");
                return 0;
            }

            // Option B (Smart Escalation): WhatsApp is a follow-up only after email reminder
            $minDelayDays = (int) env('WHATSAPP_REVIEW_DELAY_DAYS', 3);
            if (empty($order->review_request_sent_at) && !$isForced) {
                $this->warn("Order {$displayCode}: Email review reminder has not been sent yet. Under Option B (Smart Escalation), WhatsApp is sent as a follow-up after the email reminder. Use --force to bypass.");
                Log::info("[WhatsAppReviewReminderCron] Order {$displayCode}: Email reminder not yet sent. Skipped.");
                return 0;
            }

            if (!empty($order->review_request_sent_at) && !$isForced) {
                $emailSentDate  = Carbon::parse($order->review_request_sent_at);
                $daysSinceEmail = $emailSentDate->diffInDays(Carbon::now());
                if ($daysSinceEmail < $minDelayDays) {
                    $this->warn("Order {$displayCode}: Email reminder was sent on {$order->review_request_sent_at} ({$daysSinceEmail} day(s) ago, minimum {$minDelayDays} days required for follow-up). Use --force to bypass.");
                    Log::info("[WhatsAppReviewReminderCron] Order {$displayCode}: Only {$daysSinceEmail} days since email (minimum {$minDelayDays}). Skipped.");
                    return 0;
                }
            }

            // Generate secure HMAC link
            $token = base64_encode($order->code);
            $signature = hash_hmac('sha256', $order->code, config('app.key'));
            $reviewUrl = $this->buildReviewUrl($order, $token, $signature);

            $this->line("Recipient: {$formattedPhone} ({$recipientName})");
            $this->line("Products: {$orderProducts->count()} item(s) total ({$unreviewedProducts->count()} unreviewed)");
            $this->line("Review Landing URL: {$reviewUrl}");

            if ($isDryRun) {
                $this->warn("[DRY-RUN] Test WhatsApp message prepared. Not sending.");
                Log::info("[WhatsAppReviewReminderCron] [DRY-RUN] Test WhatsApp prepared for {$formattedPhone} on Order {$displayCode}.");
                return 0;
            }

            $this->info("Dispatching WhatsApp reminder via WABA...");
            $result = $whatsAppService->sendReviewInvitation($formattedPhone, $recipientName, $displayCode, $reviewUrl, 'AE');

            if ($result['success']) {
                $this->info("SUCCESS: WhatsApp reminder successfully sent to {$formattedPhone}!");
                Log::info("[WhatsAppReviewReminderCron] WhatsApp reminder successfully sent to {$formattedPhone} for Order {$displayCode}.");
                if (!$isForced && empty($phoneOverride)) {
                    $order->whatsapp_review_request_sent_at = Carbon::now();
                    $order->save();
                }
            } else {
                $this->error("FAILED: Could not send WhatsApp reminder. Error: " . ($result['message'] ?? 'Unknown error'));
                Log::error("[WhatsAppReviewReminderCron] WhatsApp reminder failed sending to {$formattedPhone} for Order {$displayCode}.", $result);
                return 1;
            }

            return 0;
        }

        $startWindow     = Carbon::now()->subDays(25)->startOfDay();
        $endWindow       = Carbon::now()->subDays(7)->endOfDay();
        $launchDate      = config('app.review_launch_date', '2026-09-01');
        $minDelayDays    = (int) env('WHATSAPP_REVIEW_DELAY_DAYS', 3);
        $emailSentBefore = Carbon::now()->subDays($minDelayDays)->endOfDay();

        $this->info("Starting WhatsApp review reminder dispatch (Smart Escalation Mode)...");
        $this->info("Delivery Window: {$startWindow->toDateTimeString()} to {$endWindow->toDateTimeString()}");
        $this->info("Email Follow-Up Delay: At least {$minDelayDays} days after email reminder (Sent on or before {$emailSentBefore->toDateTimeString()})");
        $this->info("Launch Date Barrier: {$launchDate}");
        if ($isDryRun) {
            $this->warn("RUNNING IN DRY-RUN MODE (No WhatsApp messages will be sent, no records updated)");
        }

        Log::info('[WhatsAppReviewReminderCron] Batch scan started (Smart Escalation)', [
            'window_start'      => $startWindow->toDateTimeString(),
            'window_end'        => $endWindow->toDateTimeString(),
            'min_delay_days'    => $minDelayDays,
            'email_sent_before' => $emailSentBefore->toDateTimeString(),
            'launch_date'       => $launchDate,
            'is_dry_run'        => (bool) $isDryRun,
        ]);

        $query = Order::whereIn('status', ['delivered', 'completed'])
            ->whereBetween('updated_at', [$startWindow, $endWindow])
            ->where('created_at', '>=', $launchDate)
            ->whereNotNull('review_request_sent_at') // Must have received email reminder first
            ->where('review_request_sent_at', '<=', $emailSentBefore) // At least 3 days elapsed since email
            ->whereNull('whatsapp_review_request_sent_at') // Has not received WhatsApp reminder yet
            ->orderBy('id', 'asc');

        $totalEligible = $query->count();
        $this->info("Found {$totalEligible} potential eligible orders.");
        Log::info("[WhatsAppReviewReminderCron] Found {$totalEligible} potential eligible orders in delivery window.");

        if ($totalEligible === 0) {
            $this->info("No eligible orders to process.");
            Log::info('[WhatsAppReviewReminderCron] No eligible orders to process. Run completed.');
            return 0;
        }

        $sentCount    = 0;
        $skippedCount = 0;
        $failedCount  = 0;

        $query->chunk(50, function ($orders) use ($whatsAppService, $isDryRun, &$sentCount, &$skippedCount, &$failedCount) {
            foreach ($orders as $order) {
                try {
                    $address = OrderAddress::where('order_id', $order->id)->first();

                    // Strictly UAE only
                    if ($address && !empty($address->country) && !in_array(strtoupper(trim($address->country)), ['AE', 'ARE', 'UNITED ARAB EMIRATES'])) {
                        $this->line("Order #{$order->code}: Skipped non-UAE destination ({$address->country}).");
                        Log::info("[WhatsAppReviewReminderCron] Order #{$order->code}: Skipped non-UAE destination ({$address->country}).");
                        $skippedCount++;
                        continue;
                    }

                    $recipientPhone = $address ? trim($address->phone) : null;
                    $recipientName  = $address ? trim($address->name) : 'Valued Customer / عميلنا العزيز';

                    $formattedPhone = $whatsAppService->formatPhoneNumber($recipientPhone, 'AE');
                    if (empty($formattedPhone)) {
                        $this->warn("Order #{$order->code}: Invalid or non-UAE phone number. Skipping.");
                        Log::warning("[WhatsAppReviewReminderCron] Order #{$order->code}: Invalid or non-UAE phone number. Skipping.");
                        $skippedCount++;
                        continue;
                    }

                    $orderProducts = OrderProduct::where('order_id', $order->id)->get();
                    if ($orderProducts->isEmpty()) {
                        $this->warn("Order #{$order->code}: No products found. Skipping.");
                        Log::warning("[WhatsAppReviewReminderCron] Order #{$order->code}: No products found. Skipping.");
                        $skippedCount++;
                        continue;
                    }

                    $reviewedProductIds = ProductReview::where('order_id', $order->id)
                        ->pluck('product_id')
                        ->map(fn($id) => (int)$id)
                        ->toArray();

                    $unreviewedProducts = $orderProducts->filter(function ($item) use ($reviewedProductIds) {
                        return !in_array((int)$item->product_id, $reviewedProductIds);
                    });

                    if ($unreviewedProducts->isEmpty()) {
                        $this->info("Order #{$order->code}: All products already reviewed. Marking as sent and skipping.");
                        Log::info("[WhatsAppReviewReminderCron] Order #{$order->code}: All products already reviewed. Marking as sent.");
                        if (!$isDryRun) {
                            $order->whatsapp_review_request_sent_at = Carbon::now();
                            $order->save();
                        }
                        $skippedCount++;
                        continue;
                    }

                    $token = base64_encode($order->code);
                    $signature = hash_hmac('sha256', $order->code, config('app.key'));
                    $reviewUrl = $this->buildReviewUrl($order, $token, $signature);
                    $displayCode = str_starts_with($order->code, '#') ? $order->code : '#' . $order->code;

                    if ($isDryRun) {
                        $this->line("[DRY-RUN] Would send WhatsApp reminder to {$formattedPhone} for Order {$displayCode}");
                        Log::info("[WhatsAppReviewReminderCron] [DRY-RUN] Would send WhatsApp to {$formattedPhone} for Order {$displayCode}");
                        $sentCount++;
                        continue;
                    }

                    $result = $whatsAppService->sendReviewInvitation($formattedPhone, $recipientName, $displayCode, $reviewUrl, 'AE');

                    if ($result['success']) {
                        $order->whatsapp_review_request_sent_at = Carbon::now();
                        $order->save();
                        $sentCount++;
                        $this->info("Sent WhatsApp review reminder for Order #{$order->code} to {$formattedPhone}");
                        Log::info("[WhatsAppReviewReminderCron] Sent WhatsApp reminder for Order #{$order->code} to {$formattedPhone}");
                    } else {
                        $failedCount++;
                        $this->error("Failed to send WhatsApp reminder for Order #{$order->code} to {$formattedPhone}");
                        Log::error("[WhatsAppReviewReminderCron] Failed sending WhatsApp for Order #{$order->code} to {$formattedPhone}", $result);
                    }

                    usleep(150000); // 150ms delay
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->error("Error processing WhatsApp for Order #{$order->code}: {$e->getMessage()}");
                    Log::error("WhatsApp Review Reminder Cron Error for Order #{$order->code}: " . $e->getMessage(), [
                        'order_id'  => $order->id,
                        'exception' => $e,
                    ]);
                }
            }
        });

        $this->info("WhatsApp review reminder run completed.");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Sent', $sentCount],
                ['Skipped / Already Reviewed', $skippedCount],
                ['Failed', $failedCount],
            ]
        );

        Log::info('[WhatsAppReviewReminderCron] Batch scan completed', [
            'total_sent'    => $sentCount,
            'total_skipped' => $skippedCount,
            'total_failed'  => $failedCount,
        ]);

        return 0;
    }

    /**
     * Build secure review landing URL without duplicate locale prefixes.
     */
    protected function buildReviewUrl(Order $order, string $token, string $signature): string
    {
        $frontendBase = rtrim(env('FRONTEND_URL') ?: (env('CUSTOM_URL') ?: 'https://ae.ahmedalmaghribi.com'), '/');
        $frontendBase = preg_replace('#/(en|ar)$#i', '', $frontendBase);
        $locale = (!empty($order->lang) && in_array(strtolower($order->lang), ['en', 'ar'])) ? strtolower($order->lang) : 'en';
        return "{$frontendBase}/{$locale}/review-order?q=" . urlencode($token) . "&s=" . urlencode($signature);
    }
}

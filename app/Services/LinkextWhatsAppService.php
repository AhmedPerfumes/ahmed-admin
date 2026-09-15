<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class LinkextWhatsAppService
{
    /**
     * Send post-purchase review invitation via WhatsApp (WABA / Linkext).
     *
     * @param string $rawPhone Customer contact number
     * @param string $customerName Customer display name
     * @param string $orderCode Order code (e.g. #10042415)
     * @param string $reviewUrl Direct HMAC signed review link
     * @param string $countryCode Country ISO code (default: AE)
     * @return array Result array with success flag, formatted phone, and provider response
     */
    public function sendReviewInvitation(string $rawPhone, string $customerName, string $orderCode, string $reviewUrl, string $countryCode = 'AE'): array
    {
        $phone = $this->formatPhoneNumber($rawPhone, 'AE');
        if (!$phone) {
            Log::warning("[LinkextWhatsApp] Invalid or non-UAE phone number provided for Order {$orderCode}: {$rawPhone}");
            return [
                'success' => false,
                'message' => 'WhatsApp review reminders are only supported for UAE mobile numbers (+9715...).',
                'phone'   => $rawPhone,
            ];
        }

        // Clean customer names for English and Arabic sections
        $name = trim($customerName);
        $isGeneric = empty($name) || in_array(strtolower($name), ['valued customer', 'customer', 'customer name', 'عميلنا العزيز']);

        $nameEn = $isGeneric ? 'Valued Customer' : $name;
        $nameAr = $isGeneric ? 'عميلنا العزيز' : $name;

        $gateway = strtolower(env('WABA_GATEWAY', 'myinboxmedia'));
        $displayCode = str_starts_with($orderCode, '#') ? $orderCode : '#' . $orderCode;

        // Extract dynamic URL suffix (?q=...&s=...) for button template: https://ae.ahmedalmaghribi.com/en/review-order{{1}}
        $urlParts = parse_url($reviewUrl);
        $urlSuffix = !empty($urlParts['query']) ? ('?' . $urlParts['query']) : $reviewUrl;

        if ($gateway === 'linkext') {
            return $this->sendViaLinkext($phone, $nameEn, $nameAr, $displayCode, $urlSuffix);
        }

        // Default: MyInboxMedia WABA (standard provider across ahmed-admin)
        return $this->sendViaMyInboxMedia($phone, $nameEn, $nameAr, $displayCode, $urlSuffix);
    }

    /**
     * Format recipient phone number into E.164 without leading plus for UAE (+971).
     * Strictly validates UAE mobile numbers (9715XXXXXXXX, 05XXXXXXXX, 5XXXXXXXX).
     * Any non-UAE or invalid numbers return null.
     */
    public function formatPhoneNumber(?string $phone, string $countryCode = 'AE'): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Remove all non-digits
        $cleaned = preg_replace('/\D/', '', $phone);
        if (empty($cleaned)) {
            return null;
        }

        // Remove leading double zeros (00971... -> 971...)
        $cleaned = preg_replace('/^00/', '', $cleaned);

        // Case 1: Already starts with 971
        if (str_starts_with($cleaned, '971')) {
            $national = substr($cleaned, 3);
            // UAE mobile starts with 5 and is 9 digits (e.g. 50XXXXXXX -> total 12 digits: 9715XXXXXXXX)
            if (strlen($national) === 9 && str_starts_with($national, '5')) {
                return $cleaned;
            }
            // Allow 8 digits if older 8-digit mobile format (total 11 digits)
            if (strlen($national) === 8 && str_starts_with($national, '5')) {
                return $cleaned;
            }
            return null;
        }

        // Case 2: Starts with 05 (10 digits total: 05XXXXXXXX)
        if (str_starts_with($cleaned, '05') && strlen($cleaned) === 10) {
            return '971' . substr($cleaned, 1);
        }

        // Case 3: Starts with 5 (9 digits total: 5XXXXXXXX)
        if (str_starts_with($cleaned, '5') && strlen($cleaned) === 9) {
            return '971' . $cleaned;
        }

        return null;
    }

    /**
     * Dispatch WhatsApp message using MyInboxMedia WABA gateway.
     */
    protected function sendViaMyInboxMedia(string $phone, string $nameEn, string $nameAr, string $orderCode, string $urlSuffix): array
    {
        $apiUrl       = env('WABA_URL', 'https://waba.myinboxmedia.in/api/sendwaba');
        $profileId    = env('WABA_PROFILE_ID', 'MIM2400074');
        $apiKey       = env('WABA_API_KEY', '#JpXt4fbMCFj');
        $templateName = env('WABA_REVIEW_TEMPLATE', 'review_order_invitation');

        $payload = [
            'ProfileId'              => $profileId,
            'APIKey'                 => $apiKey,
            'MobileNumber'           => (string)$phone,
            'templateName'           => $templateName,
            'Parameters'             => [
                (string)$nameEn,    // {{1}}
                (string)$orderCode, // {{2}}
                (string)$nameAr,    // {{3}}
                (string)$orderCode, // {{4}}
            ],
            'HeaderType'             => 'Text',
            'Text'                   => '',
            'MediaUrl'               => '',
            'Latitude'               => 0,
            'Longitude'              => 0,
            'isTemplate'             => 'true',
            'ButtonOrListJSON'       => '',
            'SubClientCode'          => '',
            'HeaderParameter'        => '',
            'CTAButtonURLParameter'  => (string)$urlSuffix, // Replaces {{1}} in https://ae.ahmedalmaghribi.com/en/review-order{{1}}
            'CTAButtonURLParameter2' => '',
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($apiUrl, $payload);

            $body = $response->body();
            $statusCode = $response->status();

            if ($response->successful()) {
                Log::info("[LinkextWhatsApp] WABA review invitation sent for Order {$orderCode} to {$phone}", [
                    'response' => $body,
                ]);
                return [
                    'success'  => true,
                    'gateway'  => 'myinboxmedia',
                    'phone'    => $phone,
                    'response' => $body,
                ];
            }

            Log::error("[LinkextWhatsApp] WABA dispatch returned status {$statusCode} for Order {$orderCode}", [
                'body' => $body,
            ]);

            return [
                'success'  => false,
                'gateway'  => 'myinboxmedia',
                'phone'    => $phone,
                'message'  => "HTTP {$statusCode}: {$body}",
            ];
        } catch (\Exception $e) {
            Log::error("[LinkextWhatsApp] WABA dispatch exception for Order {$orderCode}: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'gateway' => 'myinboxmedia',
                'phone'   => $phone,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Dispatch WhatsApp message using Linkext API.
     */
    protected function sendViaLinkext(string $phone, string $nameEn, string $nameAr, string $orderCode, string $urlSuffix): array
    {
        $apiUrl       = env('LINKEXT_API_URL', 'https://api.linkext.com/v1/messages');
        $apiKey       = env('LINKEXT_API_KEY', '');
        $senderNumber = env('LINKEXT_SENDER_NUMBER', '');
        $templateName = env('LINKEXT_REVIEW_TEMPLATE', 'review_order_invitation');

        $payload = [
            'to'       => '+' . ltrim($phone, '+'),
            'from'     => $senderNumber,
            'type'     => 'template',
            'template' => [
                'name'     => $templateName,
                'language' => ['code' => 'en'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nameEn],    // {{1}}
                            ['type' => 'text', 'text' => $orderCode], // {{2}}
                            ['type' => 'text', 'text' => $nameAr],    // {{3}}
                            ['type' => 'text', 'text' => $orderCode], // {{4}}
                        ],
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => [
                            ['type' => 'text', 'text' => $urlSuffix], // Replaces {{1}} on button
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(15)->post($apiUrl, $payload);

            $body = $response->body();
            $statusCode = $response->status();

            if ($response->successful()) {
                Log::info("[LinkextWhatsApp] Linkext review invitation sent for Order {$orderCode} to {$phone}", [
                    'response' => $body,
                ]);
                return [
                    'success'  => true,
                    'gateway'  => 'linkext',
                    'phone'    => $phone,
                    'response' => $body,
                ];
            }

            Log::error("[LinkextWhatsApp] Linkext dispatch returned status {$statusCode} for Order {$orderCode}", [
                'body' => $body,
            ]);

            return [
                'success'  => false,
                'gateway'  => 'linkext',
                'phone'    => $phone,
                'message'  => "HTTP {$statusCode}: {$body}",
            ];
        } catch (\Exception $e) {
            Log::error("[LinkextWhatsApp] Linkext dispatch exception for Order {$orderCode}: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'gateway' => 'linkext',
                'phone'   => $phone,
                'message' => $e->getMessage(),
            ];
        }
    }
}

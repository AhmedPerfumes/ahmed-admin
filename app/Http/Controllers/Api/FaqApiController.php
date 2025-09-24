<?php

namespace App\Http\Controllers\Api;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Faq\Models\Faq;
use Botble\Faq\Models\FaqCategory;

class FaqApiController extends BaseController
{
    // Return all categories with their FAQs
    public function categories()
    {
        $categories = FaqCategory::with(['faqs' => function ($q) {
            $q->where('status', 'published'); // only published faqs
        }])
        ->where('status', 'published') // only published categories
        ->orderBy('order', 'ASC')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    // Return all FAQs with their category
    public function faqs()
    {
        $faqs = Faq::with('category')
            ->where('status', 'published')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $faqs,
        ]);
    }
}

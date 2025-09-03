<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Ecommerce\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductReviewController extends Controller
{
    /**
     * This function gets all the reviews for ONE specific product.
     */
    public function index(Product $product)
    {
        $reviews = ProductReview::where('product_id', $product->id)
            // **THIS IS THE CHANGE**: Only get reviews with a 'published' status
            ->where('status', BaseStatusEnum::PUBLISHED)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reviews);
    }

    /**
     * This function saves a new review that comes from the React form.
     */
    public function store(Request $request)
    {
        // First, we check if the data sent to us is valid.
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:ec_products,id',
            'star' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:10',
            'customer_name' => 'required|string|max:100',
            'customer_email' => 'required|email|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Get the validated data from the validator.
        $validatedData = $validator->validated();

        // **THIS IS THE CHANGE**: We force the status to 'pending'.
        $reviewData = array_merge($validatedData, ['status' => 'pending']);

        // Create the new review with our modified data.
        $review = ProductReview::create($reviewData);

        // Send back a success message.
        return response()->json($review, 201);
    }
}
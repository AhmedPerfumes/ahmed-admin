<?php

namespace App\Http\Controllers;

use App\Models\ProductReview;
use App\Tables\ProductReviewTable;
use Botble\Base\Http\Responses\BaseHttpResponse;

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
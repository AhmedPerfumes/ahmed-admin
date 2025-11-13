<?php

namespace Botble\Ecommerce\Http\Controllers; // Adjust namespace

use Botble\Base\Http\Controllers\BaseController;
use Botble\Ecommerce\Models\PrebookingSubmission; // Import your Model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PrebookingApiController extends BaseController
{
    /**
     * Handle the POST request from the Prebooking Widget.
     */
    public function submit(Request $request)
    {
        try {
            // 1. Validation Rules
            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'email' => 'required|email',
                'interestedSeries' => 'required|array|min:1',
                'interestedSeries.*' => 'string|in:2000,2025,2050,full-set', // Validate against possible values
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            // 2. Data Preparation
            $data = [
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                // Laravel's casts='array' handles serialization of the array into JSON string
                'interested_series' => $request->input('interestedSeries'), 
                'ip_address' => $request->ip(),
            ];

            // 3. Save to Database
            PrebookingSubmission::create($data);

            // 4. Return Success Response
            return response()->json([
                'status' => 'success',
                'message' => 'Thank you for your interest! Your pre-booking has been recorded.',
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }
}
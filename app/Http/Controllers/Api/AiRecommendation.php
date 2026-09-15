<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class AiRecommendation extends Controller {

    public function syncAiRecommendations(Request $request) 
    {
        $productId = $request->input('id');

        if (!$productId) {
            return response()->json(['success' => false, 'message' => 'Product ID is missing.'], 400);
        }

        try {
            // 1. Call the Python API
            $response = Http::get("http://127.0.0.1:8000/recommend/{$productId}");

            if ($response->successful()) {
                $data = $response->json();
                $recommendedIds = $data['recommendations'];

                // Handle cases where the model finds 0 recommendations (e.g., due to your new filters)
                if (empty($recommendedIds)) {
                    return response()->json([
                        'success' => true, 
                        'message' => 'AI sync completed, but no similar products were found based on the current filters.'
                    ]);
                }

                // 2. Database Transaction for safety
                DB::transaction(function () use ($productId, $recommendedIds) {
                    DB::table('ec_product_related_relations')->where('from_product_id', $productId)->delete();

                    foreach ($recommendedIds as $id) {
                        DB::table('ec_product_related_relations')->insert([
                            'from_product_id' => $productId,
                            'to_product_id'   => $id
                        ]);
                    }
                });

                $count = count($recommendedIds);
                return response()->json([
                    'success' => true, 
                    'message' => "Successfully synced {$count} AI-recommended products."
                ]);
            }

            return response()->json([
                'success' => false, 
                'message' => 'Failed to connect to the Recommendation Engine. Ensure the Python server is running.'
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

}
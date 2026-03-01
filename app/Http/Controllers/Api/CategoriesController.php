<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CategoriesController extends Controller
{
    /**
     * Get all categories
     * GET /api/categories
     */
    public function getAllCategories(Request $request)
    {
        try {
            // Get all categories ordered by name
            $categories = Category::orderBy('name', 'asc')
                ->get()
                ->map(function ($category) {
                    return [
                        'category_id' => $category->category_id,
                        'name' => $category->name,
                        'description' => $category->description,
                    ];
                });

            return response()->json([
                'message' => 'Categories retrieved successfully',
                'data' => $categories,
                'count' => $categories->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting categories', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve categories',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single category by ID
     * GET /api/categories/{category_id}
     */
    public function getCategoryById(Request $request, $categoryId)
    {
        try {
            $category = Category::find($categoryId);

            if (!$category) {
                return response()->json([
                    'message' => 'Category not found',
                ], 404);
            }

            return response()->json([
                'message' => 'Category retrieved successfully',
                'data' => [
                    'category_id' => $category->category_id,
                    'name' => $category->name,
                    'description' => $category->description,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting category', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve category',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

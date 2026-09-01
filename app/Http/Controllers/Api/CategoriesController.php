<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
            // The count travels with the row so the admin screens can say
            // what a category holds - and why one cannot be deleted yet.
            $categories = Category::withCount('items')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($category) {
                    return [
                        'category_id' => $category->category_id,
                        'name' => $category->name,
                        'description' => $category->description,
                        'item_count' => $category->items_count,
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

    /**
     * Create a category.
     * POST /api/admin/categories
     *
     * Names are unique because they are how a category is recognised in every
     * picker in both apps; two "Books" would be indistinguishable there.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $category = Category::create([
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Category created successfully',
            'data' => self::present($category),
        ], 201);
    }

    /**
     * Rename a category or reword its description.
     * PUT /api/admin/categories/{category_id}
     */
    public function update(Request $request, $categoryId)
    {
        $category = Category::find($categoryId);

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'name')->ignore($category->category_id, 'category_id'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $category->update([
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => self::present($category->fresh()),
        ], 200);
    }

    /**
     * Delete a category.
     * DELETE /api/admin/categories/{category_id}
     *
     * Refused while items still point at it: deleting would either orphan those
     * listings or silently drop them out of every category filter.
     */
    public function destroy(Request $request, $categoryId)
    {
        $category = Category::withCount('items')->find($categoryId);

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        if ($category->items_count > 0) {
            return response()->json([
                'message' => "This category still holds {$category->items_count} item(s). "
                    . 'Move them to another category first.',
            ], 409);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully'], 200);
    }

    /** One shape for a category, wherever it is returned. */
    private static function present(Category $category): array
    {
        return [
            'category_id' => $category->category_id,
            'name' => $category->name,
            'description' => $category->description,
            'item_count' => $category->items()->count(),
        ];
    }
}

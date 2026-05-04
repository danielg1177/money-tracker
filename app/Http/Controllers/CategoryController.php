<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        $family = auth()->user()->family;

        if (! $family) {
            return [];
        }

        return $family->categories;
    }

    public function store(StoreCategoryRequest $request)
    {
        $validated = $request->validated();
        $validated['family_id'] = auth()->user()->family_id;

        return Category::create($validated);
    }

    public function update(StoreCategoryRequest $request, Category $category)
    {
        if ($category->family_id !== auth()->user()->family_id) {
            abort(403);
        }

        $category->update($request->validated());

        return $category;
    }

    public function destroy(Category $category)
    {
        if ($category->family_id !== auth()->user()->family_id) {
            abort(403);
        }

        $category->delete();

        return response()->noContent();
    }
}

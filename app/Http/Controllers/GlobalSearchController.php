<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GlobalSearchController extends Controller
{
    private const CATEGORIES = ['all', 'people', 'messages', 'files', 'classes', 'meetings', 'assignments', 'announcements'];

    public function __invoke(Request $request, GlobalSearchService $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'category' => ['nullable', 'string', Rule::in(self::CATEGORIES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);
        $category = $data['category'] ?? 'all';
        $limit = min((int) ($data['limit'] ?? ($category === 'all' ? 5 : 25)), $category === 'all' ? 5 : 25);

        return response()->json(['results' => $search->search($request->user(), trim($data['q']), $category, $limit)]);
    }
}

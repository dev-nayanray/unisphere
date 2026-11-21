<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared list behaviour for admin tables:
 *   ?search=cse          searches the given columns
 *   ?sort=code&dir=desc  sorts by an allowed column
 *   ?page=2&per_page=20  paginates (omit both to get a plain array, e.g. for dropdowns)
 */
trait ListsRecords
{
    protected function listing(
        Request $request,
        Builder $query,
        array $searchable = [],
        array $sortable = [],
        string $defaultSort = 'id',
    ): JsonResponse {
        if ($request->filled('search') && $searchable) {
            $term = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($q) use ($searchable, $term) {
                foreach ($searchable as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        $sort = in_array($request->input('sort'), $sortable, true) ? $request->input('sort') : $defaultSort;
        $query->orderBy($sort, $request->input('dir') === 'desc' ? 'desc' : 'asc');

        if ($request->hasAny(['page', 'per_page'])) {
            $perPage = min(200, max(5, $request->integer('per_page', 20)));

            return response()->json($query->paginate($perPage)->withQueryString());
        }

        return response()->json($query->get());
    }
}

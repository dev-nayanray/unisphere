<?php

namespace App\Http\Controllers;

use App\Models\CourseSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization lives in app/Policies. Controllers call Gate::authorize();
 * these two helpers are shorthands for the classroom checks used everywhere.
 */
abstract class Controller
{
    /** Teacher, enrolled students, the department head or an admin. */
    protected function ensureCanView(CourseSection $section): void
    {
        Gate::authorize('view', $section);
    }

    /** The class teacher or an admin. */
    protected function ensureCanManage(CourseSection $section): void
    {
        Gate::authorize('manage', $section);
    }

    /**
     * Delete a record, turning a foreign-key failure (the record is still
     * used elsewhere) into a clear 422 instead of a 500.
     */
    protected function deleteOrExplain(Model $model, string $label): JsonResponse
    {
        try {
            $model->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => "This {$label} is still in use and cannot be deleted. Remove or move the records that use it first.",
            ], 422);
        }

        return response()->json(['message' => ucfirst($label).' deleted.']);
    }
}

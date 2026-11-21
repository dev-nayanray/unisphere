<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Material;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    public function index(CourseSection $section): JsonResponse
    {
        return response()->json(
            $section->materials()->with('author:id,name')->latest()->get()
        );
    }

    public function store(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'topic' => ['nullable', 'string', 'max:80'],
            'type' => ['required', 'in:file,link,video'],
            'url' => ['required_unless:type,file', 'nullable', 'url'],
            'file' => ['required_if:type,file', 'nullable', 'file', 'max:20480'], // 20 MB
        ]);

        if ($request->hasFile('file')) {
            $data['file_path'] = $request->file('file')->store("classes/{$section->id}/materials", 'public');
        }
        unset($data['file']);

        $material = $section->materials()->create([...$data, 'author_id' => $request->user()->id]);
        Post::announceClasswork($section, $request->user(), 'material', $material->id, $material->title);

        return response()->json($material, 201);
    }

    public function destroy(Material $material): JsonResponse
    {
        Gate::authorize('delete', $material);

        if ($material->file_path) {
            Storage::disk('public')->delete($material->file_path);
        }
        $material->delete();

        return response()->json(['message' => 'Material deleted.']);
    }
}

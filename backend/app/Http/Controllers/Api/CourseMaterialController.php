<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseMaterialRequest;
use App\Models\Course;
use App\Models\CourseMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseMaterialController extends Controller
{
    /**
     * Display a listing of materials for a given course.
     */
    public function index(Request $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('view', $course)) {
            return response()->json([
                'message' => 'Unauthorized access to course materials.',
            ], 403);
        }

        $materials = $course->materials()->latest()->get();

        return response()->json([
            'data' => $materials,
        ]);
    }

    /**
     * Store a newly uploaded course material.
     */
    public function store(CourseMaterialRequest $request, Course $course): JsonResponse
    {
        if (!app(\App\Services\CourseAccessService::class)->can($request->user(), $course, 'upload_documents')) {
            return response()->json([
                'message' => 'Unauthorized access to course.',
            ], 403);
        }

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $fileSize = $file->getSize();

        // Store file securely in storage/app/materials
        $storedPath = $file->store('materials', 'local');

        $material = $course->materials()->create([
            'title' => trim($request->title),
            'description' => $request->description ? trim($request->description) : null,
            'file_name' => $originalName,
            'file_path' => $storedPath,
            'file_type' => $extension,
            'file_size' => $fileSize,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $material,
            'message' => 'Material uploaded successfully',
        ], 201);
    }

    /**
     * Safely download / stream an uploaded course material.
     */
    public function show(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        if (!app(\App\Services\CourseAccessService::class)->can($request->user(), $material->course, 'download_documents')) {
            return response()->json([
                'message' => 'Unauthorized access to course material.',
            ], 403);
        }

        if (! Storage::disk('local')->exists($material->file_path)) {
            return response()->json([
                'message' => 'Requested file was not found on server.',
            ], 404);
        }

        return Storage::disk('local')->download($material->file_path, $material->file_name);
    }

    /**
     * Remove the specified course material from storage.
     */
    public function destroy(Request $request, CourseMaterial $material): JsonResponse
    {
        if (!app(\App\Services\CourseAccessService::class)->can($request->user(), $material->course, 'manage_documents')) {
            return response()->json([
                'message' => 'Unauthorized access to course material.',
            ], 403);
        }

        // Delete physical file from disk
        if (Storage::disk('local')->exists($material->file_path)) {
            Storage::disk('local')->delete($material->file_path);
        }

        $material->delete();

        return response()->json([
            'message' => 'Material deleted successfully',
        ]);
    }
}


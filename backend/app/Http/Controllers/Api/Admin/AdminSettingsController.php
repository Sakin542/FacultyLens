<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\FeatureFlagService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function __construct(protected FeatureFlagService $features, protected AuditLogService $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'features' => $this->features->describe(),
                'google_configured' => trim((string) config('admin.google.client_id')) !== '',
                'history' => \App\Models\AuditLog::query()->with('user:id,name')->where('action', 'ADMIN_SETTINGS_UPDATED')->latest('created_at')->limit(30)->get()
                    ->map(fn ($l) => ['id' => $l->id, 'actor' => $l->user?->only(['id', 'name']), 'changed' => $l->metadata['changed'] ?? [], 'previous' => $l->metadata['previous'] ?? null, 'reason' => $l->metadata['reason'] ?? null, 'created_at' => $l->created_at?->toIso8601String()]),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (FeatureFlagService::DEFINITIONS as $key => $def) {
            $rules["features.{$key}"] = match ($def['type']) {
                'boolean' => ['sometimes', 'boolean'],
                'select' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(array_keys($def['options'] ?? []))],
                default => ['sometimes', 'nullable', 'string', 'max:500'],
            };
        }
        $validated = $request->validate(['features' => ['required', 'array'], 'reason' => ['nullable', 'string', 'max:500'], ...$rules]);

        $before = $this->features->all();
        $changed = $this->features->update((array) $validated['features'], $request->user());
        if ($changed !== []) {
            $this->audit->log('ADMIN_SETTINGS_UPDATED', 'SystemSetting', null, [
                'changed' => $changed,
                'previous' => array_intersect_key($before, $changed),
                'reason' => $validated['reason'] ?? null,
            ], $request->user());
        }

        return response()->json([
            'status' => 'success',
            'message' => $changed ? 'Settings saved.' : 'No changes.',
            'data' => ['features' => $this->features->describe()],
        ]);
    }
}

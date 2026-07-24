<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectService $projectService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Project::with('creator');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['name', 'status', 'due_date', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->input('per_page', 50), 100);
        $projects = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => ProjectResource::collection($projects),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:3|max:100',
            'description' => 'nullable|string|max:1000',
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:today',
        ]);

        $project = $this->projectService->createProject($validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Proyecto creado exitosamente.',
            'data' => new ProjectResource($project),
        ], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $project->load('creator');

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|min:3|max:100',
            'description' => 'sometimes|nullable|string|max:1000',
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'status' => ['sometimes', Rule::in(['Activo', 'Pausado', 'Completado', 'Archivado'])],
        ]);

        if (isset($validated['status'])) {
            $this->projectService->updateStatus($project, $validated['status']);
            unset($validated['status']);
        }

        if (!empty($validated)) {
            $project = $this->projectService->updateProject($project, $validated, $request->user());
        }

        return response()->json([
            'success' => true,
            'message' => 'Proyecto actualizado exitosamente.',
            'data' => new ProjectResource($project->fresh('creator')),
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->projectService->deleteProject($project);

        return response()->json([
            'success' => true,
            'message' => 'Proyecto eliminado exitosamente.',
        ]);
    }

    public function tasks(Request $request, Project $project): JsonResponse
    {
        $query = $project->tasks()->with(['assignee', 'creator']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['title', 'status', 'due_date', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $tasks = $query->get();

        return response()->json([
            'success' => true,
            'data' => TaskResource::collection($tasks),
        ]);
    }

    public function summary(): JsonResponse
    {
        $projects = Project::all();

        $summary = [
            'total' => $projects->count(),
            'active' => $projects->where('status', 'Activo')->count(),
            'paused' => $projects->where('status', 'Pausado')->count(),
            'completed' => $projects->where('status', 'Completado')->count(),
            'archived' => $projects->where('status', 'Archivado')->count(),
            'at_risk' => $projects->filter(fn ($p) => $p->health === 'at_risk')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }
}

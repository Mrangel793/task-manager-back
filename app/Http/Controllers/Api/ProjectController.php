<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Models\User;
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
        $user = $request->user();
        $query = Project::with('creator')->visibleTo($user);

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
            'visibility' => ['nullable', Rule::in(['todos', 'miembros'])],
        ]);

        $project = $this->projectService->createProject($validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Proyecto creado exitosamente.',
            'data' => new ProjectResource($project),
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        if (! $project->isVisibleTo($request->user())) {
            return response()->json(['success' => false, 'message' => 'No tienes acceso a este proyecto.'], 403);
        }

        $project->load('creator', 'members');

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        if (! $project->isVisibleTo($request->user())) {
            return response()->json(['success' => false, 'message' => 'No tienes acceso a este proyecto.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|min:3|max:100',
            'description' => 'sometimes|nullable|string|max:1000',
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'status' => ['sometimes', Rule::in(['Activo', 'Pausado', 'Completado', 'Archivado'])],
            'visibility' => ['sometimes', Rule::in(['todos', 'miembros'])],
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

    public function destroy(Request $request, Project $project): JsonResponse
    {
        if (! $project->isVisibleTo($request->user())) {
            return response()->json(['success' => false, 'message' => 'No tienes acceso a este proyecto.'], 403);
        }

        $this->projectService->deleteProject($project);

        return response()->json([
            'success' => true,
            'message' => 'Proyecto eliminado exitosamente.',
        ]);
    }

    public function tasks(Request $request, Project $project): JsonResponse
    {
        if (! $project->isVisibleTo($request->user())) {
            return response()->json(['success' => false, 'message' => 'No tienes acceso a este proyecto.'], 403);
        }

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

    public function summary(Request $request): JsonResponse
    {
        $projects = Project::visibleTo($request->user())->get();

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

    // -- Member management --

    public function listMembers(Request $request, Project $project): JsonResponse
    {
        if (! $project->isVisibleTo($request->user())) {
            return response()->json(['success' => false, 'message' => 'No tienes acceso a este proyecto.'], 403);
        }

        $members = $project->members()->get();

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($members),
        ]);
    }

    public function addMember(Request $request, Project $project): JsonResponse
    {
        if (! $project->canManageMembers($request->user())) {
            return response()->json(['success' => false, 'message' => 'Solo el creador del proyecto o un Admin puede gestionar miembros.'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        $user = User::findOrFail($validated['user_id']);

        // Creator doesn't need to be a member — they always have access
        if ((string) $user->id === (string) $project->created_by) {
            return response()->json(['success' => false, 'message' => 'El creador del proyecto siempre tiene acceso.'], 422);
        }

        $project->members()->syncWithoutDetaching([$user->id => ['added_at' => now()]]);

        return response()->json([
            'success' => true,
            'message' => "{$user->name} agregado al proyecto.",
        ]);
    }

    public function removeMember(Request $request, Project $project, User $user): JsonResponse
    {
        if (! $project->canManageMembers($request->user())) {
            return response()->json(['success' => false, 'message' => 'Solo el creador del proyecto o un Admin puede gestionar miembros.'], 403);
        }

        $project->members()->detach($user->id);

        return response()->json([
            'success' => true,
            'message' => "{$user->name} removido del proyecto.",
        ]);
    }
}

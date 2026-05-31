<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TaskCommentController extends Controller
{
    public function index(Task $task): JsonResponse
    {
        $comments = $task->comments()
            ->with('user:id,name,email')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ['comments' => $comments],
        ]);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'body'       => 'nullable|string|max:2000',
            'attachment' => 'nullable|file|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        if (!$request->filled('body') && !$request->hasFile('attachment')) {
            return response()->json([
                'success' => false,
                'message' => 'Debes escribir un mensaje o adjuntar una imagen.',
            ], 422);
        }

        $attachmentPath = null;

        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store(
                'task-comments/' . $task->id,
                'public'
            );
        }

        $comment = TaskComment::create([
            'organization_id' => $request->user()->organization_id,
            'task_id'         => $task->id,
            'user_id'         => $request->user()->id,
            'body'            => $request->input('body'),
            'attachment_path' => $attachmentPath,
        ]);

        $comment->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'data'    => ['comment' => $comment],
        ], 201);
    }

    public function destroy(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $user = $request->user();

        $canDelete = $comment->user_id === $user->id
            || in_array($user->role, ['Admin', 'Supervisor']);

        if (!$canDelete) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para eliminar este comentario.',
            ], 403);
        }

        if ($comment->attachment_path) {
            Storage::disk('public')->delete($comment->attachment_path);
        }

        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Comentario eliminado.',
        ]);
    }
}

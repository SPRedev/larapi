<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    //======================================================================
    // PRIVATE HELPER METHODS
    //======================================================================

    /**
     * A reusable helper to apply visibility rules to a task query.
     */
    private function applyTaskVisibilityScope($query, $userId, $accessSchema)
    {
        if (in_array('view', $accessSchema)) {
            // This user has global view access, so no filters are needed.
            return;
        } 
        
        if (in_array('view_assigned', $accessSchema)) {
            // This user can see tasks they created OR are assigned to.
            $query->where(function ($subQuery) use ($userId) {
                $subQuery->where('tasks.created_by', $userId)
                         ->orWhereRaw('FIND_IN_SET(?, tasks.field_171)', [$userId]);
            });
            return;
        }

        // If neither 'view' nor 'view_assigned' is present, they can see nothing.
        $query->whereRaw('1 = 0'); // This is a safe way to return no results
    }

    //======================================================================
    // PUBLIC API METHODS
    //======================================================================

    /**
     * Get a list of all tasks visible to the current user.
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $rukoUser = DB::table('app_entity_1')->where('id', $userId)->first();
        $accessSchema = explode(',', DB::table('app_entities_access')->where('entities_id', 22)->where('access_groups_id', $rukoUser->field_6)->value('access_schema'));

        $tasksQuery = DB::table('app_entity_22 as tasks')
            ->select(
                'tasks.id',
                'tasks.field_168 as name',
                'tasks.parent_item_id as project_id',
                'projects.field_158 as project_name',
                'tasks.field_171 as assigned_to_ids',
                'status.name as status_name',
                'priority.name as priority_name'
            )
            ->leftJoin('app_fields_choices as status', 'tasks.field_169', '=', 'status.id')
            ->leftJoin('app_fields_choices as priority', 'tasks.field_170', '=', 'priority.id')
            ->leftJoin('app_entity_21 as projects', 'tasks.parent_item_id', '=', 'projects.id');

        $this->applyTaskVisibilityScope($tasksQuery, $userId, $accessSchema);

        $tasks = $tasksQuery->orderBy('tasks.id', 'desc')->get();

        foreach ($tasks as $task) {
            $assignedIds = array_filter(explode(',', $task->assigned_to_ids ?? ''));
            $task->assigned_to = !empty($assignedIds)
                ? DB::table('app_entity_1')->whereIn('id', $assignedIds)->select('id', 'field_12 as username')->get()
                : [];
            unset($task->assigned_to_ids);
        }

        return response()->json($tasks);
    }

    /**
     * Get the detailed information for a single task.
     */
    public function show(Request $request, $task_id)
    {
        $task = DB::table('app_entity_22 as tasks')
            ->where('tasks.id', $task_id)
            ->select(
                'tasks.id', 'tasks.field_168 as name', 'tasks.field_172 as description',
                'tasks.parent_item_id as project_id', 'tasks.field_171 as assigned_to_ids',
                'status.name as status_name', 'priority.name as priority_name', 'type.name as type_name',
                'tasks.field_167 as type_id', 'tasks.field_170 as priority_id'
            )
            ->leftJoin('app_fields_choices as status', 'tasks.field_169', '=', 'status.id')
            ->leftJoin('app_fields_choices as priority', 'tasks.field_170', '=', 'priority.id')
            ->leftJoin('app_fields_choices as type', 'tasks.field_167', '=', 'type.id')
            ->first();

        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        $userId = $request->user()->id;
        $rukoUser = DB::table('app_entity_1')->where('id', $userId)->first();
        $accessSchema = explode(',', DB::table('app_entities_access')->where('entities_id', 22)->where('access_groups_id', $rukoUser->field_6)->value('access_schema'));
        $assigned_ids = explode(',', $task->assigned_to_ids ?? '');

        $task->comments = DB::table('app_comments')
            ->where('entities_id', 22)->where('items_id', $task_id)
            ->join('app_entity_1 as users', 'app_comments.created_by', '=', 'users.id')
            ->select('app_comments.id', 'app_comments.description', 'app_comments.date_added', 'users.field_12 as author_username', 'app_comments.created_by')
            ->orderBy('app_comments.date_added', 'asc')->get();

        $task->permissions = [
            'can_update' => in_array('update', $accessSchema) || (in_array('action_with_assigned', $accessSchema) && in_array($userId, $assigned_ids)),
            'can_delete' => in_array('delete', $accessSchema) || (in_array('action_with_assigned', $accessSchema) && in_array($userId, $assigned_ids)),
        ];

        return response()->json($task);
    }

    /**
     * Create a new task.
     */
    public function createTask(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255', 'description' => 'nullable|string',
            'project_id' => 'required|integer', 'type_id' => 'required|integer',
            'priority_id' => 'required|integer', 'assigned_to' => 'nullable|array',
            'assigned_to.*' => 'integer',
        ]);

        $assignedToString = !empty($validated['assigned_to']) ? implode(',', $validated['assigned_to']) : null;

        $newTaskId = DB::table('app_entity_22')->insertGetId([
            'parent_item_id' => $validated['project_id'], 'created_by' => $request->user()->id,
            'date_added' => time(), 'field_168' => $validated['name'],
            'field_172' => $validated['description'] ?? '', 'field_167' => $validated['type_id'],
            'field_169' => 46, 'field_170' => $validated['priority_id'],
            'field_171' => $assignedToString, 'field_173' => '', 'field_174' => '',
            'field_175' => 0, 'field_176' => 0, 'field_177' => '',
        ]);

        if (!empty($validated['assigned_to'])) {
            foreach ($validated['assigned_to'] as $assignedId) {
                DB::table('app_entity_22_values')->insert(['items_id' => $newTaskId, 'fields_id' => 171, 'value' => $assignedId]);
            }
        }

        return response()->json(['message' => 'Task created successfully', 'task_id' => $newTaskId], 201);
    }

    /**
     * Update an existing task.
     */
    public function updateTask(Request $request, $task_id)
    {
        $task = DB::table('app_entity_22')->where('id', $task_id)->first();
        if (!$task) { return response()->json(['error' => 'Task not found'], 404); }

        $userId = $request->user()->id;
        $rukoUser = DB::table('app_entity_1')->where('id', $userId)->first();
        $accessSchema = explode(',', DB::table('app_entities_access')->where('entities_id', 22)->where('access_groups_id', $rukoUser->field_6)->value('access_schema'));
        $assigned_ids = explode(',', $task->field_171 ?? '');

        if (!in_array('update', $accessSchema) && !(in_array('action_with_assigned', $accessSchema) && in_array($userId, $assigned_ids))) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255', 'description' => 'nullable|string',
            'project_id' => 'required|integer', 'type_id' => 'required|integer',
            'priority_id' => 'required|integer', 'assigned_to' => 'nullable|array',
            'assigned_to.*' => 'integer',
        ]);

        $assignedToString = !empty($validated['assigned_to']) ? implode(',', $validated['assigned_to']) : null;

        DB::table('app_entity_22')->where('id', $task_id)->update([
            'parent_item_id' => $validated['project_id'], 'date_updated' => time(),
            'field_168' => $validated['name'], 'field_172' => $validated['description'] ?? '',
            'field_167' => $validated['type_id'], 'field_170' => $validated['priority_id'],
            'field_171' => $assignedToString,
        ]);

        DB::table('app_entity_22_values')->where('items_id', $task_id)->where('fields_id', 171)->delete();
        if (!empty($validated['assigned_to'])) {
            foreach ($validated['assigned_to'] as $assignedId) {
                DB::table('app_entity_22_values')->insert(['items_id' => $task_id, 'fields_id' => 171, 'value' => $assignedId]);
            }
        }

        return response()->json(['message' => 'Task updated successfully']);
    }

    /**
     * Delete a task.
     */
    public function deleteTask(Request $request, $task_id)
    {
        $task = DB::table('app_entity_22')->where('id', $task_id)->first();
        if (!$task) { return response()->json(['error' => 'Task not found'], 404); }

        $userId = $request->user()->id;
        $rukoUser = DB::table('app_entity_1')->where('id', $userId)->first();
        $accessSchema = explode(',', DB::table('app_entities_access')->where('entities_id', 22)->where('access_groups_id', $rukoUser->field_6)->value('access_schema'));
        $assigned_ids = explode(',', $task->field_171 ?? '');

        if (!in_array('delete', $accessSchema) && !(in_array('action_with_assigned', $accessSchema) && in_array($userId, $assigned_ids))) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        DB::table('app_entity_22')->where('id', $task_id)->delete();
        DB::table('app_entity_22_values')->where('items_id', $task_id)->delete();
        DB::table('app_comments')->where('entities_id', 22)->where('items_id', $task_id)->delete();

        return response()->json(['message' => 'Task deleted successfully']);
    }

    /**
     * Update only the status of a task.
     */
    public function updateTaskStatus(Request $request, $task_id)
    {
        $validated = $request->validate(['status_id' => 'required|integer']);
        DB::table('app_entity_22')->where('id', $task_id)->update(['field_169' => $validated['status_id']]);
        return response()->json(['message' => 'Status updated successfully']);
    }

    /**
     * Create a new comment.
     */
    public function createComment(Request $request, $task_id)
    {
        $validated = $request->validate(['description' => 'required|string']);
        $commentId = DB::table('app_comments')->insertGetId([
            'entities_id' => 22, 'items_id' => $task_id,
            'created_by' => $request->user()->id, 'description' => $validated['description'],
            'date_added' => time(), 'attachments' => '',
        ]);
        $newComment = DB::table('app_comments')->where('app_comments.id', $commentId)
            ->join('app_entity_1 as users', 'app_comments.created_by', '=', 'users.id')
            ->select('app_comments.*', 'users.field_12 as author_username')->first();
        return response()->json($newComment, 201);
    }

    /**
     * Update an existing comment.
     */
    public function updateComment(Request $request, $comment_id)
    {
        $validated = $request->validate(['description' => 'required|string']);
        $comment = DB::table('app_comments')->where('id', $comment_id)->first();
        if (!$comment || $comment->created_by != $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        DB::table('app_comments')->where('id', $comment_id)->update(['description' => $validated['description']]);
        return response()->json(['message' => 'Comment updated successfully.']);
    }

    /**
     * Delete a comment.
     */
    public function deleteComment(Request $request, $comment_id)
    {
        $comment = DB::table('app_comments')->where('id', $comment_id)->first();
        if (!$comment || $comment->created_by != $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        DB::table('app_comments')->where('id', $comment_id)->delete();
        return response()->json(['message' => 'Comment deleted successfully.']);
    }

    /**
     * Get data needed for the create/edit forms.
     */
    public function getCreateTaskFormData()
    {
        $projects = DB::table('app_entity_21')->select('id', 'field_158 as name', 'field_161 as team_member_ids')->get();
        $users = DB::table('app_entity_1')->select('id', 'field_12 as username')->where('field_5', 1)->get();
        $taskTypes = DB::table('app_fields_choices')->where('fields_id', 167)->get();
        $priorities = DB::table('app_fields_choices')->where('fields_id', 170)->get();

        foreach ($projects as $project) {
            if (strlen(trim($project->team_member_ids)) === 0) {
                $project->users = $users->values();
            } else {
                $team_ids = explode(',', $project->team_member_ids);
                $project->users = $users->filter(fn($user) => in_array($user->id, $team_ids))->values();
            }
        }

        return response()->json(['projects' => $projects, 'task_types' => $taskTypes, 'priorities' => $priorities]);
    }

    /**
     * Get all active status choices.
     */
    public function getStatuses()
    {
        $statuses = DB::table('app_fields_choices')->where('fields_id', 169)->where('is_active', 1)
            ->select('id', 'name')->orderBy('sort_order')->get();
        return response()->json($statuses);
    }

    /**
     * Get notifications for the current user.
     */
    public function getNotifications(Request $request)
    {
        $notifications = DB::table('app_users_notifications')->where('users_id', $request->user()->id)
            ->select('id', 'name', 'type', 'date_added')->orderBy('date_added', 'desc')->get();
        return response()->json($notifications);
    }
}

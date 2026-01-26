<?php

// TaskController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
        // We check for the MORE RESTRICTIVE rule FIRST.
        if (in_array('view_assigned', $accessSchema)) {
            $query->where(function ($subQuery) use ($userId) {
                $subQuery->where('tasks.created_by', $userId)
                    ->orWhereRaw('FIND_IN_SET(?, tasks.field_171)', [$userId]);
            });
            return; // Apply this rule and stop.
        }

        // If 'view_assigned' is not present, THEN we check for the global 'view' rule.
        if (in_array('view', $accessSchema)) {
            return; // This user can see everything, so we don't add any filters.
        }

        // If neither rule is present, they can't see anything.
        $query->whereRaw('1 = 0');
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

    // 1. Main Query: Fetch all tasks with necessary joins
    $tasksQuery = DB::table('app_entity_22 as tasks')
        ->select(
            'tasks.id',
            'tasks.field_168 as name',
            'tasks.parent_item_id as project_id',
            'tasks.field_171 as assigned_to_ids',
            'creator.field_12 as creator_name', // ✅ ADDED
            DB::raw("COALESCE(projects.field_158, 'No Project') as project_name"),
            DB::raw("COALESCE(status.name, 'Unknown Status') as status_name"),
            DB::raw("COALESCE(priority.name, 'Normal') as priority_name")
        )
        ->leftJoin('app_fields_choices as status', 'tasks.field_169', '=', 'status.id')
        ->leftJoin('app_fields_choices as priority', 'tasks.field_170', '=', 'priority.id')
        ->leftJoin('app_entity_21 as projects', 'tasks.parent_item_id', '=', 'projects.id')
        ->leftJoin('app_entity_1 as creator', 'tasks.created_by', '=', 'creator.id'); // ✅ ADDED

    $this->applyTaskVisibilityScope($tasksQuery, $userId, $accessSchema);

    $tasks = $tasksQuery->orderBy('tasks.id', 'desc')->get();

    // 2. Eager Load Users: Collect all unique user IDs from all tasks
    $allUserIds = $tasks->pluck('assigned_to_ids')
        ->flatMap(fn ($ids) => explode(',', $ids ?? ''))
        ->map(fn ($id) => (int) $id)
        ->filter()
        ->unique()
        ->values();

    // 3. Fetch all required users in a SINGLE query
    $users = collect();
    if ($allUserIds->isNotEmpty()) {
        $users = DB::table('app_entity_1')
            ->whereIn('id', $allUserIds)
            ->select('id', 'field_12 as username')
            ->get()
            ->keyBy('id'); // Key the collection by user ID for easy lookup
    }

    // 4. Transform the final collection
    $transformedTasks = $tasks->map(function ($task) use ($users, $accessSchema, $userId) {
        $assignedIds = collect(explode(',', $task->assigned_to_ids ?? ''))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        // Map the user data from our pre-fetched collection
        $task->assigned_to = $assignedIds->map(fn ($id) => $users->get($id))->filter()->values();

        // Set permissions
        $task->permissions = [
            'can_update' => in_array('update', $accessSchema) || (in_array('action_with_assigned', $accessSchema) && $assignedIds->contains($userId)),
            'can_delete' => in_array('delete', $accessSchema) || (in_array('action_with_assigned', $accessSchema) && $assignedIds->contains($userId)),
        ];
        
        // Clean up and cast types
        $task->id = (int) $task->id;
        $task->project_id = (int) ($task->project_id ?? 0);
        unset($task->assigned_to_ids);

        return $task;
    });

    return response()->json($transformedTasks);
}

    /**
     * Get the detailed information for a single task.
     */
    public function show(Request $request, $task_id)
    {
        $userId = $request->user()->id;
        $rukoUser = DB::table('app_entity_1')->where('id', $userId)->first();
        $accessSchema = explode(',', DB::table('app_entities_access')->where('entities_id', 22)->where('access_groups_id', $rukoUser->field_6)->value('access_schema'));

        $taskQuery = DB::table('app_entity_22 as tasks')->where('tasks.id', $task_id);
        $this->applyTaskVisibilityScope($taskQuery, $userId, $accessSchema);

        $task = $taskQuery
            ->select(
                'tasks.id',
                'tasks.field_168 as name',
                'tasks.field_172 as description',
                'tasks.parent_item_id as project_id',
                'tasks.field_171 as assigned_to_ids',
                'tasks.field_167 as type_id',
                'tasks.field_170 as priority_id',
                'tasks.field_177 as attachments_list', // This was the missing piece
                DB::raw("COALESCE(status.name, 'Unknown Status') as status_name"),
                DB::raw("COALESCE(priority.name, 'Normal') as priority_name"),
                DB::raw("COALESCE(type.name, 'Unknown Type') as type_name")
            )
            ->leftJoin('app_fields_choices as status', 'tasks.field_169', '=', 'status.id')
            ->leftJoin('app_fields_choices as priority', 'tasks.field_170', '=', 'priority.id')
            ->leftJoin('app_fields_choices as type', 'tasks.field_167', '=', 'type.id')
            ->first();

        if (!$task) {
            return response()->json(['error' => 'Task not found or permission denied'], 404);
        }

        // ✅ CORRECTED ATTACHMENT LOGIC
        $task->attachments = [];
        if (!empty($task->attachments_list)) {
            $attachmentFiles = explode(',', $task->attachments_list);
            foreach ($attachmentFiles as $filename) {
                if (empty(trim($filename))) {
                    continue;
                }

                $originalName = substr($filename, strpos($filename, '_') + 1);

                // Generate an absolute URL to the download route
                $url = route('attachments.download', ['filename' => $filename], true);

                $task->attachments[] = [
                    'filename' => $filename,
                    'original_name' => $originalName,
                    'url' => $url,
                ];
            }
        }
        unset($task->attachments_list);


        // --- The rest of your original code ---
        $task->id = (int) $task->id;
        $task->project_id = (int) $task->project_id;
        $task->type_id = (int) $task->type_id;
        $task->priority_id = (int) $task->priority_id;
        $task->description = $task->description ?? '';

        $assigned_ids = array_map('intval', array_filter(explode(',', $task->assigned_to_ids ?? '')));

        if (!empty($assigned_ids)) {
            $users = DB::table('app_entity_1')->whereIn('id', $assigned_ids)->select('id', 'field_12 as username')->get();
            $task->assigned_to = $users->map(function ($user) {
                $user->id = (int) $user->id;
                return $user;
            });
        } else {
            $task->assigned_to = [];
        }
        unset($task->assigned_to_ids);

        $comments = DB::table('app_comments')
            ->where('entities_id', 22)->where('items_id', $task_id)
            ->join('app_entity_1 as users', 'app_comments.created_by', '=', 'users.id')
            ->select('app_comments.id', 'app_comments.description', 'app_comments.date_added', 'users.field_12 as author_username', 'app_comments.created_by')
            ->orderBy('app_comments.date_added', 'asc')->get();

        $task->comments = $comments->map(function ($comment) {
            $comment->id = (int) $comment->id;
            $comment->created_by = (int) $comment->created_by;
            $comment->date_added = (int) $comment->date_added;
            return $comment;
        });

        $task->permissions = [
            'can_update' => in_array('update', $accessSchema) || (in_array('action_with_assigned', $accessSchema) && in_array($userId, $assigned_ids)),
            'can_delete' => in_array('delete', $accessSchema) || (in_array('action_with_assigned', $accessSchema) && in_array($userId, $assigned_ids)),
        ];

        return response()->json($task);
    }


    public function uploadAttachment(Request $request, $task_id)
    {
        $request->validate([
            'attachment' => 'required|file|max:20480',
        ]);

        $task = DB::table('app_entity_22')->where('id', $task_id)->first();
        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        $file = $request->file('attachment');
        $originalFilename = $file->getClientOriginalName();

        $rukoFilename = time() . '_' . str_replace([' ', ','], '_', $originalFilename);
        $rukoFolderPath = date('Y') . '/' . date('m') . '/' . date('d');
        $encryptedFilename = sha1($rukoFilename);

        $path = $file->storeAs(
            $rukoFolderPath,
            $encryptedFilename,
            'rukovoditel_attachments'
        );

        if (!$path) {
            return response()->json(['error' => 'Failed to upload file.'], 500);
        }

        $existingAttachments = $task->field_177;
        $newAttachmentsList = [];

        if (!empty($existingAttachments)) {
            $newAttachmentsList = explode(',', $existingAttachments);
        }

        $newAttachmentsList[] = $rukoFilename;

        DB::table('app_entity_22')->where('id', $task_id)->update([
            'field_177' => implode(',', $newAttachmentsList),
            'date_updated' => time(),
        ]);

        return response()->json([
            'message' => 'File uploaded successfully and is now visible in Rukovoditel.',
            'path' => $path,
            'rukovoditel_filename' => $rukoFilename
        ], 201);
    }

    /**
     * Create a new task.
     */
    public function createTask(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|integer',
            'type_id' => 'required|integer',
            'priority_id' => 'required|integer',
            'assigned_to' => 'nullable|array',
            'assigned_to.*' => 'integer',
        ]);

        $assignedToString = !empty($validated['assigned_to']) ? implode(',', $validated['assigned_to']) : null;

        $newTaskId = DB::table('app_entity_22')->insertGetId([
            'parent_item_id' => $validated['project_id'],
            'created_by' => $request->user()->id,
            'date_added' => time(),
            'field_168' => $validated['name'],
            'field_172' => $validated['description'] ?? '',
            'field_167' => $validated['type_id'],
            'field_169' => 46,
            'field_170' => $validated['priority_id'],
            'field_171' => $assignedToString,
            'field_173' => '',
            'field_174' => '',
            'field_175' => 0,
            'field_176' => 0,
            'field_177' => '',
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
        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        $userId = $request->user()->id;
        $rukoUser = DB::table('app_entity_1')->where('id', $userId)->first();
        $accessSchema = explode(',', DB::table('app_entities_access')->where('entities_id', 22)->where('access_groups_id', $rukoUser->field_6)->value('access_schema'));
        $assigned_ids = explode(',', $task->field_171 ?? '');

        if (!in_array('update', $accessSchema) && !(in_array('action_with_assigned', $accessSchema) && in_array($userId, $assigned_ids))) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|integer',
            'type_id' => 'required|integer',
            'priority_id' => 'required|integer',
            'assigned_to' => 'nullable|array',
            'assigned_to.*' => 'integer',
        ]);

        $assignedToString = !empty($validated['assigned_to']) ? implode(',', $validated['assigned_to']) : null;

        DB::table('app_entity_22')->where('id', $task_id)->update([
            'parent_item_id' => $validated['project_id'],
            'date_updated' => time(),
            'field_168' => $validated['name'],
            'field_172' => $validated['description'] ?? '',
            'field_167' => $validated['type_id'],
            'field_170' => $validated['priority_id'],
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
        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

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
            'entities_id' => 22,
            'items_id' => $task_id,
            'created_by' => $request->user()->id,
            'description' => $validated['description'],
            'date_added' => time(),
            'attachments' => '',
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
        $projects = DB::table('app_entity_21')
            ->select('id', 'field_158 as name', 'field_161 as team_member_ids')
            ->get()
            ->map(function ($project) {
                $project->id = (int) $project->id;
                return $project;
            });

        $users = DB::table('app_entity_1')
            ->select('id', 'field_12 as username')
            ->where('field_5', 1)
            ->get()
            ->map(function ($user) {
                $user->id = (int) $user->id;
                return $user;
            });

        $taskTypes = DB::table('app_fields_choices')
            ->where('fields_id', 167)
            ->select('id', 'name')
            ->get()
            ->map(function ($type) {
                $type->id = (int) $type->id;
                return $type;
            });

        $priorities = DB::table('app_fields_choices')
            ->where('fields_id', 170)
            ->select('id', 'name')
            ->get()
            ->map(function ($priority) {
                $priority->id = (int) $priority->id;
                return $priority;
            });

        foreach ($projects as $project) {
            if (empty(trim($project->team_member_ids))) {
                $project->users = $users->values();
            } else {
                $teamIds = array_map('intval', explode(',', $project->team_member_ids));
                $project->users = $users->filter(fn($u) => in_array($u->id, $teamIds))->values();
            }

            unset($project->team_member_ids);
        }

        return response()->json([
            'projects' => $projects,
            'task_types' => $taskTypes,
            'priorities' => $priorities,
        ]);
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
            ->select('id', 'name', 'type', 'date_added', 'items_id as task_id')
            ->orderBy('date_added', 'desc')->get();

        return response()->json($notifications);
    }

    public function deleteNotification(Request $request, $notificationId)
    {
        // 1. Find the notification that belongs to the CURRENT user.
        $notification = DB::table('app_users_notifications')
            ->where('id', $notificationId)
            ->where('users_id', $request->user()->id)
            ->first();

        // 2. If it doesn't exist or doesn't belong to the user, it's already "deleted" from their perspective.
        //    Return a success response to prevent errors in the app.
        if (!$notification) {
            // Use 204 No Content, which is the standard for a successful DELETE
            // on a resource that is already gone.
            return response()->noContent();
        }

        // 3. If it exists, delete it.
        DB::table('app_users_notifications')->where('id', $notificationId)->delete();

        // 4. Return the success response.
        return response()->noContent();
    }
}

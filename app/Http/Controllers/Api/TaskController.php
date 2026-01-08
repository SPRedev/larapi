<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function index(Request $request)
{
    $userId = Auth::id();
    $rukoUser = DB::table('app_entity_1')->where('id', $userId)->first();
    if (!$rukoUser) { return response()->json(['error' => 'User not found'], 404); }

    $userGroupId = $rukoUser->field_6;
    $permissions = DB::table('app_entities_access')->where('entities_id', 22)->where('access_groups_id', $userGroupId)->value('access_schema');
    $accessSchema = explode(',', $permissions ?? '');

    $tasksQuery = DB::table('app_entity_22 as tasks')
        ->select(
            'tasks.id',
            'tasks.field_168 as name',
            'tasks.parent_item_id as project_id',
            'projects.field_158 as project_name', // ✅ NEW: Get the project name
            'tasks.field_171 as assigned_to_ids',
            'status.name as status_name',
            'priority.name as priority_name'
        )
        ->leftJoin('app_fields_choices as status', 'tasks.field_169', '=', 'status.id')
        ->leftJoin('app_fields_choices as priority', 'tasks.field_170', '=', 'priority.id')
        // ✅ NEW: Join with the projects table (app_entity_21)
        ->leftJoin('app_entity_21 as projects', 'tasks.parent_item_id', '=', 'projects.id');


if (in_array('view_assigned', $accessSchema)) {
    // This user's role requires us to filter by assignment or creation.
    $tasksQuery->where(function ($query) use ($userId) {
        $query->whereRaw('FIND_IN_SET(?, tasks.field_171)', [$userId])  // Task is assigned to me
              ->orWhere('tasks.created_by', $userId);                   // OR I created the task
    });
} elseif (!in_array('view', $accessSchema)) {
    // If we reach here, it means 'view_assigned' was NOT present.
    // We now check if 'view' is also absent. If so, the user can't see anything.
    return response()->json([]);
}

    $tasks = $tasksQuery->orderBy('tasks.id', 'desc')->get();

    foreach ($tasks as $task) {
        $assignedIds = array_filter(explode(',', $task->assigned_to_ids ?? ''));
        if (!empty($assignedIds)) {
            $task->assigned_to = DB::table('app_entity_1')->whereIn('id', $assignedIds)->select('id', 'field_12 as username')->get();
        } else {
            $task->assigned_to = [];
        }
        unset($task->assigned_to_ids);
    }

    return response()->json($tasks);
}

// In app/Http/Controllers/Api/TaskController.php

// In app/Http/Controllers/Api/TaskController.php

// In app/Http/Controllers/Api/TaskController.php

public function show(Request $request, $task_id)
{
    $task = DB::table('app_entity_22 as tasks')
        ->where('tasks.id', $task_id)
        ->select('tasks.id', 'tasks.field_168 as name', 'tasks.field_172 as description', 'tasks.parent_item_id as project_id', 'tasks.field_177 as attachment_filenames', 'status.name as status_name', 'priority.name as priority_name')
        ->leftJoin('app_fields_choices as status', 'tasks.field_169', '=', 'status.id')
        ->leftJoin('app_fields_choices as priority', 'tasks.field_170', '=', 'priority.id')
        ->first();

    if (!$task) {
        return response()->json(['error' => 'Task not found'], 404);
    }

    $comments = DB::table('app_comments')->where('entities_id', 22)->where('items_id', $task_id)->join('app_entity_1 as users', 'app_comments.created_by', '=', 'users.id')->select('app_comments.id', 'app_comments.description', 'app_comments.date_added', 'users.field_12 as author_username')->orderBy('app_comments.date_added', 'asc')->get();

    // --- FINAL, CORRECTED LOGIC USING RUKOVODITEL'S DOWNLOADER ---
    $attachments = collect();
    $apiBaseUrl = url('/api');
    // IMPORTANT: This must be the base URL to your Rukovoditel installation
    $rukoBaseUrl = 'http://192.168.100.11/tmgr';
    // $rukoBaseUrl = 'http://10.0.2.2/tmgr';

    if (!empty($task->attachment_filenames )) {
    $filenames = array_map('trim', explode(',', trim($task->attachment_filenames)));
    
    foreach ($filenames as $filename) {
        if (!empty($filename)) {
            $attachments->push([
                'id' => 0,
                'filename' => $filename,
                // This URL now points to our new, secure download endpoint
                'url' => $apiBaseUrl . '/tasks/' . $task->id . '/download-attachment/' . urlencode($filename),
            ]);
        }
    }
}

    $task->comments = $comments;
    $task->attachments = $attachments;
    unset($task->attachment_filenames);

    return response()->json($task);
}


  // In TaskController.php
public function updateStatus(Request $request, $task_id)
{
    $validated = $request->validate([
        'status_id' => 'required|integer',
    ]);

    $task = DB::table('app_entity_22')->where('id', $task_id);

    if (!$task->exists()) {
        return response()->json(['error' => 'Task not found'], 404);
    }

    $task->update(['field_169' => $validated['status_id']]);

    // This part is optional but good for Rukovoditel's history log
    DB::table('app_comments_history')->insert([
        'comments_id' => 0,
        'fields_id' => 169,
        'fields_value' => $validated['status_id']
    ]);

    return response()->json(['message' => 'Status updated successfully']);
}


    public function getNotifications(Request $request)
    {
        $userId = $request->user()->id;
        $notifications = DB::table('app_users_notifications')->where('users_id', $userId)->select('id', 'name', 'type', 'date_added')->orderBy('date_added', 'desc')->get();
        return response()->json($notifications);
    }

// In app/Http/Controllers/Api/TaskController.php - A simpler, better download method

public function downloadAttachment(Request $request, $task_id, $filename)
{
    // We only need the filename. The other parameters are for context.
    // Construct the direct, physical path to the file.
    $filePath = 'C:/laragon/www/tmgr/public/uploads/attachments/' . $filename;

    // Check if the file exists.
    if (!file_exists($filePath)) {
        return response()->json(['error' => 'File not found on disk.'], 404);
    }

    // Stream the file as a download. The second argument is the "public" name
    // the user will see when they download it.
    return response()->download($filePath, $filename);
}

public function getStatuses(Request $request)
{
    // The ID of the "Status" field for Tasks is 169.
    $statusFieldId = 169;

    $statuses = DB::table('app_fields_choices')
        ->where('fields_id', $statusFieldId)
        ->where('is_active', 1) // Only get active choices
        ->select('id', 'name')
        ->orderBy('sort_order')
        ->get();

    return response()->json($statuses);
}
    
public function createComment(Request $request, $task_id)
{
    // ... (validation and insert logic is the same and works correctly)
    $validated = $request->validate(['description' => 'required|string']);
    $userId = Auth::id();
    $commentId = DB::table('app_comments')->insertGetId([
        'entities_id' => 22,
        'items_id' => $task_id,
        'created_by' => $userId,
        'description' => $validated['description'],
        'date_added' => time(),
        'attachments' => '',
    ]);

    // --- THIS IS THE PART TO FIX ---
    $newComment = DB::table('app_comments')
        // ✅ FIX: Specify the table name for the 'id' column
        ->where('app_comments.id', $commentId)
        ->join('app_entity_1 as users', 'app_comments.created_by', '=', 'users.id')
        ->select('app_comments.*', 'users.field_12 as author_username')
        ->first();

    return response()->json($newComment, 201);
}

public function deleteComment(Request $request, $comment_id)
{
    $userId = Auth::id();

    // Find the comment to make sure it exists and belongs to the user
    $comment = DB::table('app_comments')->where('id', $comment_id)->first();

    if (!$comment) {
        return response()->json(['error' => 'Comment not found.'], 404);
    }

    // SECURITY CHECK: Ensure the user is the author of the comment
    if ($comment->created_by != $userId) {
        return response()->json(['error' => 'You do not have permission to delete this comment.'], 403);
    }

    // Delete the comment
    DB::table('app_comments')->where('id', $comment_id)->delete();

    return response()->json(['message' => 'Comment deleted successfully.']);
}
public function updateComment(Request $request, $comment_id)
{
    $validated = $request->validate(['description' => 'required|string']);
    $userId = Auth::id();
    $comment = DB::table('app_comments')->where('id', $comment_id)->first();

    if (!$comment) {
        return response()->json(['error' => 'Comment not found.'], 404);
    }
    if ($comment->created_by != $userId) {
        return response()->json(['error' => 'Forbidden.'], 403);
    }
    DB::table('app_comments')->where('id', $comment_id)->update([
        'description' => $validated['description'],
    ]);
    return response()->json(['message' => 'Comment updated successfully.']);
}

public function getCreateTaskFormData()
{
    // 1. Fetch all Projects (Entity 21)
    $projects = DB::table('app_entity_21')
        ->select('id', 'field_158 as name', 'field_161 as team_member_ids')
        ->get();

    // 2. Fetch all Users (Entity 1) that can be assigned
    $users = DB::table('app_entity_1')
        ->select('id', 'field_12 as username', 'field_6 as group_id')
        ->where('field_5', 1) // Where user is Active
        ->get();
    
    // 3. Fetch all Task Types and Priorities from the choices table
    $taskTypes = DB::table('app_fields_choices')->where('fields_id', 167)->get(); // Field ID for "Type"
    $priorities = DB::table('app_fields_choices')->where('fields_id', 170)->get(); // Field ID for "Priority"

    // 4. Associate users with their projects
    foreach ($projects as $project) {
    // We check the length of the trimmed string. This is the most reliable way.
    if (strlen(trim($project->team_member_ids)) === 0) {
        // SPECIAL CASE: If the string length is 0, it's our "Globale" project.
        // Assign ALL users to this project.
        $project->users = $users->values();
    } else {
        // NORMAL CASE: For all other projects, filter users based on the IDs.
        $team_ids = explode(',', $project->team_member_ids);
        $project->users = $users->filter(function ($user) use ($team_ids) {
            return in_array($user->id, $team_ids);
        })->values();
    }
}

return response()->json([
    'projects' => $projects,
    'task_types' => $taskTypes,
    'priorities' => $priorities,
]);
}

public function createTask(Request $request)
{
    // ✅ Validation (unchanged)
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'project_id' => 'required|integer',
        'type_id' => 'required|integer',
        'priority_id' => 'required|integer',
        'assigned_to' => 'nullable|array',
        'assigned_to.*' => 'integer',
    ]);

    $userId = Auth::id();

    // Convert assigned users array to comma-separated string
    $assignedToString = !empty($validated['assigned_to'])
        ? implode(',', $validated['assigned_to'])
        : null;

    // ✅ INSERT TASK WITH REQUIRED DEFAULT VALUES
    $newTaskId = DB::table('app_entity_22')->insertGetId([
        'parent_item_id' => $validated['project_id'],
        'created_by'     => $userId,
        'date_added'     => time(),

        // Core fields
        'field_168' => $validated['name'],                 // Task name
        'field_172' => $validated['description'] ?? '',   // Description
        'field_167' => $validated['type_id'],              // Type
        'field_169' => 46,                                 // Status: "Nouveau"
        'field_170' => $validated['priority_id'],          // Priority
        'field_171' => $assignedToString,                  // Assigned users

        // ✅ REQUIRED DEFAULTS (VERY IMPORTANT)
        'field_173' => '',   // Estimated time
        'field_174' => '',   // Worked hours
        'field_175' => 0,    // Start date
        'field_176' => 0,    // End date
        'field_177' => '',   // Attachments
    ]);

    // ✅ Insert assigned users into values table (Rukovoditel logic)
    if (!empty($validated['assigned_to'])) {
        foreach ($validated['assigned_to'] as $assignedId) {
            DB::table('app_entity_22_values')->insert([
                'items_id'  => $newTaskId,
                'fields_id' => 171,
                'value'     => $assignedId,
            ]);
        }
    }

    return response()->json([
        'message' => 'Task created successfully',
        'task_id' => $newTaskId,
    ], 201);
}

}

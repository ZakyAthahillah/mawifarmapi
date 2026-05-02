<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        if ($request->filled('module')) {
            $query->where('module', $request->query('module'));
        }

        if ($request->filled('user')) {
            $search = $request->query('user');
            $query->where(fn ($inner) => $inner
                ->where('user_name', 'like', "%$search%")
                ->orWhere('user_role', 'like', "%$search%"));
        }

        return response()->json([
            'status' => true,
            'data' => $query->limit(300)->get()->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $log->user_name,
                'user_role' => $log->user_role,
                'action' => $log->action,
                'module' => $log->module,
                'subject_type' => class_basename((string) $log->subject_type),
                'subject_id' => $log->subject_id,
                'before_data' => $log->before_data,
                'after_data' => $log->after_data,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => optional($log->created_at)?->format('Y-m-d H:i:s'),
            ]),
        ]);
    }
}

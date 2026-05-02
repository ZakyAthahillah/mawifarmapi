<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public static function log(string $action, ?string $module = null, mixed $subject = null, ?array $before = null, ?array $after = null, ?Request $request = null): void
    {
        try {
            $user = Auth::user();
            $request ??= request();

            ActivityLog::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'user_role' => $user?->role,
                'action' => $action,
                'module' => $module,
                'subject_type' => self::subjectType($subject),
                'subject_id' => self::subjectId($subject),
                'before_data' => $before,
                'after_data' => $after,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (\Throwable) {
            // Logging must never block the main application flow.
        }
    }

    private static function subjectType(mixed $subject): ?string
    {
        if ($subject instanceof Model) {
            return $subject::class;
        }

        return is_string($subject) ? $subject : null;
    }

    private static function subjectId(mixed $subject): ?string
    {
        if ($subject instanceof Model) {
            return (string) $subject->getKey();
        }

        return null;
    }
}

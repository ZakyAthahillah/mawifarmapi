<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function show()
    {
        return response()->json([
            'status' => true,
            'data' => $this->data(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $this->data();

        AppSetting::setValue('maintenance_enabled', $data['enabled'] ? '1' : '0');
        AppSetting::setValue('maintenance_message', trim((string) ($data['message'] ?? '')));

        $after = $this->data();
        ActivityLogger::log('update', 'maintenance', 'maintenance', $before, $after, $request);

        return response()->json([
            'status' => true,
            'message' => 'Status maintenance berhasil disimpan',
            'data' => $after,
        ]);
    }

    private function data(): array
    {
        $enabled = AppSetting::getValue('maintenance_enabled', '0') === '1';
        $message = AppSetting::getValue('maintenance_message', '') ?? '';

        return [
            'enabled' => $enabled,
            'message' => $enabled ? $message : '',
        ];
    }
}

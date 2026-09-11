<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Token;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TokenApiController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Token::with('abilities');

        if ($user->role < 100) {
            $query->where('user_id', $user->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        return response()->json($query->orderBy('created_time', 'desc')->paginate(20));
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'remain_quota' => 'nullable|integer',
            'quota_quota' => 'nullable|integer', // 兼容旧表单字段
            'unlimited_quota' => 'nullable|boolean',
            'expired_time' => 'nullable',
            'expired_at' => 'nullable', // 兼容旧表单字段
            'group' => 'nullable|string|max:64',
            'allow_ips' => 'nullable|string|max:256',
            'model_limits' => 'nullable|string|max:2000',
            'model_limits_enabled' => 'nullable|boolean',
        ]);

        $unlimited = (bool) ($validated['unlimited_quota'] ?? false);
        $expiredTime = $validated['expired_time'] ?? $validated['expired_at'] ?? -1;
        if (is_string($expiredTime) && ! is_numeric($expiredTime)) {
            $expiredTime = $expiredTime === '' ? -1 : strtotime($expiredTime); // datetime-local
        }

        $token = Token::create([
            'name' => $validated['name'],
            'key' => 'sk-'.bin2hex(random_bytes(24)),
            'user_id' => $user->id,
            'status' => 1,
            'remain_quota' => $unlimited ? 0 : (int) ($validated['remain_quota'] ?? $validated['quota_quota'] ?? 0),
            'unlimited_quota' => $unlimited,
            'expired_time' => (int) $expiredTime,
            'created_time' => time(),
            'accessed_time' => time(),
            'group' => (string) ($validated['group'] ?? ''),
            'allow_ips' => (string) ($validated['allow_ips'] ?? ''),
            'model_limits_enabled' => (bool) ($validated['model_limits_enabled'] ?? false),
            'model_limits' => (string) ($validated['model_limits'] ?? ''),
        ]);

        return response()->json($token, 201);
    }

    public function show(int $id)
    {
        $user = Auth::user();
        $token = Token::with('abilities')->findOrFail($id);

        if ($user->role < 100 && $token->user_id !== $user->id) {
            return response()->json(['error' => __('Access denied')], 403);
        }

        return response()->json($token);
    }

    public function update(Request $request, int $id)
    {
        $user = Auth::user();
        $token = Token::findOrFail($id);

        if ($user->role < 100 && $token->user_id !== $user->id) {
            return response()->json(['error' => __('Access denied')], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'status' => 'nullable|integer|in:0,1,2',
            'remain_quota' => 'nullable|integer',
            'quota_quota' => 'nullable|integer', // 兼容旧表单字段
            'unlimited_quota' => 'nullable|boolean',
            'expired_time' => 'nullable',
            'expired_at' => 'nullable', // 兼容旧表单字段
            'group' => 'nullable|string|max:64',
            'allow_ips' => 'nullable|string|max:256',
            'model_limits' => 'nullable|string|max:2000',
            'model_limits_enabled' => 'nullable|boolean',
        ]);

        $unlimited = (bool) ($validated['unlimited_quota'] ?? $token->unlimited_quota);
        $payload = [];
        if (isset($validated['name'])) {
            $payload['name'] = $validated['name'];
        }
        if (isset($validated['status'])) {
            $payload['status'] = (int) $validated['status'];
        }
        if (isset($validated['remain_quota']) || isset($validated['quota_quota'])) {
            $payload['remain_quota'] = (int) ($validated['remain_quota'] ?? $validated['quota_quota']);
        }
        if (isset($validated['unlimited_quota'])) {
            $payload['unlimited_quota'] = $unlimited;
        }
        if ($unlimited) {
            $payload['remain_quota'] = 0;
        }
        $expiredTime = $validated['expired_time'] ?? $validated['expired_at'] ?? null;
        if ($expiredTime !== null) {
            if (is_string($expiredTime) && ! is_numeric($expiredTime)) {
                $expiredTime = $expiredTime === '' ? -1 : strtotime($expiredTime);
            }
            $payload['expired_time'] = (int) $expiredTime;
        }
        foreach (['group', 'allow_ips', 'model_limits'] as $field) {
            if (isset($validated[$field])) {
                $payload[$field] = (string) $validated[$field];
            }
        }
        if (isset($validated['model_limits_enabled'])) {
            $payload['model_limits_enabled'] = (bool) $validated['model_limits_enabled'];
        }

        $token->update($payload);

        return response()->json($token->refresh());
    }

    public function destroy(int $id)
    {
        $user = Auth::user();
        $token = Token::findOrFail($id);

        if ($user->role < 100 && $token->user_id !== $user->id) {
            return response()->json(['error' => __('Access denied')], 403);
        }

        $token->delete();

        return response()->json(['message' => __('Token deleted')]);
    }

    public function regenerate(int $id)
    {
        $user = Auth::user();
        $token = Token::findOrFail($id);

        if ($user->role < 100 && $token->user_id !== $user->id) {
            return response()->json(['error' => __('Access denied')], 403);
        }

        $token->key = 'sk-'.bin2hex(random_bytes(24));
        $token->save();

        return response()->json($token);
    }
}

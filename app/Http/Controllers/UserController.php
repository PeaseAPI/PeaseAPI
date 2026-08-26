<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * GET /api/user/self - Return the authenticated user's profile.
     */
    public function self(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Mask news API keys if present in setting JSON
        $setting = is_string($user->setting) ? json_decode($user->setting, true) : $user->setting;
        $newsKeys = $setting['news_keys'] ?? [];
                $maskedNewsKeys = [
            'news_google_key' => self::maskKey($newsKeys['news_google_key'] ?? ''),
            'news_newsapi_key' => self::maskKey($newsKeys['news_newsapi_key'] ?? ''),
            'news_tavily_key' => self::maskKey($newsKeys['news_tavily_key'] ?? ''),
            'news_exa_key' => self::maskKey($newsKeys['news_exa_key'] ?? ''),
            'news_brave_key' => self::maskKey($newsKeys['news_brave_key'] ?? ''),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name,
                'avatar' => $user->avatar,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'group' => $user->group,
                'quota' => $user->quota,
                'used_quota' => $user->used_quota,
                'request_count' => $user->request_count,
                'aff_code' => $user->aff_code,
                'inviter_id' => $user->inviter_id,
                'setting' => $user->setting,
                'sidebar_modules' => $setting['sidebar_modules'] ?? null,
                'news_keys_masked' => $maskedNewsKeys,
                'permissions' => [
                    'sidebar_settings' => $user->role !== 100,
                ],
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
            ],
        ]);
    }

    /**
     * PUT /api/user/self - Update the authenticated user's profile.
     */
    public function updateSelf(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $data = $request->only(['display_name', 'avatar']);

        if ($request->has('password') && $request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * DELETE /api/user/self - Delete the authenticated user's account.
     */
    public function deleteSelf(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account deleted',
        ]);
    }

    /**
     * GET /api/user/self/groups - Return the user's groups.
     */
    public function groups(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $group = $user->group ?? 'default';

        return response()->json([
            'success' => true,
            'data' => [$group],
        ]);
    }

    /**
     * PUT /api/user/news-keys - Update the user's news API keys.
     */
    public function updateNewsKeys(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $setting = is_string($user->setting) ? (json_decode($user->setting, true) ?? []) : ($user->setting ?? []);
        $newsKeys = $setting['news_keys'] ?? [];

                $fields = ['news_google_key', 'news_newsapi_key', 'news_tavily_key', 'news_exa_key', 'news_brave_key'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                // Empty string means delete the key
                if ($value === '' || $value === null) {
                    unset($newsKeys[$field]);
                } else {
                    $newsKeys[$field] = $value;
                }
            }
        }

        $setting['news_keys'] = $newsKeys;
        $user->setting = json_encode($setting, JSON_UNESCAPED_UNICODE);
        $user->save();

                $maskedNewsKeys = [
            'news_google_key' => self::maskKey($newsKeys['news_google_key'] ?? ''),
            'news_newsapi_key' => self::maskKey($newsKeys['news_newsapi_key'] ?? ''),
            'news_tavily_key' => self::maskKey($newsKeys['news_tavily_key'] ?? ''),
            'news_exa_key' => self::maskKey($newsKeys['news_exa_key'] ?? ''),
            'news_brave_key' => self::maskKey($newsKeys['news_brave_key'] ?? ''),
        ];

        return response()->json([
            'success' => true,
            'message' => 'News API keys updated',
            'news_keys_masked' => $maskedNewsKeys,
        ]);
    }

    /**
     * Mask a secret key, showing only the last 4 characters.
     */
    private static function maskKey(string $key): string
    {
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 4) {
            return str_repeat('*', strlen($key));
        }

        return str_repeat('*', strlen($key) - 4) . substr($key, -4);
    }

    public function index(Request $request)
    {
        $query = User::query();
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        }

        return response()->json($query->orderBy('created_time', 'desc')->paginate(20));
    }

    public function show(int $id)
    {
        $user = User::findOrFail($id);

        return response()->json($user);
    }

    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        $data = $request->only(['username', 'email', 'status', 'role', 'balance']);
        if ($request->has('password')) {
            $data['password'] = Hash::make($request->password);
        }
        $user->update($data);

        return response()->json($user);
    }

    public function destroy(int $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => __('User deleted')]);
    }

    public function updateBalance(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        $amount = $request->input('amount', 0);
        if ($amount > 0) {
            $user->increment('balance', $amount);
        } elseif ($amount < 0) {
            $user->decrement('balance', abs($amount));
        }

        return response()->json($user);
    }
}

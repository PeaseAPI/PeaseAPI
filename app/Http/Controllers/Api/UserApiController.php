<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SmsCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserApiController extends Controller
{
    public function me()
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'display_name' => $user->display_name,
            'avatar' => $user->avatar_url,
            'role' => $user->role,
            'status' => $user->status,
            'quota' => $user->quota,
            'used_quota' => $user->used_quota,
            'balance' => $user->quota - $user->used_quota,
            'request_count' => $user->request_count,
            'group' => $user->group,
            'aff_code' => $user->aff_code,
            'created_at' => $user->created_at,
            'last_login_at' => $user->last_login_at,
            'is_admin' => $user->role >= 100,
        ]);
    }

    public function updateProfile(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'username' => 'nullable|string|min:3|max:32|alpha_num|unique:users,username,' . $user->id,
            'display_name' => 'nullable|string|max:50',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
        ]);

        if (array_key_exists('username', $validated) && $validated['username'] !== null) {
            $user->username = $validated['username'];
        }
        if (array_key_exists('display_name', $validated)) {
            $user->display_name = $validated['display_name'];
        }
        if (!empty($validated['email'])) {
            $user->email = $validated['email'];
        }

        $user->save();

        return response()->json([
            'message' => '个人信息已更新',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'display_name' => $user->display_name,
                'avatar' => $user->avatar_url,
            ],
        ]);
    }

    public function updateAvatar(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,gif,webp|max:2048',
        ]);

        $file = $request->file('avatar');
        $ext = $file->getClientOriginalExtension();
        $filename = $user->id . '_' . Str::random(10) . '.' . $ext;
        $relativePath = 'avatars/' . $filename;

        // 确保目录存在
        $destDir = public_path('avatars');
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }

        // 删除旧头像（仅本地文件，不处理 http 外链）
        if ($user->avatar && !preg_match('#^https?://#i', $user->avatar)) {
            $oldFile = public_path($user->avatar);
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }

        // 直接存储到 public/avatars 下，不再依赖 storage:link 软链接
        $file->move($destDir, $filename);
        $user->avatar = $relativePath;
        $user->save();

        return response()->json([
            'message' => '头像更新成功',
            'avatar' => $user->avatar_url,
        ]);
    }

    public function updatePhone(Request $request, SmsCodeService $smsCodeService)
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'phone' => 'required|string|regex:/^1[3-9]\d{9}$/',
            'sms_code' => 'required|string|digits:6',
        ]);

        // 校验验证码
        if (!$smsCodeService->verify($validated['phone'], $validated['sms_code'])) {
            return response()->json(['error' => '验证码错误或已过期'], 400);
        }

        // 检查手机号是否已被其他用户占用
        $exists = User::where('phone', $validated['phone'])->where('id', '!=', $user->id)->exists();
        if ($exists) {
            return response()->json(['error' => '该手机号已被其他账号绑定'], 400);
        }

        $user->phone = $validated['phone'];
        $user->save();

        return response()->json([
            'message' => '手机号更新成功',
            'phone' => $user->phone,
        ]);
    }

    public function updatePassword(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['error' => '当前密码不正确'], 400);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json(['message' => '密码已更新']);
    }

    // Admin only - User list
    public function index(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        if ($user->role < 100) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        $query = User::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($users);
    }

    public function show(int $id)
    {
        /** @var User $user */
        $user = Auth::user();
        if ($user->role < 100) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        $targetUser = User::with(['tokens', 'subscriptions'])->findOrFail($id);
        return response()->json($targetUser);
    }

    public function update(Request $request, int $id)
    {
        /** @var User $user */
        $user = Auth::user();
        if ($user->role < 100) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        $targetUser = User::findOrFail($id);

        $validated = $request->validate([
            'username' => 'sometimes|string|min:3|max:32|alpha_num|unique:users,username,' . $id,
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'status' => 'sometimes|integer|in:0,1',
            'role' => 'sometimes|integer',
            'quota' => 'sometimes|integer|min:0',
        ]);

        if (isset($validated['quota'])) {
            $targetUser->quota = $validated['quota'];
        }
        if (isset($validated['status'])) {
            $targetUser->status = $validated['status'];
        }
        if (isset($validated['role'])) {
            $targetUser->role = $validated['role'];
        }
        if (isset($validated['username'])) {
            $targetUser->username = $validated['username'];
        }
        if (isset($validated['email'])) {
            $targetUser->email = $validated['email'];
        }

        $targetUser->save();

        return response()->json(['message' => 'User updated', 'user' => $targetUser]);
    }

    public function destroy(int $id)
    {
        /** @var User $user */
        $user = Auth::user();
        if ($user->role < 100) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        $targetUser = User::findOrFail($id);
        if ($targetUser->id === $user->id) {
            return response()->json(['error' => 'Cannot delete yourself'], 400);
        }

        $targetUser->delete();

        return response()->json(['message' => 'User deleted']);
    }

    public function updateBalance(Request $request, int $id)
    {
        /** @var User $user */
        $user = Auth::user();
        if ($user->role < 100) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        $targetUser = User::findOrFail($id);
        $amount = $request->input('amount', 0);

        if ($amount > 0) {
            $targetUser->increment('quota', $amount);
        } elseif ($amount < 0) {
            $absAmount = abs($amount);
            if ($targetUser->quota < $absAmount) {
                return response()->json(['error' => 'Insufficient balance'], 400);
            }
            $targetUser->decrement('quota', $absAmount);
        }

        return response()->json(['message' => 'Balance updated', 'user' => $targetUser]);
    }

    public function resetPassword(Request $request, int $id)
    {
        /** @var User $user */
        $user = Auth::user();
        if ($user->role < 100) {
            return response()->json(['error' => 'Admin access required'], 403);
        }

        $targetUser = User::findOrFail($id);

        $newPassword = $request->input('password', substr(md5(uniqid()), 0, 12));
        $targetUser->password = Hash::make($newPassword);
        $targetUser->save();

        return response()->json(['message' => 'Password reset', 'new_password' => $newPassword]);
    }
}
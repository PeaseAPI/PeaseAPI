<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
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
        return response()->json(['message' => 'User deleted']);
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
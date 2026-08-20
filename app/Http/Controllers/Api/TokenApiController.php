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
            'quota_quota' => 'nullable|integer',
            'unlimited_quota' => 'nullable|boolean',
            'expired_at' => 'nullable|integer',
        ]);

        $validated['key'] = 'sk-'.bin2hex(random_bytes(24));
        $validated['user_id'] = $user->id;
        $validated['status'] = 1;
        $validated['created_at'] = time();

        $token = Token::create($validated);

        if ($request->has('ability_ids')) {
            $token->abilities()->attach($request->ability_ids);
        }

        return response()->json($token->load('abilities'), 201);
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
            'quota_quota' => 'nullable|integer',
            'unlimited_quota' => 'nullable|boolean',
            'expired_at' => 'nullable|integer',
        ]);

        $token->update($validated);

        if ($request->has('ability_ids')) {
            $token->abilities()->sync($request->ability_ids);
        }

        return response()->json($token->load('abilities'));
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

<?php

namespace App\Http\Controllers;

use App\Models\Token;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Token::with('abilities');
        if ($user->role < 100) {
            $query->where('user_id', $user->id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('created_time', 'desc')->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->all();
        $data['key'] = $data['key'] ?? 'sk-'.bin2hex(random_bytes(24));
        $data['user_id'] = $data['user_id'] ?? $request->user()->id;
        $data['created_at'] = time();

        $token = Token::create($data);
        if ($request->has('ability_ids')) {
            $token->abilities()->attach($request->ability_ids);
        }

        return response()->json($token->load('abilities'), 201);
    }

    public function show(int $id)
    {
        return response()->json(Token::with('abilities')->findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $token = Token::findOrFail($id);
        $token->update($request->all());
        if ($request->has('ability_ids')) {
            $token->abilities()->sync($request->ability_ids);
        }

        return response()->json($token->load('abilities'));
    }

    public function destroy(int $id)
    {
        Token::findOrFail($id)->delete();

        return response()->json(['message' => __('Token deleted')]);
    }
}

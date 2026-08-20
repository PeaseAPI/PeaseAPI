<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Token;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Get user stats
        $stats = [
            'balance' => $user->quota - $user->used_quota,
            'total_quota' => $user->quota,
            'used_quota' => $user->used_quota,
            'request_count' => $user->request_count,
            'token_count' => Token::where('user_id', $user->id)->count(),
        ];

        // Recent logs (logs table uses created_at)
        $recentLogs = Log::with(['token', 'channel'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Recent tokens
        $recentTokens = Token::where('user_id', $user->id)
            ->orderBy('created_time', 'desc')
            ->limit(5)
            ->get();

        return view('dashboard.index', compact('user', 'stats', 'recentLogs', 'recentTokens'));
    }

    public function tokens()
    {
        return view('dashboard.tokens');
    }

    public function tokenCreate()
    {
        return view('dashboard.token-create');
    }

    public function tokenEdit(int $id)
    {
        return view('dashboard.token-edit', ['tokenId' => $id]);
    }

    public function logs()
    {
        return view('dashboard.logs');
    }

    public function redeem()
    {
        return view('dashboard.redeem');
    }

    public function profile()
    {
        return view('dashboard.profile');
    }

    public function settings()
    {
        return view('dashboard.settings');
    }
}

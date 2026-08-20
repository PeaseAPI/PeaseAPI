<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Log;
use App\Models\Token;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function dashboard()
    {
        $user = Auth::user();
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', 1)->count(),
            'total_channels' => Channel::count(),
            'active_channels' => Channel::where('status', 1)->count(),
            'total_tokens' => Token::count(),
            'active_tokens' => Token::where('status', 1)->count(),
            'total_requests' => User::sum('request_count'),
            'total_used_quota' => User::sum('used_quota'),
        ];

        $recentLogs = Log::with(['user', 'token', 'channel'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentUsers = User::orderBy('created_at', 'desc')->limit(5)->get();

        return view('admin.dashboard', compact('user', 'stats', 'recentLogs', 'recentUsers'));
    }

    public function users(Request $request)
    {
        return view('admin.users');
    }

    public function channels()
    {
        return view('admin.channels');
    }

    public function tokens()
    {
        return view('admin.tokens');
    }

    public function abilities()
    {
        return view('admin.abilities');
    }

    public function logs()
    {
        return view('admin.logs');
    }

    public function redemptions()
    {
        return view('admin.redemptions');
    }

    public function options()
    {
        return view('admin.options');
    }

    public function systemSettings()
    {
        return view('admin.system-settings');
    }
}

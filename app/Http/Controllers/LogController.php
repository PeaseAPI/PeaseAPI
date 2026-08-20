<?php

namespace App\Http\Controllers;

use App\Services\LogService;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function __construct(protected LogService $logService) {}

    public function index(Request $request)
    {
        $filters = $request->only([
            'user_id', 'token_id', 'channel_id', 'type',
            'model', 'start_time', 'end_time', 'request_id',
        ]);

        if ($request->user()->role < 100) {
            $filters['user_id'] = $request->user()->id;
        }

        return response()->json($this->logService->getLogs($filters, $request->input('per_page', 20)));
    }
}

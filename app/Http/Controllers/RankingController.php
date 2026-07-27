<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UsedataRankings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 排行榜控制器 - 对标 new-api controller/ranking.go
 */
class RankingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->input('type', 'user');
        $limit = min((int) $request->input('limit', 100), 500);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = UsedataRankings::query()
            ->when($type, fn ($q, $v) => $q->where('type', $v))
            ->when($startDate, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($endDate, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('quota')
            ->limit($limit);

        return $this->success($query->get());
    }
}
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Option;
use App\Models\PrefillGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 分组管理控制器 - 对标 new-api controller/group.go
 * 包含用户分组列表与预填组 CRUD
 */
class GroupController extends Controller
{
    /**
     * 获取所有分组列表（管理员）
     */
    public function index(): JsonResponse
    {
        // 用户分组
        $userGroups = User::query()
            ->select('group', DB::raw('COUNT(*) as user_count'))
            ->groupBy('group')
            ->pluck('user_count', 'group');

        // 渠道分组
        $channelGroups = Channel::query()
            ->select('group', DB::raw('COUNT(*) as channel_count'))
            ->groupBy('group')
            ->pluck('channel_count', 'group');

        // 分组倍率
        $groupRatioOption = Option::where('key', 'GroupRatio')->value('value');
        $groupRatio = [];
        if ($groupRatioOption) {
            $decoded = json_decode($groupRatioOption, true);
            if (is_array($decoded)) {
                $groupRatio = $decoded;
            }
        }

        // 预填组
        $prefillGroups = PrefillGroup::orderByDesc('id')->get();

        $groups = [];
        $allGroups = $userGroups->keys()
            ->merge($channelGroups->keys())
            ->merge(collect($groupRatio)->keys())
            ->unique();

        foreach ($allGroups as $group) {
            $groups[] = [
                'name' => $group,
                'user_count' => $userGroups->get($group, 0),
                'channel_count' => $channelGroups->get($group, 0),
                'ratio' => $groupRatio[$group] ?? 1,
            ];
        }

        return $this->success([
            'groups' => $groups,
            'prefill_groups' => $prefillGroups,
        ]);
    }

    /**
     * 获取预填组列表（管理员）
     */
    public function prefillIndex(): JsonResponse
    {
        return $this->success(PrefillGroup::orderByDesc('id')->get());
    }

    public function prefillStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
        ]);

        return $this->success(PrefillGroup::create($data), '预填组已创建');
    }

    public function prefillUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
        ]);

        $group = PrefillGroup::find($data['id']);
        if (! $group) {
            return $this->error('预填组不存在', 404);
        }

        $group->update($data);

        return $this->success($group->refresh(), '预填组已更新');
    }

    public function prefillDestroy(int $id): JsonResponse
    {
        $group = PrefillGroup::find($id);
        if (! $group) {
            return $this->error('预填组不存在', 404);
        }

        $group->delete();

        return $this->success(null, '预填组已删除');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ability;
use App\Models\Channel;
use App\Models\PrefillGroup;
use App\Services\ChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 渠道能力 / 分组 / 预填组 控制器
 *
 * 对齐 Go 版 controller/ability.go + controller/group.go + controller/prefill_group.go
 * 表 abilities 字段: id, `group`, model, channel_id, enabled, priority
 */
class AbilityController extends Controller
{
    public function __construct(
        private readonly ChannelService $channelService,
    ) {
    }

    // ===== Abilities =====

    /**
     * 能力列表（支持 group/model/channel_id/enabled 过滤）
     */
    public function index(Request $request): JsonResponse
    {
        $query = Ability::query()->with('channel:id,name,type');

        if ($request->filled('group')) {
            $query->byGroup((string) $request->input('group'));
        }
        if ($request->filled('model')) {
            $query->byModel((string) $request->input('model'));
        }
        if ($request->filled('channel_id')) {
            $query->byChannel((int) $request->input('channel_id'));
        }
        if ($request->filled('enabled')) {
            $query->where('enabled', (int) $request->input('enabled'));
        }

        $perPage = (int) $request->integer('per_page', 50);
        $paginator = $query
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->paginate($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $ability = Ability::with('channel:id,name,type')->findOrFail($id);

        return $this->success($ability);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAbility($request);
        $ability = Ability::create($data);
        $this->channelService->clearChannelCache();

        return $this->success($ability, '创建成功');
    }

    /**
     * 更新能力（路由 PUT /abilities/{id}）
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'group'      => ['sometimes', 'string', 'max:64'],
            'model'      => ['sometimes', 'string', 'max:255'],
            'channel_id' => ['sometimes', 'integer', 'exists:channels,id'],
            'enabled'    => ['sometimes', 'integer', 'in:0,1'],
            'priority'   => ['sometimes', 'integer'],
        ]);

        $ability = Ability::findOrFail($id);
        $ability->update($data);
        $this->channelService->clearChannelCache();

        return $this->success($ability->fresh(), '更新成功');
    }

    public function destroy(int $id): JsonResponse
    {
        Ability::findOrFail($id)->delete();
        $this->channelService->clearChannelCache();

        return $this->success(null, '删除成功');
    }

    /**
     * 批量同步能力表
     * - channel_ids: 指定渠道 ID 数组；为空则重建全部渠道能力表
     */
    public function batchSync(Request $request): JsonResponse
    {
        $channelIds = array_filter(
            array_map('intval', (array) $request->input('channel_ids', []))
        );

        if (empty($channelIds)) {
            $count = $this->channelService->fixAllAbilities();

            return $this->success(
                ['rebuilt_channels' => $count],
                '已重建全部渠道能力表'
            );
        }

        $count = 0;
        Channel::whereIn('id', $channelIds)->chunkById(100, function ($channels) use (&$count): void {
            foreach ($channels as $channel) {
                $this->channelService->syncAbilities($channel);
                $count++;
            }
        });

        return $this->success(
            ['rebuilt_channels' => $count],
            '批量同步完成'
        );
    }

    // ===== Groups =====

    /**
     * 获取全部分组（从渠道 group 字段去重）
     */
    public function groups(): JsonResponse
    {
        $groups = Channel::whereNotNull('group')
            ->pluck('group')
            ->flatMap(fn ($g) => explode(',', (string) $g))
            ->map(fn ($g) => trim($g))
            ->filter()
            ->unique()
            ->values();

        return $this->success($groups);
    }

    // ===== Prefill Groups =====

    public function prefillGroups(Request $request): JsonResponse
    {
        $query = PrefillGroup::query();

        if ($request->filled('keyword')) {
            $kw = (string) $request->input('keyword');
            $query->where(function ($q) use ($kw): void {
                $q->where('name', 'like', "%{$kw}%")
                  ->orWhere('description', 'like', "%{$kw}%");
            });
        }

        $paginator = $query->orderByDesc('id')->paginate(
            (int) $request->integer('per_page', 20)
        );

        return $this->paginate($paginator);
    }

    public function createPrefillGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'prefills'    => ['nullable', 'array'],
        ]);

        $prefill = PrefillGroup::create($data);

        return $this->success($prefill, '创建成功');
    }

    /**
     * 更新预填组（路由 PUT /prefill_group/ 无 id 参数，从 body 取）
     */
    public function updatePrefillGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'          => ['required', 'integer', 'exists:prefill_groups,id'],
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'prefills'    => ['nullable', 'array'],
        ]);

        $prefill = PrefillGroup::findOrFail($data['id']);
        $prefill->update(collect($data)->except('id')->toArray());

        return $this->success($prefill->fresh(), '更新成功');
    }

    public function deletePrefillGroup(int $id): JsonResponse
    {
        PrefillGroup::findOrFail($id)->delete();

        return $this->success(null, '删除成功');
    }

    // ===== Private =====

    private function validateAbility(Request $request): array
    {
        return $request->validate([
            'group'      => ['required', 'string', 'max:64'],
            'model'      => ['required', 'string', 'max:255'],
            'channel_id' => ['required', 'integer', 'exists:channels,id'],
            'enabled'    => ['sometimes', 'integer', 'in:0,1'],
            'priority'   => ['sometimes', 'integer'],
        ]);
    }
}
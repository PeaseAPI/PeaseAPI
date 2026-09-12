<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ability;
use App\Models\Channel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixAbilities extends Command
{
    protected $signature = 'ability:fix';

    protected $description = 'Fix ability table based on channels';

    public function handle(): int
    {
        $this->info('Fixing abilities...');
        $rows = [];
        foreach (Channel::where('status', 1)->get() as $ch) {
            // models 列为 array cast（JSON 数组）；历史/兼容场景可能是逗号分隔字符串。
            // 原实现直接 explode() 导致数组输入时 TypeError，且 truncate 已执行 →
            // abilities 表被清空后无法重建（destructive partial failure）。
            $raw = $ch->models ?? [];
            $models = is_array($raw)
                ? array_filter(array_map('trim', array_map('strval', $raw)), 'strlen')
                : array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen');

            foreach ($models as $model) {
                $rows[] = ['group' => $ch->group ?? 'default', 'model' => $model, 'channel_id' => $ch->id, 'enabled' => 1, 'priority' => $ch->priority ?? 0];
            }
        }

        // 先完整构建行集，再在同一事务内清空+重建；DELETE（DML，可回滚）替代
        // TRUNCATE（DDL 隐式提交），保证中途异常时旧能力表不被破坏。
        DB::transaction(function () use ($rows): void {
            DB::table('abilities')->delete();
            foreach ($rows as $row) {
                Ability::create($row);
            }
        });

        $this->info('Created '.count($rows).' abilities.');

        return 0;
    }
}

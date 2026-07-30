<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * 清理 users.avatar 列中的历史脏数据。
 *
 * 旧版代码可能将 data: URL（base64）直接写入数据库，导致浏览器报
 * "Data URL decoding failed" / 404 等错误。本命令将这类无效值清空，
 * 让前端回退到首字母占位符；同时清理指向已不存在文件的本地路径。
 */
class CleanAvatarData extends Command
{
    protected $signature = 'pease:clean-avatar {--dry-run : 仅输出将被清理的记录，不实际修改}';
    protected $description = '清理用户头像字段中的 data: URL 等历史脏数据';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cleaned = 0;
        $checked = 0;

        User::whereNotNull('avatar')->where('avatar', '!=', '')->chunkById(200, function ($users) use (&$cleaned, &$checked, $dryRun) {
            foreach ($users as $user) {
                $checked++;
                $avatar = (string) $user->avatar;
                $bad = false;
                $reason = '';

                // 1) data: URL — 必须清理
                if (stripos($avatar, 'data:') === 0) {
                    $bad = true;
                    $reason = 'data-url';
                }
                // 2) 本地相对路径但文件不存在（排除 http 外链）
                elseif (!preg_match('#^https?://#i', $avatar) && strpos($avatar, '//') !== 0) {
                    $path = public_path(ltrim($avatar, '/'));
                    if (!is_file($path)) {
                        $bad = true;
                        $reason = 'missing-file';
                    }
                }

                if (!$bad) {
                    continue;
                }

                $this->line(sprintf(
                    '[%s] id=%d username=%s reason=%s avatar=%s',
                    $dryRun ? 'DRY' : 'FIX',
                    $user->id,
                    $user->username,
                    $reason,
                    mb_substr($avatar, 0, 60) . (mb_strlen($avatar) > 60 ? '…' : '')
                ));

                if (!$dryRun) {
                    $user->avatar = '';
                    $user->save();
                }
                $cleaned++;
            }
        });

        $this->info(sprintf('已检查 %d 条记录，%s %d 条脏数据。', $checked, $dryRun ? '发现' : '已清理', $cleaned));
        return self::SUCCESS;
    }
}
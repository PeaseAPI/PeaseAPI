<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ability;
use App\Models\Channel;

class FixAbilities extends Command
{
    protected $signature = "ability:fix";
    protected $description = "Fix ability table based on channels";

    public function handle(): int
    {
        $this->info("Fixing abilities...");
        Ability::truncate();
        $channels = Channel::where("status", 1)->get();
        $count = 0;
        foreach ($channels as $ch) {
            $models = explode(",", $ch->models ?? "");
            foreach ($models as $model) {
                $model = trim($model);
                if ($model) {
                    Ability::create(["group" => $ch->group ?? "default", "model" => $model, "channel_id" => $ch->id, "enabled" => 1, "priority" => $ch->priority ?? 0]);
                    $count++;
                }
            }
        }
        $this->info("Created " . $count . " abilities.");
        return 0;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class PluginMigrateStatus extends Command
{
    protected $signature = 'plugin:migrate:status {plugin} {--path=}';
    protected $description = 'Show the status of each plugin migration';

    public function handle()
    {
        $plugin = $this->argument('plugin');
        $path = $this->option('path') ?? "plugins/{$plugin}/migrations";

        if (!File::exists(base_path($path))) {
            $this->error("Migration path does not exist: {$path}");
            return 1;
        }

        if (!Schema::hasTable('plugin_migrations')) {
            $this->error('Plugin migrations table does not exist. Please run plugin migrations first.');
            return 1;
        }

        $files = File::glob(base_path($path) . '/*.php');
        $ran = DB::table('plugin_migrations')
            ->where('plugin', $plugin)
            ->pluck('migration')
            ->toArray();

        $rows = [];
        foreach ($files as $file) {
            $migrationName = basename($file);
            $status = in_array($migrationName, $ran);

            if ($status) {
                $batch = DB::table('plugin_migrations')
                    ->where('plugin', $plugin)
                    ->where('migration', $migrationName)
                    ->value('batch');

                $rows[] = [
                    $migrationName,
                    "Ran (Batch {$batch})"
                ];
            } else {
                $rows[] = [
                    $migrationName,
                    'Pending'
                ];
            }
        }

        $this->table(
            ['Migration name', 'Batch / Status'],
            $rows
        );

        return 0;
    }
}

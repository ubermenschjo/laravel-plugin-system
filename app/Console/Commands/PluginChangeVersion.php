<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
class PluginChangeVersion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugin:version {plugin} {version} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Change plugin version';

    /**
     * Execute the console command.
     */
    public function handle(PluginManager $pluginManager)
    {
        $pluginName = $this->argument('plugin');
        $version = $this->argument('version');
        $force = $this->option('force');

        if (!$force && !$this->confirm("Are you sure you want to change {$pluginName}'s version to {$version}?")) {
            return;
        }

        try {
            $pluginManager->changeVersion($pluginName, $version);
            $this->info("Successfully changed {$pluginName}'s version to {$version}");
        } catch (\Exception $e) {
            Log::error("Plugin version change failed: " . $e->getMessage(), ['exception' => $e]);
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
} 
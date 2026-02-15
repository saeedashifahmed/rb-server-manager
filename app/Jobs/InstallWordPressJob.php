<?php

namespace App\Jobs;

use App\Models\Installation;
use App\Models\Server;
use App\Services\ScriptBuilder;
use App\Services\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InstallWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum attempts before marking as failed.
     */
    public int $tries = 1;

    /**
     * Maximum execution time in seconds (15 minutes).
     */
    public int $timeout = 900;

    public function __construct(
        private readonly int $installationId,
        private readonly int $serverId,
    ) {}

    public function handle(): void
    {
        $installation = Installation::findOrFail($this->installationId);
        $server = Server::findOrFail($this->serverId);

        // Guard: don't re-run if already completed or still installing
        if (! $installation->isPending()) {
            Log::info("InstallWordPressJob: Installation #{$this->installationId} is not pending, skipping.");
            return;
        }

        $installation->update([
            'status'     => Installation::STATUS_INSTALLING,
            'started_at' => now(),
        ]);
        $installation->appendLog('Starting WordPress + SSL installation...');

        $ssh = new SSHService();

        try {
            // Connect to server
            $installation->markStep('Connecting to server', 2);
            $ssh->connect($server);
            $installation->appendLog("Connected to {$server->ip_address}:{$server->ssh_port}");

            // Build scripts
            $builder = new ScriptBuilder(
                domain: $installation->domain,
                adminEmail: $installation->admin_email,
                siteTitle: $installation->site_title,
            );

            // Store DB credentials on the installation record
            $installation->update([
                'wp_db_name' => $builder->getDbName(),
                'wp_db_user' => $builder->getDbUser(),
            ]);

            // Execute each step in sequence
            $steps = ScriptBuilder::getSteps();

            foreach ($steps as $step) {
                $installation->markStep($step['name'], $step['progress']);

                try {
                    $command = $builder->{$step['method']}();
                    $output = $ssh->executeOrFail($command);

                    // Log truncated output to avoid bloating the DB
                    $truncatedOutput = mb_substr($output, 0, 2000);
                    $installation->appendLog("✓ {$step['name']}\n{$truncatedOutput}");
                } catch (RuntimeException $e) {
                    throw new RuntimeException(
                        "Failed at step '{$step['name']}': " . $e->getMessage()
                    );
                }
            }

            // Mark success
            $wpAdminUrl = $builder->getWpAdminUrl();
            $installation->markSuccess($wpAdminUrl);

            Log::info("InstallWordPressJob: Installation #{$this->installationId} completed successfully.");

        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            $installation->markFailed($errorMessage);

            Log::error("InstallWordPressJob: Installation #{$this->installationId} failed.", [
                'error'   => $errorMessage,
                'trace'   => mb_substr($e->getTraceAsString(), 0, 1000),
            ]);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Handle a job failure (queue system level failure).
     */
    public function failed(?Throwable $exception): void
    {
        $installation = Installation::find($this->installationId);

        if ($installation) {
            $installation->markFailed(
                'Job failed: ' . ($exception?->getMessage() ?? 'Unknown error')
            );
        }

        Log::error("InstallWordPressJob: Job failed for installation #{$this->installationId}", [
            'error' => $exception?->getMessage(),
        ]);
    }
}

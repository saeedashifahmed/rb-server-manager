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
     * Maximum execution time in seconds (25 minutes — long apt operations can be slow).
     */
    public int $timeout = 1500;

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
            $installation->markStep('Connecting to server', 1);
            $ssh->connect($server);
            $installation->appendLog("Connected to {$server->ip_address}:{$server->ssh_port}");

            // Detect PHP version preference
            $phpVersion = $installation->php_version ?? ScriptBuilder::DEFAULT_PHP_VERSION;

            // Build scripts with the selected PHP version
            $builder = new ScriptBuilder(
                domain: $installation->domain,
                adminEmail: $installation->admin_email,
                siteTitle: $installation->site_title,
                phpVersion: $phpVersion,
            );

            // Store DB credentials and resolved PHP version on the installation record
            $installation->update([
                'wp_db_name'  => $builder->getDbName(),
                'wp_db_user'  => $builder->getDbUser(),
                'php_version' => $builder->getPhpVersion(),
            ]);

            $installation->appendLog("Target PHP version: {$builder->getPhpVersion()}");

            // Execute each step in sequence
            $steps = ScriptBuilder::getSteps();
            $totalSteps = count($steps);

            foreach ($steps as $index => $step) {
                $stepNumber = $index + 1;
                $installation->markStep("{$step['name']} ({$stepNumber}/{$totalSteps})", $step['progress']);

                try {
                    $command = $builder->{$step['method']}();
                    $stepTimeout = $step['timeout'] ?? 300;

                    $output = $ssh->executeOrFail($command, $stepTimeout);

                    // Log truncated output to avoid bloating the DB
                    $truncatedOutput = mb_substr($output, 0, 2000);
                    $installation->appendLog("✓ {$step['name']}\n{$truncatedOutput}");
                } catch (RuntimeException $e) {
                    throw new RuntimeException(
                        "Failed at step '{$step['name']}' ({$stepNumber}/{$totalSteps}): " . $e->getMessage()
                    );
                }
            }

            // Mark success
            $wpAdminUrl = $builder->getWpAdminUrl();
            $installation->markSuccess($wpAdminUrl);

            Log::info("InstallWordPressJob: Installation #{$this->installationId} completed successfully.", [
                'domain'      => $installation->domain,
                'php_version' => $builder->getPhpVersion(),
            ]);

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

<?php

namespace App\Services;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

class SSHService
{
    private ?SSH2 $connection = null;
    private ?Server $server = null;

    private const CONNECT_TIMEOUT  = 30;
    private const DEFAULT_TIMEOUT  = 600;  // 10 minutes default for long-running apt operations
    private const MAX_RECONNECTS   = 2;

    /**
     * Establish an SSH connection to the given server.
     *
     * @throws RuntimeException
     */
    public function connect(Server $server): self
    {
        $this->server = $server;
        $this->doConnect();

        return $this;
    }

    /**
     * Internal connection method — used for initial connect and reconnects.
     */
    private function doConnect(): void
    {
        if ($this->server === null) {
            throw new RuntimeException('No server configured. Call connect() first.');
        }

        $ssh = new SSH2($this->server->ip_address, $this->server->ssh_port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(self::DEFAULT_TIMEOUT);

        // Enable quiet mode to avoid throwing on stderr
        $ssh->enableQuietMode();

        $authenticated = false;

        if ($this->server->hasSshPrivateKey()) {
            $key = PublicKeyLoader::load($this->server->ssh_private_key);
            $authenticated = $ssh->login($this->server->ssh_username, $key);
        } elseif ($this->server->hasSshPassword()) {
            $authenticated = $ssh->login($this->server->ssh_username, $this->server->ssh_password);
        } else {
            throw new RuntimeException('Server has no SSH authentication method configured.');
        }

        if (! $authenticated) {
            throw new RuntimeException(
                "SSH authentication failed for {$this->server->ssh_username}@{$this->server->ip_address}:{$this->server->ssh_port}"
            );
        }

        $this->connection = $ssh;
    }

    /**
     * Execute a command on the connected server.
     * Returns the command output as a string.
     *
     * @param  int|null  $timeout  Override the default timeout in seconds.
     * @throws RuntimeException
     */
    public function execute(string $command, ?int $timeout = null): string
    {
        $this->ensureConnected();

        if ($timeout !== null) {
            $this->connection->setTimeout($timeout);
        }

        $output = $this->connection->exec($command);

        // Restore default timeout
        if ($timeout !== null) {
            $this->connection->setTimeout(self::DEFAULT_TIMEOUT);
        }

        if ($output === false) {
            throw new RuntimeException("Failed to execute command: " . mb_substr($command, 0, 200));
        }

        return $output;
    }

    /**
     * Execute a command and check exit status.
     * Throws on non-zero exit code. Supports per-step timeouts
     * and automatic reconnection if the connection drops.
     *
     * @param  int|null  $timeout  Override the default timeout in seconds.
     * @throws RuntimeException
     */
    public function executeOrFail(string $command, ?int $timeout = null): string
    {
        $wrappedCommand = $command . "\nEXIT_STATUS=$?\necho \"EXIT_CODE:$EXIT_STATUS\"";

        $attempts = 0;
        $lastException = null;

        while ($attempts <= self::MAX_RECONNECTS) {
            try {
                $output = $this->execute($wrappedCommand, $timeout);
                break;
            } catch (RuntimeException $e) {
                $lastException = $e;
                $attempts++;

                // If connection dropped, try to reconnect
                if ($attempts <= self::MAX_RECONNECTS && $this->server !== null) {
                    try {
                        $this->disconnect();
                        sleep(2);
                        $this->doConnect();
                        continue;
                    } catch (RuntimeException) {
                        // Reconnection failed
                    }
                }

                throw new RuntimeException(
                    "Failed to execute command after {$attempts} attempt(s): " . $e->getMessage()
                );
            }
        }

        // Parse exit code from output
        $lines = explode("\n", trim($output));
        $exitCode = null;

        // Search for EXIT_CODE line from the end (it may not be the very last line)
        for ($i = count($lines) - 1; $i >= max(0, count($lines) - 5); $i--) {
            if (preg_match('/^EXIT_CODE:(\d+)$/', trim($lines[$i]), $matches)) {
                $exitCode = (int) $matches[1];
                // Remove the EXIT_CODE line from output
                array_splice($lines, $i, 1);
                break;
            }
        }

        $output = implode("\n", $lines);

        if ($exitCode !== null && $exitCode !== 0) {
            throw new RuntimeException(
                "Command failed (exit code {$exitCode}):\n" . mb_substr($output, -1000)
            );
        }

        return $output;
    }

    /**
     * Upload content as a file to the remote server.
     *
     * @throws RuntimeException
     */
    public function uploadContent(string $content, string $remotePath): void
    {
        $this->ensureConnected();

        $command = "cat > " . escapeshellarg($remotePath) . " << 'RBEOF'\n{$content}\nRBEOF";
        $this->executeOrFail($command);
    }

    /**
     * Check if a command/package exists on the remote server.
     */
    public function commandExists(string $command): bool
    {
        try {
            $output = $this->execute("which {$command} 2>/dev/null", 10);
            return ! empty(trim($output));
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Disconnect from the server.
     */
    public function disconnect(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->disconnect();
            } catch (\Throwable) {
                // Ignore disconnect errors
            }
            $this->connection = null;
        }
    }

    /**
     * Get the underlying SSH2 connection.
     */
    public function getConnection(): ?SSH2
    {
        return $this->connection;
    }

    /**
     * Test connectivity to a server.
     *
     * @throws RuntimeException
     */
    public function testConnection(Server $server): bool
    {
        try {
            $this->connect($server);
            $output = $this->execute('echo "CONNECTION_OK" && uname -a', 15);
            $this->disconnect();

            return str_contains($output, 'CONNECTION_OK');
        } catch (RuntimeException $e) {
            $this->disconnect();
            throw $e;
        }
    }

    private function ensureConnected(): void
    {
        if ($this->connection === null || ! $this->connection->isConnected()) {
            // Try to reconnect if we have server info
            if ($this->server !== null) {
                try {
                    $this->doConnect();
                    return;
                } catch (RuntimeException) {
                    // Fall through to error
                }
            }

            throw new RuntimeException('Not connected to any server. Call connect() first.');
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

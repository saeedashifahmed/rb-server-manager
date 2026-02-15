<?php

namespace App\Services;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

class SSHService
{
    private ?SSH2 $connection = null;

    private const CONNECT_TIMEOUT = 30;
    private const EXEC_TIMEOUT    = 300;

    /**
     * Establish an SSH connection to the given server.
     *
     * @throws RuntimeException
     */
    public function connect(Server $server): self
    {
        $ssh = new SSH2($server->ip_address, $server->ssh_port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(self::EXEC_TIMEOUT);

        $key = PublicKeyLoader::load($server->ssh_private_key);

        if (! $ssh->login($server->ssh_username, $key)) {
            throw new RuntimeException(
                "SSH authentication failed for {$server->ssh_username}@{$server->ip_address}:{$server->ssh_port}"
            );
        }

        $this->connection = $ssh;

        return $this;
    }

    /**
     * Execute a command on the connected server.
     * Returns the command output as a string.
     *
     * @throws RuntimeException
     */
    public function execute(string $command): string
    {
        $this->ensureConnected();

        $output = $this->connection->exec($command);

        if ($output === false) {
            throw new RuntimeException("Failed to execute command: {$command}");
        }

        return $output;
    }

    /**
     * Execute a command and check exit status.
     * Throws on non-zero exit code.
     *
     * @throws RuntimeException
     */
    public function executeOrFail(string $command): string
    {
        $output = $this->execute($command . ' 2>&1; echo "EXIT_CODE:$?"');

        // Parse exit code from output
        $lines = explode("\n", trim($output));
        $lastLine = end($lines);

        if (preg_match('/^EXIT_CODE:(\d+)$/', $lastLine, $matches)) {
            $exitCode = (int) $matches[1];
            $output = implode("\n", array_slice($lines, 0, -1));

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    "Command failed (exit code {$exitCode}): {$command}\nOutput: " . mb_substr($output, 0, 500)
                );
            }
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

        // Use a heredoc approach via SSH to write file contents safely
        $escapedContent = str_replace("'", "'\\''", $content);
        $command = "cat > " . escapeshellarg($remotePath) . " << 'RBEOF'\n{$content}\nRBEOF";

        $this->executeOrFail($command);
    }

    /**
     * Check if a command/package exists on the remote server.
     */
    public function commandExists(string $command): bool
    {
        try {
            $output = $this->execute("which {$command} 2>/dev/null");
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
            $this->connection->disconnect();
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
            $output = $this->execute('echo "CONNECTION_OK"');
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
            throw new RuntimeException('Not connected to any server. Call connect() first.');
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

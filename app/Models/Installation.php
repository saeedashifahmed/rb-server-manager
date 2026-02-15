<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installation extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'user_id',
        'domain',
        'admin_email',
        'site_title',
        'status',
        'current_step',
        'progress',
        'log',
        'wp_admin_url',
        'wp_db_name',
        'wp_db_user',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'progress'     => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ── Status Constants ────────────────────────────────────────

    public const STATUS_PENDING    = 'pending';
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';

    // ── Relationships ───────────────────────────────────────────

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Status Helpers ──────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInstalling(): bool
    {
        return $this->status === self::STATUS_INSTALLING;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function statusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING    => 'yellow',
            self::STATUS_INSTALLING => 'blue',
            self::STATUS_SUCCESS    => 'green',
            self::STATUS_FAILED     => 'red',
            default                 => 'gray',
        };
    }

    // ── Log Helpers ─────────────────────────────────────────────

    public function appendLog(string $message): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $entry = "[{$timestamp}] {$message}\n";

        $this->update([
            'log' => ($this->log ?? '') . $entry,
        ]);
    }

    public function markStep(string $step, int $progress): void
    {
        $this->update([
            'current_step' => $step,
            'progress'     => $progress,
        ]);
        $this->appendLog("Step: {$step} ({$progress}%)");
    }

    public function markSuccess(string $wpAdminUrl): void
    {
        $this->update([
            'status'       => self::STATUS_SUCCESS,
            'progress'     => 100,
            'current_step' => 'Completed',
            'wp_admin_url' => $wpAdminUrl,
            'completed_at' => now(),
        ]);
        $this->appendLog('Installation completed successfully.');
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status'        => self::STATUS_FAILED,
            'current_step'  => 'Failed',
            'error_message' => mb_substr($errorMessage, 0, 1000),
            'completed_at'  => now(),
        ]);
        $this->appendLog("FAILED: {$errorMessage}");
    }
}

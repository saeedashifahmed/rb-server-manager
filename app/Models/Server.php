<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'ip_address',
        'ssh_port',
        'ssh_username',
        'ssh_private_key',
        'ssh_password',
        'ssh_private_key_encrypted',
        'ssh_password_encrypted',
        'status',
    ];

    protected $hidden = [
        'ssh_private_key_encrypted',
        'ssh_password_encrypted',
    ];

    protected $casts = [
        'ssh_port' => 'integer',
    ];

    // ── Accessors & Mutators ────────────────────────────────────

    /**
     * Encrypt the SSH private key before storing.
     */
    public function setSshPrivateKeyAttribute(?string $value): void
    {
        $this->attributes['ssh_private_key_encrypted'] = filled($value)
            ? Crypt::encryptString($value)
            : null;
    }

    /**
     * Decrypt the SSH private key when accessing.
     */
    public function getSshPrivateKeyAttribute(): ?string
    {
        if (empty($this->attributes['ssh_private_key_encrypted'])) {
            return null;
        }

        return Crypt::decryptString($this->attributes['ssh_private_key_encrypted']);
    }

    /**
     * Encrypt the SSH password before storing.
     */
    public function setSshPasswordAttribute(?string $value): void
    {
        $this->attributes['ssh_password_encrypted'] = filled($value)
            ? Crypt::encryptString($value)
            : null;
    }

    /**
     * Decrypt the SSH password when accessing.
     */
    public function getSshPasswordAttribute(): ?string
    {
        if (empty($this->attributes['ssh_password_encrypted'])) {
            return null;
        }

        return Crypt::decryptString($this->attributes['ssh_password_encrypted']);
    }

    // ── Relationships ───────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }

    // ── Helpers ─────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function latestInstallation(): ?Installation
    {
        return $this->installations()->latest()->first();
    }

    public function hasSshPrivateKey(): bool
    {
        return ! empty($this->attributes['ssh_private_key_encrypted']);
    }

    public function hasSshPassword(): bool
    {
        return ! empty($this->attributes['ssh_password_encrypted']);
    }
}

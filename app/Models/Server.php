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
        'ssh_private_key_encrypted',
        'status',
    ];

    protected $hidden = [
        'ssh_private_key_encrypted',
    ];

    protected $casts = [
        'ssh_port' => 'integer',
    ];

    // ── Accessors & Mutators ────────────────────────────────────

    /**
     * Encrypt the SSH private key before storing.
     */
    public function setSshPrivateKeyAttribute(string $value): void
    {
        $this->attributes['ssh_private_key_encrypted'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt the SSH private key when accessing.
     */
    public function getSshPrivateKeyAttribute(): string
    {
        return Crypt::decryptString($this->attributes['ssh_private_key_encrypted']);
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
}

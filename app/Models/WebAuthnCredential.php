<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

class WebAuthnCredential extends Model
{
    protected $table = 'webauthn_credentials';

    protected $fillable = [
        'user_id',
        'name',
        'credential_id',
        'public_key',
        'user_handle',
        'counter',
        'aaguid',
        'transports',
        'backup_eligible',
        'backup_status',
        'last_used_at',
    ];

    protected $casts = [
        'transports' => 'array',
        'backup_eligible' => 'boolean',
        'backup_status' => 'boolean',
        'last_used_at' => 'datetime',
        'counter' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toCredentialRecord(): CredentialRecord
    {
        $aaguid = $this->aaguid
            ? Uuid::fromString($this->aaguid)
            : Uuid::fromString('00000000-0000-0000-0000-000000000000');

        return CredentialRecord::create(
            self::base64UrlDecode($this->credential_id),
            'public-key',
            is_array($this->transports) ? $this->transports : [],
            'none',
            EmptyTrustPath::create(),
            $aaguid,
            self::base64UrlDecode($this->public_key),
            $this->user_handle,
            (int) $this->counter,
            null,
            $this->backup_eligible,
            $this->backup_status,
            true,
        );
    }

    public static function storeFromCredentialRecord(
        int $userId,
        CredentialRecord $record,
        ?string $name = null,
    ): self {
        return self::query()->create([
            'user_id' => $userId,
            'name' => $name ?: 'Passkey',
            'credential_id' => self::base64UrlEncode($record->publicKeyCredentialId),
            'public_key' => self::base64UrlEncode($record->credentialPublicKey),
            'user_handle' => $record->userHandle,
            'counter' => $record->counter,
            'aaguid' => $record->aaguid->toRfc4122(),
            'transports' => $record->transports,
            'backup_eligible' => $record->backupEligible,
            'backup_status' => $record->backupStatus,
        ]);
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64url data.');
        }

        return $decoded;
    }
}

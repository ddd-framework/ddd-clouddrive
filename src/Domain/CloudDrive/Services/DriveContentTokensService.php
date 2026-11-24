<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentToken;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveTransferSettings;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * Signs and reads the tokens of content URLs: `<payload>.<signature>`, both base64url, the signature an HMAC-SHA256
 * over the payload with a key derived from the server secret (`CLOUDDRIVE_CONTENT_TOKEN_SECRET`, else `APP_SECRET`).
 * A token names a node, a version, a purpose, the account and audience that asked and an expiry; it needs no
 * `Authorization` header, so it works in `<img>`, `<video>` and a download link.
 */
class DriveContentTokensService extends Service
{
    public const string SECRET_ENV_NAME = 'CLOUDDRIVE_CONTENT_TOKEN_SECRET';

    public const string FALLBACK_SECRET_ENV_NAME = 'APP_SECRET';

    /** Context of the key derivation: a token of this module is never valid for anything else signed with the secret */
    public const string KEY_CONTEXT = 'ddd-clouddrive-content-token-v1';

    public const int MAX_TOKEN_LENGTH = 1024;

    public function createToken(DriveContentToken $driveContentToken): string
    {
        $payload = static::encodeBase64Url((string)json_encode([
            'n' => $driveContentToken->driveNodeId,
            'v' => $driveContentToken->driveFileVersionId,
            'p' => $driveContentToken->purpose,
            'a' => $driveContentToken->accountId,
            'u' => $driveContentToken->audience,
            'o' => $driveContentToken->onBehalfOfAccountId,
            'e' => $driveContentToken->expiresAt,
        ], JSON_THROW_ON_ERROR));
        return $payload . '.' . static::encodeBase64Url(hash_hmac('sha256', $payload, $this->getSigningKey(), true));
    }

    /** The token of a valid, unexpired content URL; null for a forged, altered, malformed or expired one */
    public function readToken(string $token, ?int $now = null): ?DriveContentToken
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $signature] = explode('.', $token);
        $expectedSignature = static::encodeBase64Url(hash_hmac('sha256', $payload, $this->getSigningKey(), true));
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }
        $decodedPayload = json_decode((string)static::decodeBase64Url($payload), true);
        if (!is_array($decodedPayload) || !is_int($decodedPayload['n'] ?? null) || !is_int($decodedPayload['v'] ?? null) || !is_int($decodedPayload['e'] ?? null)
            || !in_array($decodedPayload['p'] ?? null, DriveContentGrant::PEOPLE_PURPOSES, true)
        ) {
            return null;
        }
        $driveContentToken = new DriveContentToken();
        $driveContentToken->driveNodeId = $decodedPayload['n'];
        $driveContentToken->driveFileVersionId = $decodedPayload['v'];
        $driveContentToken->purpose = $decodedPayload['p'];
        $driveContentToken->accountId = is_int($decodedPayload['a'] ?? null) ? $decodedPayload['a'] : null;
        $driveContentToken->audience = is_string($decodedPayload['u'] ?? null) ? $decodedPayload['u'] : '';
        $driveContentToken->onBehalfOfAccountId = is_int($decodedPayload['o'] ?? null) ? $decodedPayload['o'] : null;
        $driveContentToken->expiresAt = $decodedPayload['e'];
        return $driveContentToken->isExpired($now) ? null : $driveContentToken;
    }

    /** The URL of a token below the host's mount of the content route */
    public function buildContentUrl(string $token, ?DriveTransferSettings $driveTransferSettings = null): string
    {
        return ($driveTransferSettings ?? DriveTransferSettings::fromConfig())->contentUrlPrefix . '/' . $token;
    }

    /**
     * @throws InternalErrorException without a server secret
     */
    protected function getSigningKey(): string
    {
        $secret = (string)(Config::getEnv(self::SECRET_ENV_NAME) ?: Config::getEnv(self::FALLBACK_SECRET_ENV_NAME) ?: '');
        if (strlen($secret) < 16) {
            throw new InternalErrorException(sprintf('The cloud drive signs its content URLs with %s (or %s): set one of them (at least 16 characters).', self::SECRET_ENV_NAME, self::FALLBACK_SECRET_ENV_NAME));
        }
        return hash_hmac('sha256', self::KEY_CONTEXT, $secret, true);
    }

    protected static function encodeBase64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    protected static function decodeBase64Url(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}

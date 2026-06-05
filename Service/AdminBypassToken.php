<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

final class AdminBypassToken
{
    public const COOKIE_NAME = 'pimcore_amm_bypass';
    private const TTL = 86400; // 24 hours

    public function __construct(private readonly string $secret) {}

    public function generate(string $userIdentifier): string
    {
        $expiry = time() + self::TTL;
        // payload: base64(userIdentifier).expiry — dots are safe because base64 uses +/= not dots
        $payload = base64_encode($userIdentifier) . '.' . $expiry;
        $sig = hash_hmac('sha256', $payload, $this->secret);
        return $payload . '.' . $sig;
    }

    /** Returns the userIdentifier if the token is valid and unexpired, null otherwise. */
    public function verify(string $token): ?string
    {
        // Format: base64(userIdentifier).expiry.hmac
        $parts = explode('.', $token, 3);
        if (\count($parts) !== 3) {
            return null;
        }

        [$encodedUser, $expiry, $sig] = $parts;

        $payload = $encodedUser . '.' . $expiry;
        if (!hash_equals(hash_hmac('sha256', $payload, $this->secret), $sig)) {
            return null;
        }

        if ((int) $expiry < time()) {
            return null;
        }

        $userIdentifier = base64_decode($encodedUser, true);
        return ($userIdentifier !== false && $userIdentifier !== '') ? $userIdentifier : null;
    }

    public function ttl(): int
    {
        return self::TTL;
    }
}

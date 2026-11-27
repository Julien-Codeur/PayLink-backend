<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class ProviderCallbackSecretChecker
{
    public const PLACEHOLDER = 'dev_only_change_me';
    public const MIN_LENGTH = 32;

    public function assertUsable(string $secret, string $environment): void
    {
        if (\in_array($environment, ['dev', 'test'], true)) {
            return;
        }

        if ('' === $secret || self::PLACEHOLDER === $secret || \strlen($secret) < self::MIN_LENGTH) {
            throw new \LogicException('PAYLINK_PROVIDER_CALLBACK_SECRET est absent, trop court ou encore le placeholder.');
        }
    }
}

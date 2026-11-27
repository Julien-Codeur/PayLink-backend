<?php

declare(strict_types=1);

namespace App;

use App\Shared\Security\ProviderCallbackSecretChecker;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();
        $secret = $this->getContainer()->getParameter('paylink.provider_callback_secret');
        if (!\is_string($secret)) {
            $secret = '';
        }
        (new ProviderCallbackSecretChecker())->assertUsable($secret, $this->environment);
    }
}

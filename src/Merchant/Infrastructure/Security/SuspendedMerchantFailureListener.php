<?php

declare(strict_types=1);

namespace App\Merchant\Infrastructure\Security;

use App\Shared\Http\ApiProblemException;
use App\Shared\Http\ApiProblemResponse;
use App\Shared\Http\CorrelationId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

#[AsEventListener(event: LoginFailureEvent::class, priority: -32)]
final class SuspendedMerchantFailureListener
{
    public function __invoke(LoginFailureEvent $event): void
    {
        if (!$event->getException() instanceof AccountStatusException) {
            return;
        }

        $event->setResponse(new ApiProblemResponse(
            ApiProblemException::merchantSuspended(),
            CorrelationId::from($event->getRequest()),
        ));
    }
}

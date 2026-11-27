<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class JwtProblemSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_INVALID => 'onFailure',
            Events::JWT_EXPIRED => 'onFailure',
            Events::JWT_NOT_FOUND => 'onFailure',
        ];
    }

    public function onFailure(AuthenticationFailureEvent $event): void
    {
        $request = $event->getRequest();
        $correlationId = null === $request ? null : CorrelationId::from($request);
        $event->setResponse(new ApiProblemResponse(
            ApiProblemException::authentication('Jeton invalide ou expiré.'),
            $correlationId,
        ));
    }
}

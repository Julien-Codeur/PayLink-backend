<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Security;

use App\Merchant\Domain\Entity\Merchant;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class MerchantJwtSubscriber
{
    #[AsEventListener(event: Events::JWT_CREATED)]
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof Merchant) {
            return;
        }

        $payload = $event->getData();
        $payload['merchant_id'] = (string) $user->getId();
        $event->setData($payload);
    }
}

<?php

declare(strict_types=1);

namespace App\Merchant\Infrastructure\Security;

use App\Merchant\Domain\Entity\ApiKey;
use App\Merchant\Infrastructure\Repository\ApiKeyRepository;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\ApiProblemResponse;
use App\Shared\Http\CorrelationId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(Request $request): bool
    {
        if ($request->headers->has('Authorization')) {
            return false;
        }

        $presented = $request->headers->get('X-API-Key');

        return \is_string($presented) && '' !== trim($presented);
    }

    public function authenticate(Request $request): Passport
    {
        $presented = trim((string) $request->headers->get('X-API-Key'));
        $hash = hash('sha256', $presented);

        return new SelfValidatingPassport(new UserBadge($hash, function (string $keyHash): UserInterface {
            $apiKey = $this->apiKeys->findOneActiveByHash($keyHash);
            if (!$apiKey instanceof ApiKey) {
                throw new CustomUserMessageAuthenticationException('Clé API invalide ou révoquée.');
            }

            $apiKey->markUsed($this->clock->now());
            $this->entityManager->flush();

            return $apiKey->getMerchant();
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new ApiProblemResponse(
            ApiProblemException::authentication('Clé API invalide ou révoquée.'),
            CorrelationId::from($request),
        );
    }
}

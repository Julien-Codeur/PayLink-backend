<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 20)]
final class ApiProblemListener
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $problem = $this->map($event->getThrowable(), $event->getRequest()->getPathInfo());
        if (!$problem instanceof ApiProblemException) {
            return;
        }

        $event->setResponse(new ApiProblemResponse($problem, CorrelationId::from($event->getRequest())));
    }

    private function map(\Throwable $throwable, string $path): ?ApiProblemException
    {
        if ($throwable instanceof ApiProblemException) {
            return $throwable;
        }

        if (!str_starts_with($path, '/api')) {
            return null;
        }

        if ($throwable instanceof AccountStatusException) {
            return ApiProblemException::merchantSuspended();
        }

        if ($throwable instanceof AuthenticationException) {
            return ApiProblemException::authentication();
        }

        if ($throwable instanceof NotFoundHttpException) {
            return ApiProblemException::notFound();
        }

        if ($this->debug) {
            return null;
        }

        return new ApiProblemException('/errors/internal', 'Erreur interne', 500, 'Une erreur interne s\'est produite.');
    }
}

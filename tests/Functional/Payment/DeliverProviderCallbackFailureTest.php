<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Message\DeliverProviderCallback;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class DeliverProviderCallbackFailureTest extends PaymentTestCase
{
    public function testUnCallback401PartDansLeTransportFailed(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction(
            $client,
            $merchant['api_key'],
            $this->paymentBody(reference: 'FAIL-401', phone: '+22890000002'),
            $this->idempotencyKey(),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $bus = static::getContainer()->get('messenger.bus.default');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new DeliverProviderCallback($id));

        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            if ('/internal/provider/callback' !== $event->getRequest()->getPathInfo()) {
                return;
            }
            $event->setResponse(new Response('', Response::HTTP_UNAUTHORIZED));
            $event->stopPropagation();
        }, 512);

        $worker = new Worker(['async' => $transport], $bus, $dispatcher);
        $dispatcher->addListener(WorkerRunningEvent::class, static function () use ($worker): void {
            $worker->stop();
        });
        $worker->run(['sleep' => 0]);

        $failed = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $failed);
        $rejected = [];
        foreach ($failed->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof DeliverProviderCallback) {
                $rejected[] = $message;
            }
        }
        self::assertCount(1, $rejected);
        self::assertSame($id, $rejected[0]->transactionId);

        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneForMerchant($this->merchant($merchant['email']), $id);
        self::assertNotNull($transaction);
        self::assertSame(TransactionStatus::Initiated, $transaction->getStatus());
    }
}

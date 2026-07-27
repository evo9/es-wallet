<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Http;

use App\Wallet\Application\Command\CaptureHold;
use App\Wallet\Application\Command\CloseWallet;
use App\Wallet\Application\Command\DepositMoney;
use App\Wallet\Application\Command\HoldFunds;
use App\Wallet\Application\Command\OpenWallet;
use App\Wallet\Application\Command\ReleaseHold;
use App\Wallet\Application\Command\WithdrawMoney;
use App\Wallet\Application\Query\GetBalance;
use App\Wallet\Application\Query\GetWalletHistory;
use App\Wallet\Application\Query\WalletBalance;
use App\Wallet\Application\Query\WalletHistoryEntry;
use App\Wallet\Domain\ValueObject\WalletId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Only parses input -> dispatches a command/query on the (sync, in test/dev) bus ->
 * shapes the response. No business logic here — that lives in the aggregate and its
 * command handlers. Domain exceptions raised by handlers surface via
 * HandlerFailedException; ExceptionListener unwraps and maps them to HTTP status codes.
 */
final readonly class WalletController
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    #[Route('/wallets', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $walletId = WalletId::generate();

        $this->bus->dispatch(new OpenWallet($walletId, $payload['currency']));

        return new JsonResponse(['walletId' => $walletId->toString()], Response::HTTP_CREATED);
    }

    #[Route('/wallets/{id}/deposit', methods: ['POST'])]
    public function deposit(string $id, Request $request): Response
    {
        $payload = $request->toArray();

        $this->bus->dispatch(new DepositMoney(
            WalletId::fromString($id),
            $payload['amount'],
            $payload['currency'],
            $payload['source'],
        ));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    #[Route('/wallets/{id}/withdraw', methods: ['POST'])]
    public function withdraw(string $id, Request $request): Response
    {
        $payload = $request->toArray();

        $this->bus->dispatch(new WithdrawMoney(
            WalletId::fromString($id),
            $payload['amount'],
            $payload['currency'],
            $payload['destination'],
        ));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    #[Route('/wallets/{id}/holds', methods: ['POST'])]
    public function hold(string $id, Request $request): Response
    {
        $payload = $request->toArray();

        $this->bus->dispatch(new HoldFunds(
            WalletId::fromString($id),
            $payload['holdId'],
            $payload['amount'],
            $payload['currency'],
        ));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    #[Route('/wallets/{id}/holds/{holdId}/release', methods: ['POST'])]
    public function releaseHold(string $id, string $holdId): Response
    {
        $this->bus->dispatch(new ReleaseHold(WalletId::fromString($id), $holdId));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    #[Route('/wallets/{id}/holds/{holdId}/capture', methods: ['POST'])]
    public function captureHold(string $id, string $holdId): Response
    {
        $this->bus->dispatch(new CaptureHold(WalletId::fromString($id), $holdId));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    #[Route('/wallets/{id}', methods: ['DELETE'])]
    public function close(string $id): Response
    {
        $this->bus->dispatch(new CloseWallet(WalletId::fromString($id)));

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    #[Route('/wallets/{id}/balance', methods: ['GET'])]
    public function balance(string $id): JsonResponse
    {
        $envelope = $this->bus->dispatch(new GetBalance(WalletId::fromString($id)));

        /** @var WalletBalance $balance */
        $balance = $envelope->last(HandledStamp::class)->getResult();

        return new JsonResponse([
            'walletId' => $balance->walletId,
            'currency' => $balance->currency,
            'balance' => $balance->balance,
            'held' => $balance->held,
            'available' => $balance->available,
            'closed' => $balance->closed,
            'lastVersion' => $balance->lastVersion,
        ]);
    }

    #[Route('/wallets/{id}/history', methods: ['GET'])]
    public function history(string $id): JsonResponse
    {
        $envelope = $this->bus->dispatch(new GetWalletHistory(WalletId::fromString($id)));

        /** @var WalletHistoryEntry[] $entries */
        $entries = $envelope->last(HandledStamp::class)->getResult();

        return new JsonResponse(array_map(
            static fn (WalletHistoryEntry $entry): array => [
                'eventType' => $entry->eventType,
                'occurredAt' => $entry->occurredAt->format(\DATE_ATOM),
                'payload' => $entry->payload,
            ],
            $entries,
        ));
    }
}

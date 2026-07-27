<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wallet\Infrastructure\Http;

use App\Wallet\Domain\ValueObject\WalletId;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class WalletControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE wallet_events');
        $connection->executeStatement('TRUNCATE TABLE wallet_balances');
    }

    public function test_full_wallet_lifecycle_happy_path(): void
    {
        $client = $this->client;

        $client->jsonRequest('POST', '/wallets', ['currency' => 'EUR']);
        self::assertResponseStatusCodeSame(201);
        $walletId = json_decode($client->getResponse()->getContent(), true)['walletId'];
        self::assertIsString($walletId);

        $client->jsonRequest('POST', "/wallets/{$walletId}/deposit", ['amount' => 100, 'currency' => 'EUR', 'source' => 'topup']);
        self::assertResponseStatusCodeSame(202);

        $client->jsonRequest('GET', "/wallets/{$walletId}/balance");
        self::assertResponseStatusCodeSame(200);
        $balance = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(100, $balance['balance']);
        self::assertSame(2, $balance['lastVersion']);

        $client->jsonRequest('POST', "/wallets/{$walletId}/holds", ['holdId' => 'hold-1', 'amount' => 40, 'currency' => 'EUR']);
        self::assertResponseStatusCodeSame(202);

        $client->jsonRequest('POST', "/wallets/{$walletId}/holds/hold-1/release");
        self::assertResponseStatusCodeSame(202);

        $client->jsonRequest('POST', "/wallets/{$walletId}/holds", ['holdId' => 'hold-2', 'amount' => 40, 'currency' => 'EUR']);
        self::assertResponseStatusCodeSame(202);

        $client->jsonRequest('POST', "/wallets/{$walletId}/holds/hold-2/capture");
        self::assertResponseStatusCodeSame(202);

        $client->jsonRequest('POST', "/wallets/{$walletId}/withdraw", ['amount' => 30, 'currency' => 'EUR', 'destination' => 'payout']);
        self::assertResponseStatusCodeSame(202);

        $client->jsonRequest('DELETE', "/wallets/{$walletId}");
        self::assertResponseStatusCodeSame(202);

        $client->jsonRequest('GET', "/wallets/{$walletId}/balance");
        $balance = json_decode($client->getResponse()->getContent(), true);
        // 100 deposit - 40 (hold-2 captured) - 30 withdraw = 30; hold-1 was released, not captured.
        self::assertSame(30, $balance['balance']);
        self::assertTrue($balance['closed']);

        $client->jsonRequest('GET', "/wallets/{$walletId}/history");
        self::assertResponseStatusCodeSame(200);
        $history = json_decode($client->getResponse()->getContent(), true);
        // opened, deposit, hold-1, release-1, hold-2, capture-2, withdraw, close
        self::assertCount(8, $history);
        self::assertSame('wallet_opened', $history[0]['eventType']);
    }

    public function test_withdrawing_more_than_available_returns_409(): void
    {
        $client = $this->client;

        $client->jsonRequest('POST', '/wallets', ['currency' => 'EUR']);
        $walletId = json_decode($client->getResponse()->getContent(), true)['walletId'];

        $client->jsonRequest('POST', "/wallets/{$walletId}/deposit", ['amount' => 100, 'currency' => 'EUR', 'source' => 'topup']);

        $client->jsonRequest('POST', "/wallets/{$walletId}/withdraw", ['amount' => 500, 'currency' => 'EUR', 'destination' => 'payout']);
        self::assertResponseStatusCodeSame(409);
    }

    public function test_getting_the_balance_of_a_nonexistent_wallet_returns_404(): void
    {
        $this->client->jsonRequest('GET', '/wallets/'.WalletId::generate()->toString().'/balance');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_depositing_an_invalid_amount_returns_422(): void
    {
        $client = $this->client;

        $client->jsonRequest('POST', '/wallets', ['currency' => 'EUR']);
        $walletId = json_decode($client->getResponse()->getContent(), true)['walletId'];

        $client->jsonRequest('POST', "/wallets/{$walletId}/deposit", ['amount' => 0, 'currency' => 'EUR', 'source' => 'topup']);
        self::assertResponseStatusCodeSame(422);
    }
}

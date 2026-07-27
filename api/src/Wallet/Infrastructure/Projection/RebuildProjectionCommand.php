<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Projection;

use App\Wallet\Infrastructure\EventStore\DbalEventStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TRUNCATE wallet_balances, then replay wallet_events (batched, in append order) through
 * the same BalanceProjector::__invoke() the live Messenger handler uses — the projector
 * doesn't know or care whether an event arrived via the bus or a rebuild.
 */
#[AsCommand(name: 'wallet:projection:rebuild', description: 'Rebuild the wallet_balances read model from the event store')]
final class RebuildProjectionCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DbalEventStore $eventStore,
        private readonly BalanceProjector $projector,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->connection->executeStatement('TRUNCATE TABLE wallet_balances');

        $count = 0;
        foreach ($this->eventStore->loadAll() as $row) {
            ($this->projector)(new ProjectableEvent($row['event'], $row['version']));
            ++$count;
        }

        $io->success(sprintf('Rebuilt projection from %d event(s).', $count));

        return Command::SUCCESS;
    }
}

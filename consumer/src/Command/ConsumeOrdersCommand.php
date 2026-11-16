<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

#[AsCommand(name: 'app:orders:consume', description: 'Consume order.created messages, one every N seconds')]
final class ConsumeOrdersCommand extends Command
{
    private bool $stop = false;

    public function __construct(
        private ReceiverInterface $receiver,
        private MessageBusInterface $bus,
        private int $batchSize,
        private int $interval,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ([SIGINT, SIGTERM] as $sig) {
            pcntl_signal($sig, fn () => $this->stop = true);
        }

        $output->writeln("Consuming orders.created.queue, {$this->batchSize} message(s) every {$this->interval}s");

        while (!$this->stop) {
            pcntl_signal_dispatch();

            $envelopes = [];
            for ($n = 0; $n < $this->batchSize; $n++) {
                $got = iterator_to_array($this->receiver->get(), false);
                if (!$got) {
                    break; // queue empty
                }
                array_push($envelopes, ...$got);
            }
            foreach ($envelopes as $envelope) {
                /** @var Envelope $envelope */
                try {
                    $this->bus->dispatch($envelope->with(new ReceivedStamp('orders')));
                    $this->receiver->ack($envelope);
                    $output->writeln('Consumed '.$envelope->getMessage()->id);
                } catch (\Throwable $e) {
                    $this->receiver->reject($envelope);
                    $output->writeln('<error>Rejected: '.$e->getMessage().'</error>');
                }
            }

            // Wait before the next message (sleep in 1s steps so signals are honoured)
            for ($i = 0; $i < $this->interval && !$this->stop; $i++) {
                sleep(1);
                pcntl_signal_dispatch();
            }
        }

        return Command::SUCCESS;
    }
}

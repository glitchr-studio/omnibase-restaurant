<?php

namespace Base\Restaurant\Omnifood\Command;

use Base\Restaurant\Omnifood\Platforms;
use Base\Restaurant\Omnifood\Receiver;
use Omnifood\ReservationsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The booking platforms' reservations read and put in the book - what
 * their webhooks missed. Run by the cron container every few minutes.
 */
#[AsCommand(name: 'restaurant:reservations:sync', description: 'Read the booking platforms\' reservations into the book (glitchr/omnifood).')]
final class ReservationsSyncCommand extends Command
{
    public function __construct(private readonly Platforms $platforms, private readonly Receiver $receiver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days ahead.', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = new \DateTimeImmutable('today');
        $to = $from->modify(sprintf('+%d days', max(1, (int) $input->getOption('days'))));
        $failed = 0;
        foreach ($this->platforms->having(ReservationsInterface::class) as $name => $platform) {
            try {
                $count = 0;
                foreach ($platform->reservations($from, $to) as $booking) {
                    $this->receiver->booking($name, $booking);
                    ++$count;
                }
                $io->success(sprintf('%s: %d reservations read.', $name, $count));
            } catch (\Throwable $e) {
                ++$failed;
                $io->error(sprintf('%s: %s', $name, $e->getMessage()));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Command;

use Supertext\ContaoTranslation\Translation\ReviewCorrections;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Writes reviewers' corrections into the translations now (the cron job does it hourly). */
#[AsCommand(name: 'supertext:review:apply', description: 'Write corrections from Supertext Localization Review into the translations')]
class ApplyReviewCorrectionsCommand extends Command
{
    public function __construct(private readonly ReviewCorrections $corrections)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->corrections->run();

        if ([] === $result['roots'] && null === $result['error']) {
            $io->note('No website root has Translation review on.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($result['roots'] as $r) {
            $counts = [];

            foreach ($r['counts'] as $outcome => $n) {
                $counts[] = "$outcome: $n";
            }

            $rows[] = [$r['title'], $r['language'], [] === $counts ? 'nothing pending' : implode(', ', $counts)];
        }

        $io->table(['Website root', 'Supertext language', 'Corrections'], $rows);
        $io->writeln(\sprintf('Acknowledged: %d', $result['acknowledged']));

        if (null !== $result['error']) {
            $io->error('Supertext review service: '.$result['error']);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

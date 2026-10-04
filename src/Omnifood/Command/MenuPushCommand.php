<?php

namespace Base\Restaurant\Omnifood\Command;

use Base\Restaurant\Omnifood\MenuExport;
use Base\Restaurant\Omnifood\Platforms;
use Omnifood\Exception\InvalidMenuException;
use Omnifood\MenuInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The menu sent to the delivery platforms (each replaces its own): all of them, or the one named. */
#[AsCommand(name: 'restaurant:menu:push', description: 'Send the menu to the delivery platforms (glitchr/omnifood).')]
final class MenuPushCommand extends Command
{
    public function __construct(private readonly Platforms $platforms, private readonly MenuExport $export)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('platform', InputArgument::OPTIONAL, 'One platform\'s name; every one taking a menu when left out.')
            ->addOption('photos', null, InputOption::VALUE_REQUIRED, 'The public address the dishes\' drawings are under (https://example.org/assets).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the menu, send nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $menu = $this->export->menu('Menu', 'EUR', $input->getOption('photos'));
        $io->text(sprintf('%d sections, %d dishes.', \count($menu->categories), \count($menu->items())));
        if ($input->getOption('dry-run')) {
            foreach ($menu->categories as $category) {
                $io->section($category->name);
                $io->listing(array_map(fn ($i) => sprintf('%s - %s (%s)%s', $i->ref, $i->name, $i->price, $i->available ? '' : ' [rupture]'), $category->items));
            }

            return Command::SUCCESS;
        }

        $failed = 0;
        $targets = $this->platforms->having(MenuInterface::class);
        if ($name = $input->getArgument('platform')) {
            $targets = array_intersect_key($targets, [$name => true]);
        }
        foreach ($targets as $name => $platform) {
            try {
                $platform->pushMenu($menu);
                $io->success(sprintf('%s: menu sent.', $name));
            } catch (InvalidMenuException $e) {
                ++$failed;
                $io->error(sprintf('%s refuses this menu: %s', $name, $e->getMessage()));
            } catch (\Throwable $e) {
                ++$failed;
                $io->error(sprintf('%s: %s', $name, $e->getMessage()));
            }
        }
        if (!$targets) {
            $io->warning('No platform taking a menu is configured (omnifood.platforms), or none has its keys.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}

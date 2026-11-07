<?php /** @noinspection PhpUnused */

namespace Drupal\omnia_dev\Command;

use Drupal\omnia_dev\Service\DdevManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UliCommand extends Command
{
    protected static string $defaultName = 'uli';

    protected function configure(): void
    {
        $this
            ->setName('uli')
            ->setDescription('Generates a one-time admin login link for the DDEV test environment.')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory name of the test environment', 'test-env');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cwd = getcwd();
        $dirName = $input->getOption('dir');
        $envPath = $cwd . '/' . $dirName;

        if (!is_dir($envPath)) {
            $io->error(sprintf('Test environment directory "%s" does not exist.', $dirName));
            return Command::FAILURE;
        }

        $ddev = new DdevManager($envPath);
        $url = $ddev->getUli();

        if ($url) {
            $io->success('Admin login link:');
            $io->writeln($url);
            return Command::SUCCESS;
        }

        $io->error('Failed to generate one-time login link. Ensure DDEV is running.');
        return Command::FAILURE;
    }
}

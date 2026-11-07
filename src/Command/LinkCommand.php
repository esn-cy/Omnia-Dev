<?php /** @noinspection PhpUnused */

namespace Drupal\omnia_dev\Command;

use Drupal\omnia_dev\Service\ModuleDetector;
use Drupal\omnia_dev\Service\SymlinkManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class LinkCommand extends Command
{
    protected static string $defaultName = 'link';

    protected function configure(): void
    {
        $this
            ->setName('link')
            ->setDescription('Re-creates or updates symlinks from the module into the test environment.')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory name of the test environment', 'test-env');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cwd = getcwd();
        $dirName = $input->getOption('dir');
        $envPath = $cwd . '/' . $dirName;

        if (!is_dir($envPath)) {
            $io->error(sprintf('Test environment directory "%s" does not exist. Run "omnia-dev init" first.', $dirName));
            return Command::FAILURE;
        }

        $detector = new ModuleDetector($cwd);
        $symlinkManager = new SymlinkManager($detector, $envPath);

        $links = $symlinkManager->createSymlinks();
        $io->success('Module symlinks updated successfully:');
        $io->listing($links);

        return Command::SUCCESS;
    }
}

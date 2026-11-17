<?php /** @noinspection PhpUnused */

namespace Drupal\omnia_dev\Command;

use DOMException;
use Drupal\omnia_dev\Service\DdevManager;
use Drupal\omnia_dev\Service\JetBrainsManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DbCommand extends Command
{
    protected static string $defaultName = 'db';

    protected function configure(): void
    {
        $this
            ->setName('db')
            ->setAliases(['jetbrains:db'])
            ->setDescription('Configures or updates the JetBrains IDE database connection for the DDEV test environment.')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory name of the test environment', 'test-env');
    }

    /**
     * @throws DOMException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cwd = getcwd();
        $dirName = $input->getOption('dir');
        $envPath = $cwd . '/' . $dirName;

        if (!is_dir($envPath . '/.ddev')) {
            $io->error(sprintf('DDEV configuration not found in "%s/.ddev". Run "omnia-dev init" first.', $dirName));
            return Command::FAILURE;
        }

        $ddev = new DdevManager($envPath);
        $ddev->configureHooks();

        $jetbrains = new JetBrainsManager($cwd, $ddev);
        $info = $jetbrains->configureDatabase();

        if (!$info) {
            $io->error('Failed to retrieve DDEV database info. Ensure DDEV is running ("ddev start").');
            return Command::FAILURE;
        }

        $io->success('JetBrains IDE database connection configured:');
        $io->definitionList(
            ['Data Source Name' => $info['data_source_name']],
            ['Host/Port' => sprintf('%s:%d', $info['host'], $info['port'])],
            ['Database' => $info['dbname']],
            ['User/Pass' => sprintf('%s / %s', $info['username'], $info['password'])],
            ['Config Files' => '.idea/dataSources.xml, .idea/dataSources.local.xml']
        );

        return Command::SUCCESS;
    }
}

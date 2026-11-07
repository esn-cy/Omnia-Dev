<?php /** @noinspection PhpUnused */

namespace Drupal\omnia_dev\Command;

use Drupal\omnia_dev\Service\DdevManager;
use Drupal\omnia_dev\Service\GitManager;
use Drupal\omnia_dev\Service\ModuleDetector;
use Drupal\omnia_dev\Service\SatelliteInstaller;
use Drupal\omnia_dev\Service\SymlinkManager;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class InitCommand extends Command
{
    protected static string $defaultName = 'init';

    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Sets up a DDEV Satellite 5 test environment for the current Drupal module.')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory name for the test environment', 'test-env')
            ->addOption('php', null, InputOption::VALUE_OPTIONAL, 'PHP version for DDEV', '8.3')
            ->addOption('project-name', null, InputOption::VALUE_OPTIONAL, 'DDEV project name (defaults to [composer-name]-env)')
            ->addOption('account-name', null, InputOption::VALUE_OPTIONAL, 'Admin user account name', 'admin')
            ->addOption('account-pass', null, InputOption::VALUE_OPTIONAL, 'Admin user password', 'admin')
            ->addOption('skip-install', null, InputOption::VALUE_NONE, 'Skip running drush site:install')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force recreate environment if directory exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Omnia Dev: Satellite 5 & DDEV Test Environment Setup');

        $cwd = getcwd();
        $detector = new ModuleDetector($cwd);

        $machineName = $detector->getMachineName();
        if (!$machineName) {
            $io->error('No Drupal module (*.info.yml) detected in current directory: ' . $cwd);
            return Command::FAILURE;
        }

        $io->section(sprintf('Detected Module: %s (%s)', $detector->getModuleName(), $machineName));

        $dirName = $input->getOption('dir');
        $envPath = $cwd . '/' . $dirName;
        $phpVersion = $input->getOption('php');

        $projectName = $input->getOption('project-name');
        if (!$projectName) {
            $baseName = $detector->getComposerName() ?? $machineName;
            $sanitized = preg_replace('/[^a-z0-9-]/', '-', strtolower($baseName));
            $sanitized = trim(preg_replace('/-+/', '-', $sanitized), '-');
            $projectName = $sanitized ? ($sanitized . '-env') : ($dirName . '-env');
        }

        // Ensure test environment directory is in .gitignore
        $gitignore = new GitManager($cwd);
        if ($gitignore->ensureIgnored($dirName)) {
            $io->comment(sprintf('Added "/%s/" to .gitignore', $dirName));
        }

        // Check if environment exists
        $recreate = false;
        if (is_dir($envPath)) {
            if ($input->getOption('force')) {
                $recreate = true;
            } else {
                $choice = $io->choice(
                    sprintf('Directory "%s" already exists. How would you like to proceed?', $dirName),
                    [
                        'reuse' => 'Reuse existing files and continue setup',
                        'recreate' => 'Delete and recreate from scratch',
                        'abort' => 'Abort setup',
                    ],
                    'reuse'
                );

                if ($choice === 'abort') {
                    $io->warning('Setup aborted by user.');
                    return Command::SUCCESS;
                }
                $recreate = ($choice === 'recreate');
            }

            if ($recreate) {
                $io->comment(sprintf('Removing existing directory: %s', $envPath));

                // 1. Delete DDEV containers and release Docker/Mutagen volume file locks
                if (is_dir($envPath . '/.ddev')) {
                    $ddevDelete = new Process(['ddev', 'delete', '--omit-snapshot', '--yes'], $envPath);
                    $ddevDelete->setTimeout(60);
                    $ddevDelete->run();
                }

                // 2. Unlock read-only files (e.g. Drupal settings, composer/git fixtures)
                exec(sprintf('chmod -R u+w %s 2>/dev/null || true', escapeshellarg($envPath)));

                // 3. Remove using Symfony Filesystem
                $fs = new Filesystem();
                try {
                    $fs->remove($envPath);
                } catch (Exception) {
                }

                if (is_dir($envPath)) {
                    exec(sprintf('rm -rf %s', escapeshellarg($envPath)));
                }

                if (is_dir($envPath)) {
                    usleep(500000);
                    exec(sprintf('chmod -R u+w %s 2>/dev/null || true', escapeshellarg($envPath)));
                    exec(sprintf('rm -rf %s', escapeshellarg($envPath)));
                }

                if (is_dir($envPath)) {
                    $io->warning(sprintf('Directory "%s" could not be completely removed. Some files may be locked by running processes.', $envPath));
                }
            }
        }

        // 1. Create satellite project
        $io->section('1/5 Creating ESN Satellite Project (esn/satellite-project:^5.0)');
        $installer = new SatelliteInstaller($envPath);

        if (!$recreate && file_exists($envPath . '/composer.json')) {
            $io->text('Existing Satellite project detected; skipping creation.');
        } else {
            $moduleAllowPlugins = $detector->getAllowPlugins();
            $satelliteSuccess = $installer->createProject(function ($type, $buffer) use ($output) {
                $output->write($buffer);
            }, $moduleAllowPlugins);

            if (!$satelliteSuccess) {
                $io->error('Failed to create Satellite project via Composer.');
                return Command::FAILURE;
            }
            $io->success('Satellite project created successfully.');
        }

        // 2. Install dependencies
        $io->section('2/5 Installing Environment & Module Dependencies');
        $extraPackages = [];
        $composerDeps = $detector->getComposerDependencies();
        foreach ($composerDeps as $pkg => $ver) {
            $extraPackages[] = $pkg . ':' . $ver;
        }

        // Ensure Drush v13 is required for the test environment
        $hasDrush = false;
        foreach (array_keys($composerDeps) as $pkg) {
            if ($pkg === 'drush/drush') {
                $hasDrush = true;
                break;
            }
        }
        if (!$hasDrush) {
            $extraPackages[] = 'drush/drush:^13';
        }

        // Add any contrib Drupal dependencies declared in *.info.yml
        $drupalDeps = $detector->getDrupalDependencies();
        foreach ($drupalDeps as $depModule) {
            $alreadyCovered = false;
            foreach (array_keys($composerDeps) as $composerPkg) {
                if (str_ends_with($composerPkg, '/' . $depModule)) {
                    $alreadyCovered = true;
                    break;
                }
            }
            if (!$alreadyCovered) {
                $extraPackages[] = 'drupal/' . $depModule;
            }
        }

        if (!empty($extraPackages)) {
            $io->text('Found dependencies to install: ' . implode(', ', $extraPackages));
            $installer->requirePackages($extraPackages, function ($type, $buffer) use ($output) {
                $output->write($buffer);
            });
        } else {
            $io->text('No additional dependencies required.');
        }

        $installer->configureScanIgnore();

        // 3. Symlink module
        $io->section('3/5 Symlinking Module into web/modules/custom/' . $machineName);
        $symlinkManager = new SymlinkManager($detector, $envPath);
        $links = $symlinkManager->createSymlinks();
        $io->listing($links);
        $io->success('Module symlinks created.');

        // 4. Configure & Start DDEV
        $io->section(sprintf('4/5 Configuring & Starting DDEV (Project: %s)', $projectName));
        $ddev = new DdevManager($envPath, $phpVersion, $projectName);
        if (!$ddev->configure(fn($t, $b) => $output->write($b))) {
            $io->error('Failed to configure DDEV.');
            return Command::FAILURE;
        }

        if (!$ddev->start(fn($t, $b) => $output->write($b))) {
            $io->error('Failed to start DDEV.');
            return Command::FAILURE;
        }
        $io->success('DDEV started successfully.');

        // 5. Site Install & Enable Module
        if (!$input->getOption('skip-install')) {
            $io->section('5/5 Installing Drupal with Satellite Profile & Enabling Module');
            $accountName = $input->getOption('account-name');
            $accountPass = $input->getOption('account-pass');

            $installProcess = $ddev->drush([
                'site:install',
                'satellite',
                '--account-name=' . $accountName,
                '--account-pass=' . $accountPass,
                '--yes',
            ], fn($t, $b) => $output->write($b));

            if (!$installProcess->isSuccessful()) {
                $io->error('Drupal site-install failed.');
                return Command::FAILURE;
            }

            // Configure Satellite Accounts API to International
            $io->text('Configuring Satellite Accounts API to International...');
            $ddev->drush([
                'php:eval',
                '$state = new \Drupal\Core\Form\FormState(); $state->setValues(["level" => "international"]); \Drupal::formBuilder()->submitForm("\Drupal\satellite_api_accounts\Form\AccountsApiIntegrationForm", $state);',
            ]);

            $allDrupalDeps = $detector->getAllDrupalDependencies();
            $modulesToEnable = array_values(array_unique(array_merge($allDrupalDeps, [$machineName])));
            $io->text('Enabling module and dependencies: ' . implode(', ', $modulesToEnable));
            $enableProcess = $ddev->drush(array_merge(['pm:enable'], $modulesToEnable, ['--yes']), fn($t, $b) => $output->write($b));
            if (!$enableProcess->isSuccessful()) {
                $io->warning('Could not enable module automatically. Try running "ddev drush pm:enable ' . $machineName . '" manually.');
            } else {
                $io->success(sprintf('Module "%s" and dependencies enabled!', $machineName));
            }

            // Drush cache rebuild
            $ddev->drush(['cr'], fn($t, $b) => $output->write($b));
        }

        // Summary
        $io->success('Omnia Dev environment setup is complete!');
        $io->definitionList(
            ['Project URL' => $ddev->getPrimaryUrl()],
            ['Admin Login' => $ddev->getUli() ?? ($ddev->getPrimaryUrl() . '/user/login')],
            ['Credentials' => sprintf('%s / %s', $input->getOption('account-name'), $input->getOption('account-pass'))],
            ['Test Environment' => $envPath],
            ['Drush Command' => sprintf('cd %s && ddev drush [cmd]', $dirName)]
        );

        return Command::SUCCESS;
    }
}

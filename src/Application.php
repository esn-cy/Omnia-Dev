<?php

namespace Drupal\omnia_dev;

use Drupal\omnia_dev\Command\InitCommand;
use Drupal\omnia_dev\Command\LinkCommand;
use Drupal\omnia_dev\Command\UliCommand;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    public const string VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct('Omnia Developer Utilities', self::VERSION);

        $this->addCommands([
            new InitCommand(),
            new LinkCommand(),
            new UliCommand(),
        ]);
    }
}

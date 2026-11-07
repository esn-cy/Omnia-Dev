<?php /** @noinspection PhpUnused */

namespace Drupal\omnia_dev\Composer;

use Composer\Script\Event;
use Drupal\omnia_dev\Application;
use Exception;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class ScriptHandler
{
    /**
     * Composer script hook for 'dev:setup' / initial setup.
     * @noinspection PhpUnusedParameterInspection
     * @throws Exception
     */
    public static function setup(Event $event): int
    {
        $app = new Application();
        $input = new ArrayInput(['command' => 'init']);
        $output = new ConsoleOutput();
        return $app->run($input, $output);
    }

    /**
     * Composer script hook for 'dev:link'.
     * @noinspection PhpUnusedParameterInspection
     * @throws Exception
     */
    public static function link(Event $event): int
    {
        $app = new Application();
        $input = new ArrayInput(['command' => 'link']);
        $output = new ConsoleOutput();
        return $app->run($input, $output);
    }

    /**
     * Composer script hook for 'dev:uli'.
     * @noinspection PhpUnusedParameterInspection
     * @throws Exception
     */
    public static function uli(Event $event): int
    {
        $app = new Application();
        $input = new ArrayInput(['command' => 'uli']);
        $output = new ConsoleOutput();
        return $app->run($input, $output);
    }
}

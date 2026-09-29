<?php

namespace ChatStats;

use ChatStats\Console\GenerateCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Сборка CLI-приложения.
 */
final class Application
{
    public function run(): int
    {
        $app = new SymfonyApplication('chatstats', '1.0.0');
        $app->add(new GenerateCommand());
        return $app->run();
    }
}
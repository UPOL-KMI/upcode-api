<?php

namespace App;

use Nette\Configurator;

class Bootstrap
{
    public static function boot(): Configurator
    {
        $configurator = new Configurator;

        //$configurator->setDebugMode('secret@23.75.345.200'); // enable for your remote IP
        // $configurator->setDebugMode(false);
        $configurator->enableTracy(__DIR__ . '/../log');

        // **After `enableTracy`, and that is the whole point.** Tracy's `Debugger::enable()` calls
        // `error_reporting(E_ALL)` itself (Debugger.php:211), so the same setting in php.ini is
        // overwritten before a single request is served -- measured on this deployment, where an
        // ini override changed nothing at all.
        //
        // Without this, every request logs the deprecation notices that Nette 3.2 raises for this
        // application's own router construction: about 87 KB per request, measured. Six weeks of
        // light use had produced a 35.7 GB `error.log` -- 120 million lines, none of them about
        // anything that went wrong. On a term's traffic that fills a disk, and it hides the
        // exceptions worth reading. The deprecations are upstream's to fix and are not lost:
        // they are what a `composer update` to a Nette that no longer raises them will resolve.
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $configurator->setTimeZone('Europe/Prague');
        $configurator->setTempDirectory(__DIR__ . '/../temp');

        $configurator->createRobotLoader()
            ->addDirectory(__DIR__)
            ->register();

        $configurator->addConfig(__DIR__ . '/config/config.neon');
        if (file_exists(__DIR__ . '/config/config.local.neon')) {
            $configurator->addConfig(__DIR__ . '/config/config.local.neon');
        }

        return $configurator;
    }
}

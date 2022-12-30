<?php

use Jackalope\RepositoryFactoryJackrabbit;
use PHPCR\SimpleCredentials;
use PHPCR\Util\Console\Helper\PhpcrConsoleDumperHelper;
use PHPCR\Util\Console\Helper\PhpcrHelper;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;

/**
 * Bootstrapping the repository implementation for the stand-alone cli application.
 *
 * Copy this file to cli-config.php and adjust the configuration parts to your need.
 */

/*
 * Configuration
 */
$workspace  = 'default'; // phpcr workspace to use
$user       = 'admin';   // jackrabbit username
$pass       = 'admin';   // jackrabbit password

function bootstrapJackrabbit()
{
    /*
     * Additional jackrabbit configuration
     */
    $jackrabbitUrl = 'http://127.0.0.1:8080/server';

    // bootstrap jackrabbit
    return (new RepositoryFactoryJackrabbit())->getRepository([
        "jackalope.jackrabbit_uri" => $jackrabbitUrl,
    ]);
}

/* Only create a session if this is not about the jackrabbit server startup command */
if (!array_key_exists(1, $argv[1])) {
    return;
}
if(!in_array($argv[1], ['jackalope:run:jackrabbit', 'list', 'help'], true)) {
    $repository = bootstrapJackrabbit();
    $credentials = new SimpleCredentials($user, $pass);
    $session = $repository->login($credentials, $workspace);

    $helperSet = new HelperSet(array(
        'phpcr' => new PhpcrHelper($session),
        'phpcr_console_dumper' => new PhpcrConsoleDumperHelper(),
    ));
    if (class_exists(QuestionHelper::class)) {
        $helperSet->set(new QuestionHelper(), 'question');
    } else {
        // legacy support for old Symfony versions
        $helperSet->set(new DialogHelper(), 'dialog');
    }
}

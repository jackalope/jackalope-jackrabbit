<?php

/* bootstrapping the repository implementation */

/*
 * configuration
 */
$workspace  = 'default'; // phpcr workspace to use
$user       = 'admin';   // jackrabbit or sql user
$pass       = 'admin';   // jackrabbit or sql password

function bootstrapJackrabbit()
{
    /* additional jackrabbit configuration */
    $jackrabbit_url = 'http://127.0.0.1:8080/server';

    // bootstrap jackrabbit
    $factory = new \Jackalope\RepositoryFactoryJackrabbit;

    return $factory->getRepository(array("jackalope.jackrabbit_uri" => $jackrabbit_url));
}

$repository = bootstrapJackrabbit();

$credentials = new \PHPCR\SimpleCredentials($user, $pass);

/* only create a session if this is not about the server control command */
if (isset($argv[1])
    && $argv[1] != 'jackalope:run:jackrabbit'
    && $argv[1] != 'list'
    && $argv[1] != 'help'
) {
    $session = $repository->login($credentials, $workspace);

    $helperSet = new \Symfony\Component\Console\Helper\HelperSet(array(
        'dialog' => new \Symfony\Component\Console\Helper\DialogHelper(),
        'phpcr' => new \PHPCR\Util\Console\Helper\PhpcrHelper($session),
        'phpcr_console_dumper' => new \PHPCR\Util\Console\Helper\PhpcrConsoleDumperHelper(),
    ));
}

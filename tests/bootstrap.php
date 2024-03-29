<?php

/** Make sure we get ALL infos from php */
error_reporting(E_ALL | E_STRICT);

if (!$loader = @include __DIR__.'/../vendor/autoload.php') {
    exit('You must set up the project dependencies, run the following commands:'.PHP_EOL.
        'curl -s http://getcomposer.org/installer | php'.PHP_EOL.
        'php composer.phar install'.PHP_EOL);
}

/* Make Jackalope base tests autoloadable */
$loader->add('Jackalope', __DIR__.'/../vendor/jackalope/jackalope/tests');

/** Load the implementation loader class */
require_once __DIR__.'/ImplementationLoader.php';
require_once __DIR__.'/JackrabbitFixtureLoader.php';

/*
 * constants for the repository descriptor test for JCR 1.0/JSR-170 and JSR-283 specs
 */

define('SPEC_VERSION_DESC', 'jcr.specification.version');
define('SPEC_NAME_DESC', 'jcr.specification.name');
define('REP_VENDOR_DESC', 'jcr.repository.vendor');
define('REP_VENDOR_URL_DESC', 'jcr.repository.vendor.url');
define('REP_NAME_DESC', 'jcr.repository.name');
define('REP_VERSION_DESC', 'jcr.repository.version');
define('OPTION_TRANSACTIONS_SUPPORTED', 'option.transactions.supported');
define('OPTION_VERSIONING_SUPPORTED', 'option.versioning.supported');
define('OPTION_OBSERVATION_SUPPORTED', 'option.observation.supported');
define('OPTION_LOCKING_SUPPORTED', 'option.locking.supported');

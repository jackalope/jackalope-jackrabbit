<?php

$vendorDir = __DIR__.'/../lib/jackalope/lib/phpcr-utils/lib/vendor';
require_once $vendorDir.'/Symfony/Component/ClassLoader/UniversalClassLoader.php';

$classLoader = new \Symfony\Component\ClassLoader\UniversalClassLoader();
$classLoader->register();

$classLoader->registerNamespaces(array(
    'Jackalope' => array(__DIR__.'/', __DIR__.'/../lib/jackalope/src'),
    'PHPCR'   => array(__DIR__.'/../lib/jackalope/lib/phpcr-utils/src', __DIR__.'/../lib/jackalope/lib/phpcr/src'),
    'Symfony\Component\Console' => $vendorDir,
    'Symfony\Component\ClassLoader' => $vendorDir,
));

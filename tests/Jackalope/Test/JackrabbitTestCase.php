<?php

namespace Jackalope\Test;

use Jackalope\TestCase;

abstract class JackrabbitTestCase extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->config['url'] = $GLOBALS['jackrabbit.uri'];
    }
}

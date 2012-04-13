<?php

namespace Jackalope\Jackrabbit;

use InvalidArgumentException;
use ReflectionClass;

/**
 * Jackalope implementation factory
 */
class Factory extends \Jackalope\Factory
{
    /**
     * {@inheritDoc}
     */
    public function get($name, array $params = array())
    {
        switch ($name) {
            case 'Query\QOM\QueryObjectModelFactory':
                $name = 'Jackrabbit\Query\QOM\QueryObjectModelFactory';
                break;
        }
        return parent::get($name,$params);
    }
}

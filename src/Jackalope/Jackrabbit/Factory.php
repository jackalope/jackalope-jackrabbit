<?php

namespace Jackalope\Jackrabbit;

/**
 * Jackalope implementation factory.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
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

<?php

namespace Jackalope\Jackrabbit\Query\QOM;

use Jackalope\ObjectManager;
use Jackalope\Query\QOM\QueryObjectModelFactory as BaseQueryObjectModelFactory;

use PHPCR\Query\QOM\JoinInterface;
use PHPCR\Query\QOM\SelectorInterface;
use PHPCR\Query\QOM\SourceInterface;
use PHPCR\Query\QOM\ConstraintInterface;
use PHPCR\Query\QOM\AndInterface;
use PHPCR\Query\QOM\OrInterface;
use PHPCR\Query\QOM\NotInterface;
use PHPCR\Query\QOM\DescendantNodeInterface;
use PHPCR\Query\QOM\ChildNodeInterface;
use PHPCR\Query\QOM\FullTextSearchInterface;
use PHPCR\Query\QOM\PropertyExistenceInterface;
use PHPCR\Query\QOM\ComparisonInterface;
use PHPCR\Query\QOM\LowerCaseInterface;
use PHPCR\Query\QOM\UpperCaseInterface;
use PHPCR\Query\QOM\PropertyValueInterface;

/**
 * {@inheritDoc}
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class QueryObjectModelFactory extends BaseQueryObjectModelFactory
{
    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function createQuery(SourceInterface $source,
                         ConstraintInterface $constraint = null,
                         array $orderings = array(),
                         array $columns = array(),
                         $simpleQuery = false
    ) {
        $className = $this->isSimple($source, $constraint)
            ? 'Query\QOM\QueryObjectModelSql1' : 'Query\QOM\QueryObjectModel';

        return $this->factory->get($className, array($this->objectManager, $source, $constraint, $orderings, $columns));
    }

    protected function isSimple($source, $constraint)
    {
        if ($source instanceof JoinInterface) {
            return false;
        }

        if ($source instanceof SelectorInterface && $source->getSelectorName() !== $source->getNodeTypeName()) {
            return false;
        }

        if (!$constraint) {
            return true;
        }

        foreach ($constraint->getConstraints() as $c) {
            if ($c instanceof AndInterface
                || $c instanceof OrInterface
                || $c instanceof NotInterface
                || $c instanceof DescendantNodeInterface
                || $c instanceof ChildNodeInterface
                || $c instanceof FullTextSearchInterface
                || $c instanceof PropertyExistenceInterface
            ) {
                continue;
            } elseif ($c instanceof ComparisonInterface) {
                $o = $c->getOperand1();
                if ($o instanceof LowerCaseInterface
                    || $o instanceof UpperCaseInterface
                    || $o instanceof PropertyValueInterface
                ) {
                    continue;
                }
            }

            return false;
        }

        return true;
    }
}

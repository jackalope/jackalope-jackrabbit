<?php

namespace Jackalope\Jackrabbit\Query\QOM;

use Jackalope\ObjectManager;
use Jackalope\FactoryInterface;
use Jackalope\Query\QOM\QueryObjectModelFactory as BaseQueryObjectModelFactory;

use PHPCR\Query\QOM\JoinInterface;
use PHPCR\Query\QOM\SelectorInterface;
use PHPCR\Query\QOM\QueryObjectModelFactoryInterface;
use PHPCR\Query\QOM\SourceInterface;
use PHPCR\Query\QOM\ConstraintInterface;

/**
 * {@inheritDoc}
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
                         array $orderings,
                         array $columns,
                         $simpleQuery = false
    ) {
        $className = $this->isSimple($source, $constraint) ? 'Query\QOM\QueryObjectModelSql1' : 'Query\QOM\QueryObjectModel';
        return $this->factory->get($className, array($this->objectManager, $source, $constraint, $orderings, $columns));
    }

    protected function isSimple($source, $constraint)
    {
        if ($source instanceof JoinInterface) {
            return false;
        }

        if ($source instanceof SelectorInterface) {
            //SQL1 can't handle selector names
            if ($source->getSelectorName()) {
                return false;
            }
        }

        if (!$constraint) {
            return true;
        }
        foreach($constraint->getConstraints() as $c) {
            //FIXME: we should check for interfaces..
            switch (get_class($c)) {
                case 'Jackalope\Query\QOM\AndConstraint':
                case 'Jackalope\Query\QOM\OrConstraint':
                case 'Jackalope\Query\QOM\NotConstraint':
                case 'Jackalope\Query\QOM\DescendantNodeConstraint':
                case 'Jackalope\Query\QOM\ChildNodeConstraint':
                case 'Jackalope\Query\QOM\NotConstraint':
                case 'Jackalope\Query\QOM\FullTextSearchConstraint':
                case 'Jackalope\Query\QOM\PropertyExistence':
                    continue 2;
                case 'Jackalope\Query\QOM\ComparisonConstraint':
                    $o = $c->getOperand1();
                    switch (get_class($o)) {
                        case 'Jackalope\Query\QOM\LowerCase':
                        case 'Jackalope\Query\QOM\UpperCase':
                        case 'Jackalope\Query\QOM\PropertyValue':
                            continue 3;
                    }
                    return false;
                default:
                    return false;
            }
        }

        return true;
    }
}

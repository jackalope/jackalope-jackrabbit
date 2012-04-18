<?php

namespace Jackalope\Jackrabbit\Query\QOM;

use Jackalope\ObjectManager;
use Jackalope\FactoryInterface;
use PHPCR\Query\QOM\JoinInterface;
use PHPCR\Query\QOM\SelectorInterface;
use PHPCR\Query\QOM\QueryObjectModelFactoryInterface;

/**
 * {@inheritDoc}
 *
 * @api
 */
class QueryObjectModelFactory extends \Jackalope\Query\QOM\QueryObjectModelFactory
{
    /**
     * {@inheritDoc}
     *
     * @api
     */
    function createQuery(\PHPCR\Query\QOM\SourceInterface $source,
                         \PHPCR\Query\QOM\ConstraintInterface $constraint = null,
                         array $orderings,
                         array $columns,
                         $simpleQuery = false
    ) {

        if ($this->isSimple($source, $constraint)) {
            return $this->factory->get('Query\QOM\QueryObjectModelSql1',
                                   array($this->objectManager, $source, $constraint, $orderings, $columns));
        } else {
            return $this->factory->get('Query\QOM\QueryObjectModel',
                                   array($this->objectManager, $source, $constraint, $orderings, $columns));
        }
    }

    protected function isSimple($source, $constraint) {
        if ($source instanceof JoinInterface) {
            return false;
        } else if ($source instanceof SelectorInterface) {
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
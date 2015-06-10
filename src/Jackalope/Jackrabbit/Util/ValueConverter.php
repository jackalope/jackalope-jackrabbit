<?php
namespace Jackalope\Jackrabbit\Util;

use PHPCR\NodeInterface;
use PHPCR\PropertyType;
use PHPCR\Util\ValueConverter as BaseValueConverter;
use PHPCR\ValueFormatException;

class ValueConverter extends BaseValueConverter
{
    /**
     * {@inheritDoc}
     *
     * Overwritten to validate that unpersisted nodes are not referenced.
     *
     * This is needed because of this jackrabbit issue:
     * https://issues.apache.org/jira/browse/JCR-1614
     */
    public function convertType($value, $type, $srctype = PropertyType::UNDEFINED)
    {
        if (is_array($value)) {
            return parent::convertType($value, $type, $srctype);
        }
        if (PropertyType::UNDEFINED == $srctype) {
            $srctype = $this->determineType($value);
        }

        if ((PropertyType::REFERENCE == $srctype || PropertyType::WEAKREFERENCE == $srctype)
            && $value instanceof NodeInterface
        ) {
            if ($value->isNew()) {
                throw new ValueFormatException('Node ' . $value->getPath() . ' must be persisted before being referenceable');
            }
        }

        return parent::convertType($value, $type, $srctype);
    }
}

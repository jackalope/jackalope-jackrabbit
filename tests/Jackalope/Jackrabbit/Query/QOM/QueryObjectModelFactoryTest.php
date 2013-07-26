<?php

namespace Jackalope\Jackrabbit\Query\QOM;

use Jackalope\Jackrabbit\Query\QOM\QueryObjectModelFactory;
use Jackalope\Jackrabbit\Factory;
use Jackalope\Query\QOM\EquiJoinCondition;

use PHPCR\Util\QOM\QueryBuilder;
use PHPCR\Query\QOM\QueryObjectModelConstantsInterface as Constants;

class QueryObjectModelFactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var QueryObjectModelFactory
     */
    protected $qf;
    /**
     * @var QueryBuilder
     */
    protected $qb;

    public function setUp()
    {
        $this->qf = new QueryObjectModelFactory(new Factory());
        $this->qb = new QueryBuilder($this->qf);
    }

    public function testStatements()
    {
        //simple query, should be sql
        $this->qb->from($this->qf->selector('nt:base', "nt:base"));
        $this->assertSame('sql', $this->qb->getQuery()->getLanguage());
        $this->assertSame("SELECT s FROM nt:base", $this->qb->getQuery()->getStatement());

        //localname is not supported by sql1
        $this->qb->where($this->qf->comparison($this->qf->nodeLocalName('nt:base'), Constants::JCR_OPERATOR_EQUAL_TO, $this->qf->literal('foo')));
        $this->assertSame('JCR-SQL2', $this->qb->getQuery()->getLanguage());
        $this->assertSame("SELECT * FROM [nt:base] WHERE LOCALNAME(nt:base) = 'foo'", $this->qb->getQuery()->getStatement());

        //descendantNode is supported by sql1
        $this->qb->where($this->qf->descendantNode('nt:base', "/foo"));
        //$this->assertSame('sql', $this->qb->getQuery()->getLanguage());
        $this->assertSame("SELECT s FROM nt:base WHERE jcr:path LIKE '/foo[%]/%'", $this->qb->getQuery()->getStatement());

        //joins are not supported by sql1
        $this->qb->join($this->qf->selector('nt:unstructured', "nt:unstructured"), $this->qf->equiJoinCondition("nt:base", "data", "nt:unstructured", "data"));
        $this->assertSame('JCR-SQL2', $this->qb->getQuery()->getLanguage());
        $this->assertSame("SELECT * FROM [nt:base] INNER JOIN [nt:unstructured] ON [nt:base].data=[nt:unstructured].data WHERE ISDESCENDANTNODE([nt:base], [/foo])", $this->qb->getQuery()->getStatement());
    }

}

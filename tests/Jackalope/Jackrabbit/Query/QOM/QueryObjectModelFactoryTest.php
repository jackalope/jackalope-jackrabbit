<?php

namespace Jackalope\Jackrabbit\Query\QOM;

use Jackalope\Jackrabbit\Query\QOM\QueryObjectModelFactory;
use Jackalope\Jackrabbit\Factory;
use PHPCR\Util\QOM\QueryBuilder;
use PHPCR\Query\QOM\QueryObjectModelConstantsInterface as Constants;
use Jackalope\Query\QOM\EquiJoinCondition;

class QueryObjectModelFactoryTest extends \PHPUnit_Framework_TestCase
{
    protected $qf;
    protected $qb;

    public function setUp()
    {
        $this->qf = new QueryObjectModelFactory(new Factory());
        $this->qb = new QueryBuilder($this->qf);
    }

    public function testStatements()
    {
        //simple query, should be sql
        $this->qb->from($this->qf->selector("nt:base"));
        $this->assertSame($this->qb->getQuery()->getLanguage(),"sql");
        $this->assertSame("SELECT s FROM nt:base", $this->qb->getQuery()->getStatement());

        //localname is not supported by sql1
        $this->qb->where($this->qf->comparison($this->qf->nodeLocalName(), Constants::JCR_OPERATOR_EQUAL_TO, $this->qf->literal('foo')));
        $this->assertSame($this->qb->getQuery()->getLanguage(),"JCR-SQL2");
        $this->assertSame("SELECT * FROM [nt:base] WHERE LOCALNAME() = 'foo'",$this->qb->getQuery()->getStatement());

        //descendantNode is supported by sql1
        $this->qb->where($this->qf->descendantNode("/foo"));
        $this->assertSame($this->qb->getQuery()->getLanguage(),"sql");
        $this->assertSame("SELECT s FROM nt:base WHERE jcr:path LIKE '/foo[%]/%'", $this->qb->getQuery()->getStatement());

        //joins are not supported by sql1
        $this->qb->join($this->qf->selector("nt:unstructured"),new equiJoinCondition("foo", "data", "bar","data"));
        $this->assertSame($this->qb->getQuery()->getLanguage(),"JCR-SQL2");
        $this->assertSame("SELECT * FROM [nt:base] INNER JOIN [nt:unstructured] ON foo.data=bar.data WHERE ISDESCENDANTNODE([/foo])",$this->qb->getQuery()->getStatement());
    }

}

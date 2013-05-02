<?php

namespace Respect\Relational;

class SqlTest extends \PHPUnit_Framework_TestCase
{

    protected $object;

    protected function setUp()
    {
        $this->object = new Sql;
    }

    public function testSimpleSelect()
    {
        $sql = (string) $this->object->select('*')->from('table');
        $this->assertEquals("SELECT * FROM table", $sql);
    }

    public function testSelectMagicGetDistinctFromHell()
    {
        $sql = (string) $this->object->selectDistinct('*')->from('table');
        $this->assertEquals("SELECT DISTINCT * FROM table", $sql);
    }

    public function testSelectColumns()
    {
        $sql = (string) $this->object->select('column', 'other_column')->from('table');
        $this->assertEquals("SELECT column, other_column FROM table", $sql);
    }

    public function testSelectTables()
    {
        $sql = (string) $this->object->select('*')->from('table', 'other_table');
        $this->assertEquals("SELECT * FROM table, other_table", $sql);
    }

    public function testSelectInnerJoin()
    {
        $sql = (string) $this->object->select('*')->from('table')->innerJoin('other_table')->on('table.column = other_table.other_column');
        $this->assertEquals("SELECT * FROM table INNER JOIN other_table ON table.column = other_table.other_column", $sql);
    }

    public function testSelectInnerJoinArr()
    {
        $sql = (string) $this->object->select('*')->from('table')->innerJoin('other_table')->on(array('table.column' => 'other_table.other_column'));
        $this->assertEquals("SELECT * FROM table INNER JOIN other_table ON table.column = other_table.other_column", $sql);
    }

    public function testSelectWhere()
    {
        $sql = (string) $this->object->select('*')->from('table')->where('column=123');
        $this->assertEquals("SELECT * FROM table WHERE column=123", $sql);
    }

    public function testSelectWhereBetween()
    {
        $sql = (string) $this->object->select('*')->from('table')->where('column')->between(1, 2);
        $this->assertEquals("SELECT * FROM table WHERE column BETWEEN 1 AND 2", $sql);
    }

    public function testSelectWhereIn()
    {
        $data = array('key' => '123', 'other_key' => '456');
        $sql = (string) $this->object->select('*')->from('table')->where('column')->in($data);
        $this->assertEquals("SELECT * FROM table WHERE column IN (?, ?)", $sql);
        $this->assertEquals(array_values($data), $this->object->getParams());
    }

    public function testSelectWhereArray()
    {
        $data = array('column' => '123', 'other_column' => '456');
        $sql = (string) $this->object->select('*')->from('table')->where($data);
        $this->assertEquals("SELECT * FROM table WHERE column = ? AND other_column = ?", $sql);
        $this->assertEquals(array_values($data), $this->object->getParams());
    }

    public function testSelectWhereArrayEmptyAnd()
    {
        $data = array('column' => '123', 'other_column' => '456');
        $sql = (string) $this->object->select('*')->from('table')->where($data)->and();
        $this->assertEquals("SELECT * FROM table WHERE column = ? AND other_column = ?", $sql);
        $this->assertEquals(array_values($data), $this->object->getParams());
    }

    public function testSelectWhereOr()
    {
        $data = array('column' => '123');
        $data2 = array('other_column' => '456');
        $sql = (string) $this->object->select('*')->from('table')->where($data)->or($data2);
        $this->assertEquals("SELECT * FROM table WHERE column = ? OR other_column = ?", $sql);
        $this->assertEquals(array_values(array_merge($data, $data2)), $this->object->getParams());
    }

    public function testSelectWhereArrayQualifiedNames()
    {
        $data = array('a.column' => '123', 'b.other_column' => '456');
        $sql = (string) $this->object->select('*')->from('table a', 'other_table b')->where($data);
        $this->assertEquals("SELECT * FROM table a, other_table b WHERE a.column = ? AND b.other_column = ?", $sql);
        $this->assertEquals(array_values($data), $this->object->getParams());
    }

    public function testSelectGroupBy()
    {
        $sql = (string) $this->object->select('*')->from('table')->groupBy('column', 'other_column');
        $this->assertEquals("SELECT * FROM table GROUP BY column, other_column", $sql);
    }

    public function testSelectGroupByHaving()
    {
        $condition = array('other_column' => 456, 'yet_another_column' => 567);
        $sql = (string) $this->object->select('*')->from('table')->groupBy('column', 'other_column')->having($condition);
        $this->assertEquals("SELECT * FROM table GROUP BY column, other_column HAVING other_column = ? AND yet_another_column = ?", $sql);
        $this->assertEquals(array_values($condition), $this->object->getParams());
    }

    public function testSimpleUpdate()
    {
        $data = array('column' => 123, 'column_2' => 234);
        $condition = array('other_column' => 456, 'yet_another_column' => 567);
        $sql = (string) $this->object->update('table')->set($data)->where($condition);
        $this->assertEquals("UPDATE table SET column = ?, column_2 = ? WHERE other_column = ? AND yet_another_column = ?", $sql);
        $this->assertEquals(array_values(array_merge($data, $condition)), $this->object->getParams());
    }

    public function testSimpleInsert()
    {
        $data = array('column' => 123, 'column_2' => 234);
        $sql = (string) $this->object->insertInto('table', $data)->values($data);
        $this->assertEquals("INSERT INTO table (column, column_2) VALUES (?, ?)", $sql);
        $this->assertEquals(array_values($data), $this->object->getParams());
    }

    public function testSimpleDelete()
    {
        $condition = array('other_column' => 456, 'yet_another_column' => 567);
        $sql = (string) $this->object->deleteFrom('table')->where($condition);
        $this->assertEquals("DELETE FROM table WHERE other_column = ? AND yet_another_column = ?", $sql);
        $this->assertEquals(array_values($condition), $this->object->getParams());
    }

    public function testCreateTable()
    {
        $columns = array(
            'column INT',
            'other_column VARCHAR(255)',
            'yet_another_column TEXT'
        );
        $sql = (string) $this->object->createTable('table', $columns);
        $this->assertEquals("CREATE TABLE table (column INT, other_column VARCHAR(255), yet_another_column TEXT)", $sql);
    }

    public function testAlterTable()
    {
        $columns = array(
            'ADD column INT',
            'ADD other_column VARCHAR(255)',
            'ADD yet_another_column TEXT'
        );
        $sql = (string) $this->object->alterTable('table', $columns);
        $this->assertEquals("ALTER TABLE table ADD column INT, ADD other_column VARCHAR(255), ADD yet_another_column TEXT", $sql);
    }

    public function testGrant()
    {
        $sql = (string) $this->object->grant('SELECT', 'UPDATE')->on('table')->to('user', 'other_user');
        $this->assertEquals("GRANT SELECT, UPDATE ON table TO user, other_user", $sql);
    }

    public function testRevoke()
    {
        $sql = (string) $this->object->revoke('SELECT', 'UPDATE')->on('table')->to('user', 'other_user');
        $this->assertEquals("REVOKE SELECT, UPDATE ON table TO user, other_user", $sql);
    }

    public function testComplexFunctions()
    {
        $condition = array("AES_DECRYPT('pass', 'salt')" => 123);
        $sql = (string) $this->object->select('column', 'COUNT(column)', 'other_column')->from('table')->where($condition);
        $this->assertEquals("SELECT column, COUNT(column), other_column FROM table WHERE AES_DECRYPT('pass', 'salt') = ?", $sql);
        $this->assertEquals(array_values($condition), $this->object->getParams());
    }

    /**
     * @ticket 13
     */
    public function testAggregateFunctions()
    {
        $where = array('abc' => 10);
        $having = array('SUM(abc) >=' => '10', 'AVG(def) =' => 15);
        $sql = (string) $this->object->select('column', 'MAX(def)')->from('table')->where($where)->groupBy('abc', 'def')->having($having);
        $this->assertEquals("SELECT column, MAX(def) FROM table WHERE abc = ? GROUP BY abc, def HAVING SUM(abc) >= ? AND AVG(def) = ?", $sql);
        $this->assertEquals(array_values(array_merge($where, $having)), $this->object->getParams());
    }

    public function testStaticBuilderCall()
    {
        $this->assertEquals(
            'ORDER BY updated_at DESC',
            (string) Sql::orderBy('updated_at')->desc()
        );
    }
    public function testLastParameterWithoutParts()
    {
        $this->assertEquals(
            'ORDER BY updated_at DESC',
            $this->object->orderBy('updated_at')->desc()
        );
    }

    public static function provider_sql_operators()
    {
        // $operator, $expectedWhere
        return array(
            array('='),
            array('=='),
            array('<>'),
            array('!='),
            array('>'),
            array('>='),
            array('<'),
            array('<='),
            array('LIKE'),
        );
    }

    /**
     * @ticket 13
     * @dataProvider provider_sql_operators
     */
    public function test_sql_operators($operator, $expected=null)
    {
        $expected = $expected ?: ' ?';
        $where    = array('id '.$operator => 10);
        $sql      = (string) $this->object->select('*')->from('table')->where($where);
        $this->assertEquals('SELECT * FROM table WHERE id '.$operator.$expected, $sql);
    }

    public function testSetQueryWithParams()
    {
        $query = 'SELECT * FROM table WHERE a > ? AND b = ?';
        $params = array(1, 'foo');

        $sql = (string) $this->object->setQuery($query, $params);
        $this->assertEquals($query, $sql);
        $this->assertEquals($params, $this->object->getParams());

        $sql = (string) $this->object->setQuery('', array());
        $this->assertEmpty($sql);
        $this->assertEmpty($this->object->getParams());
    }

    public function testSelectWhereWithRepeatedReferences()
    {
        $data1 = array('a >' => 1, 'b' => 'foo');
        $data2 = array('a >' => 4);
        $data3 = array('b' => 'bar');

        $sql = (string) $this->object->select('*')->from('table')->where($data1)->or($data2)->and($data3);
        $this->assertEquals("SELECT * FROM table WHERE a > ? AND b = ? OR a > ? AND b = ?", $sql);
        $this->assertEquals(array(1, 'foo', 4, 'bar'), $this->object->getParams());
    }
}

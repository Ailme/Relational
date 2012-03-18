<?php

namespace Respect\Relational\Styles;

class CakePHPTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var Respect\Relational\Styles\CakePHP
     */
    private $style;


    public function tableEntityProvider()
    {
        return array(
            array('posts',              'Post'),
            array('comments',           'Comment'),
            array('categories',         'Category'),
            array('post_categories',    'PostCategory'),
            array('post_tags',          'PostTag'),
        );
    }

    public function manyToMantTableProvider()
    {
        return array(
            array('post',   'category', 'post_categories'),
            array('user',   'group',    'user_groups'),
            array('group',  'profile',  'group_profiles'),
        );
    }

    public function columnsPropertyProvider()
    {
        return array(
            array('id'),
            array('text'),
            array('name'),
            array('content'),
            array('created'),
        );
    }
    
    public function foreignProvider()
    {
        return array(
            array('post',       'post_id'),
            array('author',     'author_id'),
            array('tag',        'tag_id'),
            array('user',       'user_id'),
        );
    }


    public function setUp()
    {
        $this->style = new CakePHP();
    }

    public function tearDown()
    {
        $this->style = null;
    }

    /**
     * @dataProvider tableEntityProvider
     */
    public function test_table_and_entities_methods($table, $entity)
    {
        $this->assertEquals($entity, $this->style->tableToEntity($table));
        $this->assertEquals($table, $this->style->entityToTable($entity));
        $this->assertEquals('id', $this->style->primaryFromTable($table));
    }

    /**
     * @dataProvider columnsPropertyProvider
     */
    public function test_columns_and_properties_methods($column)
    {
        $this->assertEquals($column, $this->style->columnToProperty($column));
        $this->assertEquals($column, $this->style->propertyToColumn($column));
        $this->assertFalse($this->style->isForeignColumn($column));
        $this->assertNull($this->style->tableFromForeignColumn($column));
    }

    /**
     * @dataProvider manyToMantTableProvider
     */
    public function test_table_from_left_right_table($left, $right, $table)
    {
        $this->assertEquals($table, $this->style->manyFromLeftRight($left, $right));
    }
    
    /**
     * @dataProvider foreignProvider
     */
    public function test_foreign($table, $foreign)
    {
        $this->assertTrue($this->style->isForeignColumn($foreign));
        $this->assertEquals($table, $this->style->tableFromForeignColumn($foreign));
        $this->assertEquals($foreign, $this->style->foreignFromTable($table));
    }

}


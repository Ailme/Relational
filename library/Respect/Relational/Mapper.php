<?php

namespace Respect\Relational;

use Exception;
use PDO;
use SplObjectStorage;
use InvalidArgumentException;
use PDOStatement;
use PDOException;
use stdClass;
use Respect\Data\AbstractMapper;
use Respect\Data\Collections\Collection;
use Respect\Data\Collections as c;
use Respect\Data\CollectionIterator;

class Mapper extends AbstractMapper implements c\Filterable, c\Mixable, c\Typable
{
    protected $db;
    protected $style;
    public $entityNamespace = '\\';

    public function __construct($db)
    {
        parent::__construct();
        if ($db instanceof PDO)
            $this->db = new Db($db);
        elseif ($db instanceof Db)        
            $this->db = $db;
        else
            throw new InvalidArgumentException('$db must be either an instance of Respect\Relational\Db or a PDO instance.');
    }

    protected function flushSingle($entity)
    {
        $name = $this->tracked[$entity]->getName();
        $cols = $this->extractColumns($entity, $name);
        
        if ($this->removed->contains($entity)) {
            $this->rawDelete($cols, $name, $entity);
        } elseif ($this->new->contains($entity)) {
            $this->rawInsert($cols, $name, $entity);
        } else {
            if ($this->mixable($this->tracked[$entity])) {
                foreach ($this->getMixins($this->tracked[$entity]) as $mix => $spec) {
                    $mixCols = array_intersect_key($cols, array_combine($spec, array_fill(0, count($spec), '')));
                    $mixCols['id'] = $cols["{$mix}_id"];
                    $cols = array_diff($cols, $mixCols);
                    $this->rawUpdate($mixCols, $mix);
                }
            }
            $this->rawUpdate($cols, $name);
        }
    }

    protected function guessCondition(&$columns, $name)
    {
        $primaryName    = $this->getStyle()->primaryFromTable($name);
        $condition      = array($primaryName => $columns[$primaryName]);
        unset($columns[$primaryName]);
        return $condition;
    }

    protected function rawDelete(array $condition, $name, $entity)
    {
        $columns = $this->extractColumns($entity, $name);
        $condition = $this->guessCondition($columns, $name);

        return $this->db
                        ->deleteFrom($name)
                        ->where($condition)
                        ->exec();
    }

    protected function rawUpdate(array $columns, $name)
    {
        $condition = $this->guessCondition($columns, $name);

        return $this->db
                    ->update($name)
                    ->set($columns)
                    ->where($condition)
                    ->exec();
    }

    protected function rawInsert(array $columns, $name, $entity = null)
    {
        $isInserted = $this->db
                            ->insertInto($name, $columns)
                            ->values($columns)
                            ->exec();

        if (!is_null($entity))
            $this->checkNewIdentity($entity, $name);

        return $isInserted;
    }
    
    public function flush()
    {
        $conn = $this->db->getConnection();
        $conn->beginTransaction();
        try {
            foreach ($this->changed as $entity)
                $this->flushSingle($entity);
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        $this->reset();
        $conn->commit();
    }

    protected function checkNewIdentity($entity, $name)
    {
        $identity = null;
        try {
            $identity = $this->db->getConnection()->lastInsertId();
        } catch (PDOException $e) {
            //some drivers may throw an exception here, it is just irrelevant
            return false;
        }
        if (!$identity)
            return false;

        $primaryName = $this->getStyle()->primaryFromTable($name);
        $entity->$primaryName = $identity;
        return true;
    }

    protected function createStatement(Collection $collection, $withExtra = null)
    {
        $query = $this->generateQuery($collection);
        if ($withExtra instanceof Sql)
            $query->appendQuery($withExtra);
        $statement = $this->db->prepare((string) $query, PDO::FETCH_NUM);
        $statement->execute($query->getParams());
        return $statement;
    }

    protected function generateQuery(Collection $collection)
    {
        $collections = iterator_to_array(CollectionIterator::recursive($collection), true);
        $sql = new Sql;

        $this->buildSelectStatement($sql, $collections);
        $this->buildTables($sql, $collections);

        return $sql;
    }

    protected function extractColumns($entity, $name)
    {
        $primaryName = $this->getStyle()->primaryFromTable($name);
        $cols = get_object_vars($entity);

        foreach ($cols as &$c)
            if (is_object($c))
                $c = $c->{$primaryName};

        return $cols;
    }

    protected function buildSelectStatement(Sql $sql, $collections)
    {
        $selectTable = array();
        foreach ($collections as $tableSpecifier => $c) {
            if ($this->mixable($c)) {
                foreach ($this->getMixins($c) as $mixin => $columns) {
                    foreach ($columns as $col) {
                        $selectTable[] = "{$tableSpecifier}_mix{$mixin}.$col";
                    }
                        $selectTable[] = "{$tableSpecifier}_mix{$mixin}." . 
                        $this->getStyle()->primaryFromTable($mixin) . 
                        " as {$mixin}_id";
                }
            }
            if ($this->filterable($c)) {
                $filters = $this->getFilters($c);
                if ($filters) {
                    
                    $pkName = $tableSpecifier . '.' .
                        $this->getStyle()->primaryFromTable($c->getName());
                        
                    if ($filters == array('*')) {
                        $selectColumns[] = $pkName;
                    } else {
                        $selectColumns = array(
                            $tableSpecifier . '.' .
                            $this->getStyle()->primaryFromTable($c->getName())
                        );
                        foreach ($filters as $f) {
                            $selectColumns[] = "{$tableSpecifier}.{$f}";
                        }
                    }
                    
                    if ($c->getNext()) {
                        $selectColumns[] = $tableSpecifier . '.' . 
                            $this->getStyle()->foreignFromTable(
                                $c->getNext()->getName()
                            );
                    }
                    
                    $selectTable = array_merge($selectTable, $selectColumns);
                }
            } else {
                $selectTable[] = "$tableSpecifier.*";
            }
        }

        return $sql->select($selectTable);
    }

    protected function buildTables(Sql $sql, $collections)
    {
        $conditions = $aliases = array();

        foreach ($collections as $alias => $collection)
            $this->parseCollection($sql, $collection, $alias, $aliases, $conditions);

        return $sql->where($conditions);
    }

    protected function parseConditions(&$conditions, $collection, $alias)
    {
        $entity = $collection->getName();
        $originalConditions = $collection->getCondition();
        $parsedConditions = array();
        $aliasedPk = $alias . '.' . $this->getStyle()->primaryFromTable($entity);

        if (is_scalar($originalConditions))
            $parsedConditions = array($aliasedPk => $originalConditions);
        elseif (is_array($originalConditions))
            foreach ($originalConditions as $column => $value)
                if (is_numeric($column))
                    $parsedConditions[$column] = preg_replace("/{$entity}[.](\w+)/", "$alias.$1", $value);
                else
                    $parsedConditions["$alias.$column"] = $value;

        return $parsedConditions;
    }

    protected function parseCollection(Sql $sql, Collection $collection, $alias, &$aliases, &$conditions)
    {
        $entity = $collection->getName();
        $parent = $collection->getParentName();
        $next = $collection->getNextName();

        $parentAlias = $parent ? $aliases[$parent] : null;
        $aliases[$entity] = $alias;
        $parsedConditions = $this->parseConditions($conditions, $collection, $alias);

        if (!empty($parsedConditions))
            $conditions[] = $parsedConditions;


        if (is_null($parentAlias)) 
            $sql->from($entity);
        
        if ($this->mixable($collection)) {
            foreach ($this->getMixins($collection) as $mix => $spec) {
                $sql->innerJoin($mix);
                $sql->as("{$entity}_mix{$mix}");
            }
        }
        
        if (is_null($parentAlias)) 
            return $sql;
        
        if ($collection->isRequired())
            $sql->innerJoin($entity);
        else
            $sql->leftJoin($entity);

        if ($alias !== $entity)
            $sql->as($alias);
            
        
        $aliasedPk = $alias . '.' . $this->getStyle()->primaryFromTable($entity);
        $aliasedParentPk = $parentAlias . '.' . $this->getStyle()->primaryFromTable($parent);

        if ($entity === $this->getStyle()->manyFromLeftRight($parent, $next)
                || $entity === $this->getStyle()->manyFromLeftRight($next, $parent))
            return $sql->on(
                array(
                    $alias . '.' . $this->getStyle()->foreignFromTable($parent) => $aliasedParentPk
                )
            );
        else
            return $sql->on(
                array(
                    $parentAlias . '.' . $this->getStyle()->foreignFromTable($entity) => $aliasedPk
                )
            );
    }

    protected function fetchSingle(Collection $collection, PDOStatement $statement)
    {
        $name = $collection->getName();
        $entityName = $name;
        $primaryName = $this->getStyle()->primaryFromTable($name);
        if (!$this->typable($collection)) {
            $entityClass = $this->entityNamespace . $this->getStyle()->tableToEntity($entityName);
            $entityClass = class_exists($entityClass) ? $entityClass : '\stdClass';
        } else {
            $entityClass = '\stdClass';
        }
        $statement->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, $entityClass);
        $row = $statement->fetch();


        if (!$row)
            return false;
            
        if ($this->typable($collection)) {
            $entityName = $row->{$this->getType($collection)};
            $entityClass = $this->entityNamespace . $this->getStyle()->tableToEntity($entityName);
            $entityClass = class_exists($entityClass) ? $entityClass : '\stdClass';
            $newRow = new $entityClass;
            foreach ($row as $prop => $value) {
                $newRow->$prop = $value;
            }
            $row = $newRow;
        }

        $entities = new SplObjectStorage();
        $entities[$row] = $collection;

        return $entities;
    }

    protected function fetchMulti(Collection $collection, PDOStatement $statement)
    {
        $entityInstance = null;
        $entities = array();
        $row = $statement->fetch(PDO::FETCH_NUM);

        if (!$row)
            return false;

        $entities = new SplObjectStorage();
        $entitiesInstances = array();

        foreach (CollectionIterator::recursive($collection) as $c) {
            if ($this->filterable($c)) {
                $filters = $this->getFilters($c);
                if (!$filters) {
                    continue;
                }
            }
            $tableName = $c->getName();
            $entityName = $this->getStyle()->tableToEntity($tableName);
            if (!$this->typable($c)) {
                $entityClass = $this->entityNamespace . $entityName;
                $entityClass = class_exists($entityClass) ? $entityClass : 'stdClass';
            } else {
                $entityClass = 'stdClass';
            }
            $entityInstance = new $entityClass;
            $mixins = array();
            if ($this->mixable($c)) {
                $mixins = $this->getMixins($c);
                foreach ($mixins as $mix) {
                    $entitiesInstances[] = $entityInstance;
                }
            }
            $entities[$entityInstance] = $c;
            $entitiesInstances[] = $entityInstance;
        }

        $entityInstance = array_pop($entitiesInstances);
        
        //Reversely traverses the columns to avoid conflicting foreign key names
        foreach (array_reverse($row, true) as $col => $value) {
            $entityData = $entities[$entityInstance];
            $columnMeta = $statement->getColumnMeta($col);
            $columnName = $columnMeta['name'];
            $setterName = $this->getSetterStyle($columnName);
            $primaryName = $this->getStyle()->primaryFromTable($entityData->getName());
            
            if (method_exists($entityInstance, $setterName))
                $entityInstance->$setterName($value);
            else
                $entityInstance->$columnName = $value;
                
            if ($primaryName == $columnName) 
                $entityInstance = array_pop($entitiesInstances);
        }
        

        $entitiesClone = clone $entities;

        foreach ($entities as $instance) {
            foreach ($instance as $field => &$v) {
                if ($this->getStyle()->isForeignColumn($field)) {
                    foreach ($entitiesClone as $sub) {
                        $tableName = $entities[$sub]->getName();
                        $primaryName = $this->getStyle()->primaryFromTable($tableName);
                        
                        if ($tableName === $this->getStyle()->tableFromForeignColumn($field)
                                && $sub->{$primaryName} === $v) {
                            $v = $sub;
                        }
                    }
                }
            }
        }

        return $entities;
    }

    protected function getSetterStyle($name)
    {
        $name = ucfirst(str_replace('_', '', $this->getStyle()->columnToProperty($name)));
        return "set{$name}";
    }

    /**
     * @return  Respect\Relational\Styles\Stylable
     */
    public function getStyle()
    {
        if (null === $this->style) {
            $this->setStyle(new Styles\Standard());
        }
        return $this->style;
    }

    /**
     * @param   Respect\Relational\Styles$style
     * @return  Respect\Data\AbstractMapper
     */
    public function setStyle(Styles\Stylable $style)
    {
        $this->style = $style;
        return $this;
    }
    
    public function getFilters(Collection $collection)
    {
        return $collection->getExtra('filters');
    }
    public function getMixins(Collection $collection)
    {
        return $collection->getExtra('mixins');
    }
    public function getType(Collection $collection)
    {
        return $collection->getExtra('type');
    }
    public function mixable(Collection $collection)
    {
        return $collection->have('mixins');
    }
    public function typable(Collection $collection)
    {
        return $collection->have('type');
    }
    public function filterable(Collection $collection)
    {
        return $collection->have('filters');
    }
    
}


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
    /** @var Respect\Relational\Db Holds our connector**/
    protected $db;
    
    /** @var string Namespace to look for entities **/
    public $entityNamespace = '\\';

    /**
     * @param mixed $db Respect\Relational\Db or Pdo
     */
    public function __construct($db)
    {
        parent::__construct();
        
        if ($db instanceof PDO) {
            $this->db = new Db($db);
        } elseif ($db instanceof Db) {
            $this->db = $db;
        } else {
            throw new InvalidArgumentException(
                '$db must be an instance of Respect\Relational\Db or PDO.'
            );
        }
    }

    /**
     * Flushes a single instance into the database. This method supports
     * mixing, so flushing a mixed instance will flush distinct tables on the
     * database
     *
     * @param object $entity Entity instance to be flushed
     *
     * @return null
     */
    protected function flushSingle($entity)
    {
        $coll = $this->tracked[$entity];
        
        $cols = $this->extractAndUpdateMixins(
            $coll, 
            $this->extractColumns($entity, $coll)
        );
        
        if ($this->removed->contains($entity)) {
            $this->rawDelete($cols, $coll, $entity);
        } elseif ($this->new->contains($entity)) {
            $this->rawInsert($cols, $coll, $entity);
        } else {
            $this->rawUpdate($cols, $coll);
        }
    }
    
    /**
     * Receives columns from an entity and her collection. Returns the columns
     * that belong only to the main entity. This method supports mixing, so
     * extracting mixins will also persist them on their respective
     * tables
     *
     * @param Respect\Data\Collections\Collection $collection Target collection
     * @param array                               $cols       Entity columns
     *
     * @return array Columns left for the main collection
     */
    protected function extractAndUpdateMixins(Collection $collection, $cols)
    {
        if (!$this->mixable($collection)) {
            return $cols;
        }
            
        foreach ($this->getMixins($collection) as $mix => $spec) {
            //Extract from $cols only the columns from the mixin
            $mixCols = array_intersect_key(
                $cols,
                array_combine( //create array with keys only
                    $spec, 
                    array_fill(0, count($spec), '')
                )
            );
            if (isset($cols["{$mix}_id"])) {
                $mixCols['id'] = $cols["{$mix}_id"];
                $cols = array_diff($cols, $mixCols); //Remove mixin columns
                $this->rawUpdate($mixCols, $this->__get($mix));
            }
        }
        
        return $cols;
    }

    protected function guessCondition(&$columns, Collection $collection)
    {
        $primaryName    = $this->getStyle()->identifier($collection->getName());
        $condition      = array($primaryName => $columns[$primaryName]);
        unset($columns[$primaryName]);
        return $condition;
    }

    protected function rawDelete(array $condition, Collection $collection, $entity)
    {
        $name      = $collection->getName();
        $columns   = $this->extractColumns($entity, $collection);
        $condition = $this->guessCondition($columns, $collection);

        return $this->db
                    ->deleteFrom($name)
                    ->where($condition)
                    ->exec();
    }

    protected function rawUpdate(array $columns, Collection $collection)
    {
        $name      = $collection->getName();
        $condition = $this->guessCondition($columns, $collection);

        return $this->db
                    ->update($name)
                    ->set($columns)
                    ->where($condition)
                    ->exec();
    }

    protected function rawInsert(array $columns, Collection $collection, $entity = null)
    {
        $name       = $collection->getName();
        $isInserted = $this->db
                            ->insertInto($name, $columns)
                            ->values($columns)
                            ->exec();

        if (!is_null($entity))
            $this->checkNewIdentity($entity, $collection);

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

    protected function checkNewIdentity($entity, Collection $collection)
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

        $primaryName = $this->getStyle()->identifier($collection->getName());
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

    protected function extractColumns($entity, Collection $collection)
    {
        $primaryName = $this->getStyle()->identifier($collection->getName());
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
                        $this->getStyle()->identifier($mixin) . 
                        " as {$mixin}_id";
                }
            }
            if ($this->filterable($c)) {
                $filters = $this->getFilters($c);
                if ($filters) {
                    
                    $pkName = $tableSpecifier . '.' .
                        $this->getStyle()->identifier($c->getName());
                        
                    if ($filters == array('*')) {
                        $selectColumns[] = $pkName;
                    } else {
                        $selectColumns = array(
                            $tableSpecifier . '.' .
                            $this->getStyle()->identifier($c->getName())
                        );
                        foreach ($filters as $f) {
                            $selectColumns[] = "{$tableSpecifier}.{$f}";
                        }
                    }
                    
                    if ($c->getNext()) {
                        $selectColumns[] = $tableSpecifier . '.' . 
                            $this->getStyle()->remoteIdentifier(
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
        $aliasedPk = $alias . '.' . $this->getStyle()->identifier($entity);

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
            
        
        $aliasedPk = $alias . '.' . $this->getStyle()->identifier($entity);
        $aliasedParentPk = $parentAlias . '.' . $this->getStyle()->identifier($parent);

        if ($entity === $this->getStyle()->composed($parent, $next)
                || $entity === $this->getStyle()->composed($next, $parent))
            return $sql->on(
                array(
                    $alias . '.' . $this->getStyle()->remoteIdentifier($parent) => $aliasedParentPk
                )
            );
        else
            return $sql->on(
                array(
                    $parentAlias . '.' . $this->getStyle()->remoteIdentifier($entity) => $aliasedPk
                )
            );
    }

    protected function fetchSingle(Collection $collection, PDOStatement $statement)
    {
        $name = $collection->getName();
        $entityName = $name;
        $primaryName = $this->getStyle()->identifier($name);
        if (!$this->typable($collection)) {
            $entityClass = $this->entityNamespace . $this->getStyle()->styledName($entityName);
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
            $entityClass = $this->entityNamespace . $this->getStyle()->styledName($entityName);
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
            $entityName = $this->getStyle()->styledName($tableName);
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
            $primaryName = $this->getStyle()->identifier($entityData->getName());
            
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
                if ($this->getStyle()->isRemoteIdentifier($field)) {
                    foreach ($entitiesClone as $sub) {
                        $tableName = $entities[$sub]->getName();
                        $primaryName = $this->getStyle()->identifier($tableName);
                        
                        if ($tableName === $this->getStyle()->remoteFromIdentifier($field)
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
        $name = ucfirst(str_replace('_', '', $this->getStyle()->styledProperty($name)));
        return "set{$name}";
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


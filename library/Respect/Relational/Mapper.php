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
use Respect\Data\Collection;
use Respect\Data\CollectionIterator;

class Mapper extends AbstractMapper
{

    protected $db;
    protected $new;
    protected $tracked;
    protected $changed;
    protected $removed;
    
    public function __construct($db)
    {
        if ($db instanceof PDO)
            $this->db = new Db($db);
        elseif ($db instanceof Db)
            $this->db = $db;
        else
            throw new InvalidArgumentException('$db must be either an instance of Respect\Relational\Db or a PDO instance.');

        $this->tracked = new SplObjectStorage;
        $this->changed = new SplObjectStorage;
        $this->removed = new SplObjectStorage;
        $this->new = new SplObjectStorage;
    }
    
    public function fetch(Collection $fromCollection, $withExtra=null)
    {
        $statement = $this->createStatement($fromCollection, $withExtra);
        $hydrated = $this->fetchHydrated($fromCollection, $statement);
        if (!$hydrated)
            return false;

        return $this->parseHydrated($hydrated);
    }

    public function fetchAll(Collection $fromCollection, $withExtra=null)
    {
        $statement = $this->createStatement($fromCollection, $withExtra);
        $entities = array();

        while ($hydrated = $this->fetchHydrated($fromCollection, $statement))
            $entities[] = $this->parseHydrated($hydrated);

        return $entities;
    }

    public function persist($object, Collection $onCollection)
    {
        $this->changed[$object] = true;

        if ($this->isTracked($object))
            return true;

        $this->new[$object] = true;
        $this->markTracked($object, $onCollection->getName());
        return true;
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
        $this->changed = new SplObjectStorage;
        $this->removed = new SplObjectStorage;
        $this->new = new SplObjectStorage;
        $conn->commit();
    }

    protected function flushSingle($entity)
    {
        $name = $this->tracked[$entity]['table_name'];
        $cols = $this->extractColumns($entity, $name);

        if ($this->removed->contains($entity))
            $this->rawDelete($cols, $name, $entity);
        elseif ($this->new->contains($entity))
            $this->rawInsert($cols, $name, $entity);
        else
            $this->rawUpdate($cols, $name);
    }

    public function remove($object, Collection $fromCollection)
    {
        $this->changed[$object] = true;
        $this->removed[$object] = true;

        if ($this->isTracked($object))
            return true;

        $this->markTracked($object, $fromCollection->getName());
        return true;
    }

    protected function guessCondition(&$columns, $name)
    {
        $condition = array('id' => $columns['id']);
        unset($columns['id']);
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

    protected function rawInsert(array $columns, $name, $entity=null)
    {
        $isInserted = $this->db
            ->insertInto($name, $columns)
            ->values($columns)
            ->exec();

        if (!is_null($entity))
            $this->checkNewIdentity($entity, $name);

        return $isInserted;
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

        $entity->id = $identity;
        return true;
    }

    public function markTracked($entity, $name, $id=null)
    {
        $id = $entity->id;
        $this->tracked[$entity] = array(
            'name' => $name,
            'table_name' => $name,
            'id' => &$id,
            'cols' => $this->extractColumns($entity, $name)
        );
        return true;
    }

    public function isTracked($entity)
    {
        return $this->tracked->contains($entity);
    }

    public function getTracked($name, $id)
    {
        foreach ($this->tracked as $entity)
            if ($this->tracked[$entity]['id'] == $id
                && $this->tracked[$entity]['name'] === $name)
                return $entity;

        return false;
    }

    protected function createStatement(Collection $collection, Sql $sqlExtra=null)
    {
        $query = $this->generateQuery($collection);
        if ($sqlExtra)
            $query->appendQuery($sqlExtra);
        $statement = $this->db->prepare((string) $query, PDO::FETCH_NUM);
        $statement->execute($query->getParams());
        return $statement;
    }

    protected function parseHydrated(SplObjectStorage $hydrated)
    {
        $this->tracked->addAll($hydrated);
        $hydrated->rewind();
        return $hydrated->current();
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
        $cols = get_object_vars($entity);

        foreach ($cols as &$c)
            if (is_object($c))
                $c = $c->id;

        return $cols;
    }

    protected function buildSelectStatement(Sql $sql, $collections)
    {
        $selectTable = array_keys($collections);
        foreach ($selectTable as &$ts)
            $ts = "$ts.*";

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
        $aliasedPk = "$alias.id";

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
            return $sql->from($entity);
        elseif ($collection->isRequired())
            $sql->innerJoin($entity);
        else
            $sql->leftJoin($entity);

        if ($alias !== $entity)
            $sql->as($alias);

        $aliasedPk = "$alias.id";
        $aliasedParentPk = "$parentAlias.id";
		
        if ($entity === "{$parent}_{$next}" || $entity === "{$next}_{$parent}")
            return $sql->on(array("{$alias}.{$parent}_id" => $aliasedParentPk));
        else
            return $sql->on(array("{$parentAlias}.{$entity}_id" => $aliasedPk));
    }

    protected function fetchHydrated(Collection $collection, PDOStatement $statement)
    {
        if (!$collection->hasMore())
            return $this->fetchSingle($collection, $statement);
        else
            return $this->fetchMulti($collection, $statement);
    }

    protected function fetchSingle(Collection $collection, PDOStatement $statement)
    {
        $name = $collection->getName();
        $row = $statement->fetch(PDO::FETCH_OBJ);

        if (!$row)
            return false;

        $entities = new SplObjectStorage();
        $entities[$row] = array(
            'name' => $name,
            'table_name' => $name,
            'id' => $row->id,
            'cols' => $this->extractColumns($row, $name)
        );

        return $entities;
    }

    protected function fetchMulti(Collection $collection, PDOStatement $statement)
    {
        $entityInstance = null;
        $collections = CollectionIterator::recursive($collection);
        $row = $statement->fetch(PDO::FETCH_NUM);

        if (!$row)
            return false;

        $entities = new SplObjectStorage();

        foreach ($row as $n => $value) {
            $meta = $statement->getColumnMeta($n);

            if ('id' === $meta['name']) {
                if (0 !== $n)
                    $entities[$entityInstance] = array(
                        'name' => $entityName,
                        'table_name' => $entityName,
                        'id' => $entityInstance->id,
                        'cols' => $this->extractColumns(
                            $entityInstance, $entityName
                        )
                    );

                $collections->next();
                $entityName = $collections->current()->getName();
                $entityInstance = new stdClass;
            }
            $entityInstance->{$meta['name']} = $value;
        }

        if (!empty($entities))
            $entities[$entityInstance] = array(
                'name' => $entityName,
                'table_name' => $entityName,
                'id' => $entityInstance->id,
                'cols' => $this->extractColumns($entityInstance, $entityName)
            );

        $entitiesClone = clone $entities;
            
        foreach ($entities as $instance)
            foreach ($instance as $field => &$v)
                if (strlen($field) - 3 === strripos($field, '_id'))
                    foreach ($entitiesClone as $sub)
                        if ($entities[$sub]['name'] === substr($field, 0, -3)
                            && $sub->id === $v)
                            $v = $sub;

        return $entities;
    }

}

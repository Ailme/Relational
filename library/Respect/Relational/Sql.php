<?php

namespace Respect\Relational;

class Sql
{
    const SQL_OPERATORS = '/\s?(NOT)?\s?(=|==|<>|!=|>|>=|<|<=|LIKE)\s?$/';

    protected $query = '';
    protected $params = array();

    public static function __callStatic($operation, $parts)
    {
        $sql = new static;
        return call_user_func_array(array($sql, $operation), $parts);
    }

    public function __call($operation, $parts)
    {
        return $this->preBuild($operation, $parts);
    }

    protected function preBuild($operation, $parts)
    {
        $raw = ($operation === 'on');
        $new = ($operation !== 'insertInto');
        $parts = $this->normalizeParts($parts, $raw, $new);
        if (empty($parts))
            switch ($operation) {
                case 'asc':
                case 'desc':
                    break;
                default:
                    return $this;
            }
        $this->buildOperation($operation);
        return $this->build($operation, $parts);
    }

    protected function build($operation, $parts)
    {
        switch ($operation) { //just special cases
            case 'and':
            case 'having':
            case 'where':
            case 'between':
                return $this->buildKeyValues($parts, '%s ', ' AND ');
            case 'or':
                return $this->buildKeyValues($parts, '%s ', ' OR ');
            case 'set':
                return $this->buildKeyValues($parts);
            case 'on':
                return $this->buildComparators($parts, '%s ', ' AND ');
            case 'alterTable':
                $this->buildFirstPart($parts);
                return $this->buildParts($parts, '%s ');
            case 'in':
            case 'values':
                foreach ($parts as $key => $value) $parts[$key] = '?';
                return $this->buildParts($parts, '(%s) ', ', ');
            case 'values':
                foreach ($parts as $key => $value) $parts[$key] = '?';
                return $this->buildParts($parts, '(%s) ', ', ');
            case 'createTable':
            case 'insertInto':
                $this->buildFirstPart($parts);
                return $this->buildParts($parts, '(%s) ');
            default: //defaults to any other SQL instruction
                return $this->buildParts($parts);
        }
    }

    public function __construct($rawSql = '')
    {
        $this->setQuery($rawSql);
    }

    public function __toString()
    {
        $q = rtrim($this->query);
        $this->query = '';
        return $q;
    }

    public function appendQuery($rawSql)
    {
        $this->query .= " $rawSql";
        return $this;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function setQuery($rawSql, array $params = null)
    {
        $this->query = $rawSql;
        if ($params !== null) $this->params = $params;
        return $this;
    }

    protected function buildKeyValues($parts, $format = '%s ', $partSeparator = ', ')
    {
        foreach ($parts as $key => $part)
            if (is_numeric($key))
                $parts[$key] = "$part";
            else if (preg_match(static::SQL_OPERATORS, $key) > 0)
                $parts[$key] = "$key ?";
            else
                $parts[$key] = "$key = ?";

        return $this->buildParts($parts, $format, $partSeparator);
    }

    protected function buildComparators($parts, $format = '%s ', $partSeparator = ', ')
    {
        foreach ($parts as $key => $part)
            if (is_numeric($key))
                $parts[$key] = "$part";
            else
                $parts[$key] = "$key = $part";
        return $this->buildParts($parts, $format, $partSeparator);
    }

    protected function buildOperation($operation)
    {
        $command = strtoupper(preg_replace('/[A-Z0-9]+/', ' $0', $operation));
        $this->query .= trim($command) . ' ';
    }

    protected function buildParts($parts, $format = '%s ', $partSeparator = ', ')
    {
        if (!empty($parts))
            $this->query .= sprintf($format, implode($partSeparator, $parts));

        return $this;
    }

    protected function normalizeParts($parts, $raw=false, $new=true)
    {
        $params = & $this->params;
        $newParts = array();
        array_walk_recursive($parts, function ($value, $key) use (&$newParts, &$params, &$raw, &$new) {
                if ($raw) {
                    $newParts[$key] = $value;
                } elseif (is_int($key)) {
                    $newParts[] = $value;
                } else {
                    $newParts[$key] = $key;
                    if ($new) $params[] = $value;
                }
            }
        );
        return $newParts;
    }

    protected function buildFirstPart(&$parts)
    {
        $this->query .= array_shift($parts) . ' ';
    }
}

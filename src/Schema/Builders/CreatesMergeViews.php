<?php

namespace Staudenmeir\LaravelMergedRelations\Schema\Builders;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

trait CreatesMergeViews
{
    /**
     * Create a view that merges relationships.
     *
     * @param string $name
     * @param \Illuminate\Database\Eloquent\Relations\Relation[] $relations
     * @param bool $duplicates
     * @param bool $orReplace
     * @return void
     */
    public function createMergeView($name, array $relations, $duplicates = true, $orReplace = false)
    {
        $this->removeConstraints($relations);

        $union = $duplicates ? 'unionAll' : 'union';

        $query = $this->getQuery($relations, $union);

        $this->createView($name, $query, null, $orReplace);
    }

    /**
     * Create a view that merges relationships without duplicates.
     *
     * @param string $name
     * @param \Illuminate\Database\Eloquent\Relations\Relation[] $relations
     * @return void
     */
    public function createMergeViewWithoutDuplicates($name, array $relations)
    {
        $this->createMergeView($name, $relations, false);
    }

    /**
     * Create a view that merges relationships or replace an existing one.
     *
     * @param string $name
     * @param array $relations
     * @param bool $duplicates
     * @return void
     */
    public function createOrReplaceMergeView($name, array $relations, $duplicates = true)
    {
        $this->createMergeView($name, $relations, $duplicates, true);
    }

    /**
     * Create a view that merges relationships or replace an existing one without duplicates.
     *
     * @param string $name
     * @param array $relations
     * @return void
     */
    public function createOrReplaceMergeViewWithoutDuplicates($name, array $relations)
    {
        $this->createOrReplaceMergeView($name, $relations, false);
    }

    /**
     * Remove the foreign key constraints from the relationships.
     *
     * @param \Illuminate\Database\Eloquent\Relations\Relation[] $relations
     * @return void
     */
    protected function removeConstraints(array $relations)
    {
        foreach ($relations as $relation) {
            $foreignKey = $this->getForeignKey($relation);

            $relation->getQuery()->getQuery()->wheres = collect($relation->getQuery()->getQuery()->wheres)
                ->reject(function ($where) use ($foreignKey) {
                    return $where['column'] === $foreignKey;
                })->values()->all();
        }
    }

    /**
     * Get the merge query.
     *
     * @param \Illuminate\Database\Eloquent\Relations\Relation[] $relations
     * @param string $union
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function getQuery(array $relations, $union)
    {
        $grammar = $this->connection->getQueryGrammar();

        $pdo = $this->connection->getPdo();

        $columns = $this->getColumns($relations);

        $allColumns = array_unique(array_merge(...array_values($columns)));

        $query = null;

        foreach ($relations as $i => $relation) {
            $relationQuery = $relation->getQuery();

            $from = $relationQuery->getQuery()->from;

            $foreignKey = $this->getForeignKey($relation);

            $placeholders = [];

            foreach ($allColumns as $column) {
                if (in_array($column, $columns[$from])) {
                    $relationQuery->addSelect($from.'.'.$column);
                } else {
                    $relationQuery->selectRaw('null as '.$grammar->wrap($column));

                    $placeholders[] = $column;
                }
            }

            $relationQuery->selectRaw($grammar->wrap($foreignKey).' as laravel_foreign_key')
                ->selectRaw($pdo->quote(get_class($relation->getRelated())).' as laravel_model')
                ->selectRaw($pdo->quote(implode(',', $placeholders)).' as laravel_placeholders')
                ->selectRaw($pdo->quote(implode(',', array_keys($relationQuery->getEagerLoads()))).' as laravel_with');

            if (!$query) {
                $query = $relationQuery;
            } else {
                $query->$union($relationQuery);
            }
        }

        return $query;
    }

    /**
     * Get the columns of all relationship tables.
     *
     * @param \Illuminate\Database\Eloquent\Relations\Relation[] $relations
     * @return array
     */
    protected function getColumns(array $relations)
    {
        $columns = [];

        foreach ($relations as $relation) {
            $table = $relation->getQuery()->getQuery()->from;

            if (!isset($columns[$table])) {
                $listing = $relation->getRelated()->getConnection()->getSchemaBuilder()->getColumnListing($table);

                $columns[$table] = $listing;
            }
        }

        return $columns;
    }

    /**
     * Get the foreign key of a relationship.
     *
     * @param \Illuminate\Database\Eloquent\Relations\Relation $relation
     * @return string
     */
    protected function getForeignKey(Relation $relation)
    {
        if ($relation instanceof BelongsTo) {
            return $relation->getQualifiedOwnerKeyName();
        }

        if ($relation instanceof BelongsToMany) {
            return $relation->getQualifiedForeignPivotKeyName();
        }

        if ($relation instanceof HasManyThrough) {
            return $relation->getQualifiedFirstKeyName();
        }

        if ($relation instanceof HasOneOrMany) {
            return $relation->getQualifiedForeignKeyName();
        }

        throw new RuntimeException('This type of relationship is not supported.'); // @codeCoverageIgnore
    }
}

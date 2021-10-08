<?php namespace montesilva\LaravelSearchSort;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trait SearchSortTrait
 * @package montesilva\LaravelSearchSort
 * @property array $search_sort
 * @property string $table
 * @property string $primaryKey
 * @method string getTable()
 */
trait SearchSortTrait
{
    /**
     * @var array
     */
    protected $search_bindings = [];

    /**
     * Creates the search and sort scope
     * @param Builder $q
     * @param string $search
     * @param array $sorts
     * @param bool $join
     * @param float|null $threshold
     * @param bool $entireText
     * @param bool $entireTextOnly
     * @return Builder
     */
    public function scopeSearchSort(
        Builder $q,
        string $search,
        array $sorts,
        bool $join = true,
        float $threshold = null,
        bool $entireText = false,
        bool $entireTextOnly = false
    ): Builder {
        $query = clone $q;
        if ( $join ) {
            $this->scopeAddJoinsRestricted($query);
        }
        $this->scopeSearchRestricted($query, $search,null, $threshold, $entireText, $entireTextOnly);

        return $this->scopeSortRestricted($query, $sorts);
    }

    /**
     * Creates the search scope.
     *
     * @param Builder $q
     * @param string|null $search
     * @param bool $join
     * @param float|null $threshold
     * @param boolean $entireText
     * @param boolean $entireTextOnly
     * @return Builder
     */
    public function scopeSearch(
        Builder $q,
        string $search,
        bool $join = true,
        float $threshold = null,
        bool $entireText = false,
        bool $entireTextOnly = false
    ): Builder
    {
        if ( $search === false )
        {
            return $q;
        }
        $query = clone $q;

        if ( $join ) {
            $this->scopeAddJoinsRestricted($query);
        }

        return $this->scopeSearchRestricted($query, $search,null, $threshold, $entireText, $entireTextOnly);
    }

    /**
     * Creates the sort scope.
     * @param Builder $q
     * @param array $sorts
     * @param bool $join
     * @return Builder
     */
    public function scopeSort(Builder $q, array $sorts, bool $join = true): Builder {
        if(empty($sorts)) {
            return $q;
        }
        $query = clone $q;

        if ( $join ) {
            $this->scopeAddJoinsRestricted($query);
        }

        return $this->scopeSortRestricted($q, $sorts);
    }

    /**
     * Creates the scope to add the joins
     * @param Builder $q
     * @return Builder
     */
    public function scopeAddJoins(Builder $q): Builder {
        return $this->scopeAddJoinsRestricted($q);
    }

    /**
     * Creates the scope to add the joins
     * @param Builder $q
     * @return Builder
     */
    public function scopeAddJoinsRestricted(Builder $q): Builder {
        $query = clone $q;
        $query->select($this->getTable() . '.*');
        $this->makeJoins($query);
        $this->makeGroupBy($query);

        return $query;
    }

    /**
     * @param Builder $q
     * @param string $search
     * @param string $restriction
     * @param float|null $threshold
     * @param bool $entireText
     * @param bool $entireTextOnly
     * @return Builder
     */
    public function scopeSearchRestricted(
        Builder $q,
        string $search,
        string $restriction,
        float $threshold = null,
        bool $entireText = false,
        bool $entireTextOnly = false
    ): Builder
    {
        $query = clone $q;

        $search = mb_strtolower(trim($search));
        preg_match_all('/(?:")((?:\\\\.|[^\\\\"])*)(?:")|(\S+)/', $search, $matches);
        $words = $matches[1];
        for ($i = 2; $i < count($matches); $i++) {
            $words = array_filter($words) + $matches[$i];
        }

        $selects = [];
        $this->search_bindings = [];
        $relevance_count = 0;

        foreach ($this->getSearchColumns() as $column => $relevance)
        {
            $relevance_count += $relevance;

            if (!$entireTextOnly) {
                $queries = $this->getSearchQueriesForColumn($column, $relevance, $words);
            } else {
                $queries = [];
            }

            if ( ($entireText === true && count($words) > 1) || $entireTextOnly === true )
            {
                $queries[] = $this->getSearchQuery($column, $relevance, [$search], 50, '', '');
                $queries[] = $this->getSearchQuery($column, $relevance, [$search], 30, '%', '%');
            }

            foreach ($queries as $select)
            {
                if (!empty($select)) {
                    $selects[] = $select;
                }
            }
        }

        $this->addSelectsToQuery($query, $selects);

        // Default the threshold if no value was passed.
        if (is_null($threshold)) {
            $threshold = $relevance_count / count($this->getSearchColumns());
        }

        if (!empty($selects)) {
            $this->filterQueryWithRelevance($query, $selects, $threshold);
        }

        if(is_callable($restriction)) {
            $query = $restriction($query);
        }

        $this->mergeQueries($query, $q);

        return $q;
    }

    /**
     * @param Builder $q
     * @param array|null $sorts
     * @return Builder
     */
    public function scopeSortRestricted(
        Builder $q,
        array $sorts
    ): Builder {
        if (empty($sorts))
        {
            return $q;
        }

        $query = clone $q;

        // Get sortable columns
        $sortables = $this->getSortColumns();

        foreach ($sorts as $sort) {
            // Get the direction of which to sort
            $direction = $sort['dir'];
            $prop = $sort['prop'];
            // Ensure column to sort is part of model's sortables property
            // and that the direction is a valid value
            if ($sort
                && in_array($prop, $sortables)
                && $direction
                && in_array($direction, ['asc', 'desc'])) {
                // Return ordered query
                $query = $query->orderBy($prop, $direction);
            }
        }

        // return query
        return $query;
    }

    /**
     * Returns database driver Ex: mysql, pgsql, sqlite.
     *
     * @return array
     */
    protected function getDatabaseDriver(): array
    {
        $key = $this->connection ?: Config::get('database.default');
        return Config::get('database.connections.' . $key . '.driver');
    }

    /**
     * Returns the search columns.
     *
     * @return array
     */
    protected function getSearchColumns(): array
    {
        if (array_key_exists('search_columns', $this->search_sort['search_columns'])) {
            $driver = $this->getDatabaseDriver();
            $prefix = Config::get("database.connections.$driver.prefix");
            $columns = [];
            foreach($this->search_sort['search_columns'] as $column => $priority){
                $columns[$prefix . $column] = $priority;
            }
            return $columns;
        } else {
            return DB::connection()->getSchemaBuilder()->getColumnListing($this->table);
        }
    }

    /**
     * Returns the sortable columns
     * @return array
     */
    protected function getSortColumns(): array {
        return Arr::get($this->search_sort, 'sort_columns', []);
    }

    /**
     * Returns whether or not to keep duplicates.
     *
     * @return array|bool
     */
    protected function getGroupBy(): array
    {
        return Arr::get($this->search_sort, 'groupBy', []);
    }

    /**
     * Returns the table columns.
     *
     * @return array
     */
    public function getTableColumns(): array
    {
        return Arr::get($this->search_sort, 'table_columns', []);
    }

    /**
     * Returns the tables that are to be joined.
     *
     * @return array
     */
    protected function getJoins(): array
    {
        return Arr::get($this->search_sort, 'joins', []);
    }

    /**
     * Adds the sql joins to the query.
     *
     * @param Builder $query
     */
    protected function makeJoins(Builder $query)
    {
        foreach ($this->getJoins() as $table => $keys) {
            $query->leftJoin($table, function ($join) use ($keys) {
                $join->on($keys[0], '=', $keys[1]);
                if (array_key_exists(2, $keys) && array_key_exists(3, $keys)) {
                    $join->whereRaw($keys[2] . ' = "' . $keys[3] . '"');
                }
            });
        }
    }

    /**
     * Makes the query not repeat the results.
     *
     * @param Builder $query
     */
    protected function makeGroupBy(Builder $query)
    {
        if ($groupBy = $this->getGroupBy()) {
            $query->groupBy($groupBy);
        } else {
            if ($this->isSqlsrvDatabase()) {
                $columns = $this->getTableColumns();
            } else {
                $columns = $this->getTable() . '.' .$this->primaryKey;
            }

            $query->groupBy($columns);

            $joins = array_keys(($this->getJoins()));

            foreach ($this->getColumns() as $column => $relevance) {
                array_map(function ($join) use ($column, $query) {
                    if (Str::contains($column, $join)) {
                        $query->groupBy($column);
                    }
                }, $joins);
            }
        }
    }

    /**
     * Check if used database is SQLSRV.
     *
     * @return bool
     */
    protected function isSqlsrvDatabase(): bool
    {
        return $this->getDatabaseDriver() == 'sqlsrv';
    }

    /**
     * Puts all the select clauses to the main query.
     *
     * @param Builder $query
     * @param array $selects
     */
    protected function addSelectsToQuery(Builder $query, array $selects)
    {
        if (!empty($selects)) {
            $query->selectRaw('max(' . implode(' + ', $selects) . ') as ' . $this->getRelevanceField(), $this->search_bindings);
        }
    }

    /**
     * Adds the relevance filter to the query.
     *
     * @param Builder $query
     * @param array $selects
     * @param float $relevance_count
     */
    protected function filterQueryWithRelevance(Builder $query, array $selects, float $relevance_count)
    {
        $comparator = $this->isMysqlDatabase() ? $this->getRelevanceField() : implode(' + ', $selects);

        $relevance_count=number_format($relevance_count,2,'.','');

        if ($this->isMysqlDatabase()) {
            $bindings = [];
        } else {
            $bindings = $this->search_bindings;
        }

        $query->havingRaw("$comparator >= $relevance_count", $bindings);
        $query->orderBy($this->getRelevanceField(), 'desc');
    }


    /**
     * Check if used database is MySQL.
     *
     * @return bool
     */
    private function isMysqlDatabase(): bool
    {
        return $this->getDatabaseDriver() == 'mysql';
    }

    /**
     * Returns the search queries for the specified column.
     *
     * @param string $column
     * @param float $relevance
     * @param array $words
     * @return array
     */
    protected function getSearchQueriesForColumn(string $column, float $relevance, array $words): array
    {
        return [
            $this->getSearchQuery($column, $relevance, $words, 15),
            $this->getSearchQuery($column, $relevance, $words, 5, '', '%'),
            $this->getSearchQuery($column, $relevance, $words, 1, '%', '%')
        ];
    }

    /**
     * Returns the sql string for the given parameters.
     *
     * @param string $column
     * @param string $relevance
     * @param array $words
     * @param float $relevance_multiplier
     * @param string $pre_word
     * @param string $post_word
     * @return string
     */
    protected function getSearchQuery(
        string $column,
        string $relevance,
        array $words,
        float $relevance_multiplier,
        string $pre_word = '',
        string $post_word = ''
    ): string
    {
        $like_comparator = $this->getDatabaseDriver() == 'pgsql' ? 'ILIKE' : 'LIKE';
        $cases = [];

        foreach ($words as $word)
        {
            $cases[] = $this->getCaseCompare($column, $like_comparator, $relevance * $relevance_multiplier);
            $this->search_bindings[] = $pre_word . $word . $post_word;
        }

        return implode(' + ', $cases);
    }

    /**
     * Check if used database is PostgreSQL.
     *
     * @return bool
     */
    private function isPostgresqlDatabase(): bool
    {
        return $this->getDatabaseDriver() == 'pgsql';
    }

    /**
     * Returns the comparison string.
     *
     * @param string $column
     * @param string $compare
     * @param float $relevance
     * @return string
     */
    protected function getCaseCompare(string $column, string $compare, float $relevance): string
    {
        if($this->getDatabaseDriver() == 'pgsql') {
            $field = "LOWER(" . $column . ") " . $compare . " ?";
            return '(case when ' . $field . ' then ' . $relevance . ' else 0 end)';
        }

        $column = str_replace('.', '`.`', $column);
        $field = "LOWER(`" . $column . "`) " . $compare . " ?";
        return '(case when ' . $field . ' then ' . $relevance . ' else 0 end)';
    }

    /**
     * Merge our cloned query builder with the original one.
     *
     * @param Builder $clone
     * @param Builder $original
     */
    protected function mergeQueries(Builder $clone, Builder $original) {
        $tableName = DB::connection($this->connection)->getTablePrefix() . $this->getTable();
        if ($this->isPostgresqlDatabase()) {
            $original->from(DB::connection($this->connection)->raw("({$clone->toSql()}) as {$tableName}"));
        } else {
            $original->from(DB::connection($this->connection)->raw("({$clone->toSql()}) as `{$tableName}`"));
        }

        // First create a new array merging bindings
        $mergedBindings = array_merge_recursive(
            $clone->getBindings(),
            $original->getBindings()
        );

        // Then apply bindings WITHOUT global scopes which are already included. If not, there is a strange behaviour
        // with some scope's bindings remaning
        $original->withoutGlobalScopes()->setBindings($mergedBindings);
    }

    /**
     * Returns the relevance field name, alias of ratio column in the query.
     *
     * @return string
     */
    protected function getRelevanceField(): string
    {
        if ($this->relevanceField ?? false) {
            return $this->relevanceField;
        }

        // If property $this->relevanceField is not setted, return the default
        return 'relevance';
    }
}

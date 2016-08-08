<?php

namespace Muffin\Webservice;

use Cake\Datasource\AssociationInterface;
use Cake\Datasource\EagerLoadable;
use Cake\Datasource\EagerLoaderInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\Utility\Hash;
use Closure;
use InvalidArgumentException;
use Iterator;

/**
 * Exposes the methods for storing the associations that should be eager loaded
 * for a endpoint once a query is provided and delegates the job of creating the
 * required joins and decorating the results so that those associations can be
 * part of the result set.
 */
class EagerLoader implements EagerLoaderInterface
{

    /**
     * Nested array describing the association to be fetched
     * and the options to apply for each of them, if any
     *
     * @var array
     */
    protected $_containments = [];

    /**
     * Contains a nested array with the compiled containments tree
     * This is a normalized version of the user provided containments array.
     *
     * @var array
     */
    protected $_normalized;

    /**
     * List of options accepted by associations in contain()
     * index by key for faster access
     *
     * @var array
     */
    protected $_containOptions = [
        'associations' => 1,
        'foreignKey' => 1,
        'conditions' => 1,
        'fields' => 1,
        'sort' => 1,
        'matching' => 1,
        'queryBuilder' => 1,
        'finder' => 1,
        'joinType' => 1,
        'strategy' => 1,
        'negateMatch' => 1
    ];

    /**
     * A list of associations that should be loaded with a separate query
     *
     * @var array
     */
    protected $_loadExternal = [];

    /**
     * Contains a list of the association names that are to be eagerly loaded
     *
     * @var array
     */
    protected $_aliasList = [];

    /**
     * Another EagerLoader instance that will be used for 'matching' associations.
     *
     * @var \Muffin\Webservice\EagerLoader
     */
    protected $_matching;

    /**
     * A map of endpoint aliases pointing to the association objects they represent
     * for the query.
     *
     * @var array
     */
    protected $_joinsMap = [];

    /**
     * Controls whether or not fields from associated endpoints
     * will be eagerly loaded. When set to false, no fields will
     * be loaded from associations.
     *
     * @var bool
     */
    protected $_autoFields = true;

    /**
     * Sets the list of associations that should be eagerly loaded along for a
     * specific endpoint using when a query is provided. The list of associated endpoints
     * passed to this method must have been previously set as associations using the
     * Endpoint API.
     *
     * Associations can be arbitrarily nested using dot notation or nested arrays,
     * this allows this object to calculate joins or any additional queries that
     * must be executed to bring the required associated data.
     *
     * Accepted options per passed association:
     *
     * - foreignKey: Used to set a different field to match both endpoints, if set to false
     *   no join conditions will be generated automatically
     * - fields: An array with the fields that should be fetched from the association
     * - queryBuilder: Equivalent to passing a callable instead of an options array
     * - matching: Whether to inform the association class that it should filter the
     *  main query by the results fetched by that class.
     * - joinType: For joinable associations, the SQL join type to use.
     * - strategy: The loading strategy to use (join, select, subquery)
     *
     * @param array|string $associations list of endpoint aliases to be queried.
     * When this method is called multiple times it will merge previous list with
     * the new one.
     * @return array Containments.
     */
    public function contain($associations = [])
    {
        if (empty($associations)) {
            return $this->_containments;
        }

        $associations = (array)$associations;
        $associations = $this->_reformatContain($associations, $this->_containments);
        $this->_normalized = null;
        $this->_loadExternal = [];
        $this->_aliasList = [];
        return $this->_containments = $associations;
    }

    /**
     * Remove any existing non-matching based containments.
     *
     * This will reset/clear out any contained associations that were not
     * added via matching().
     *
     * @return void
     */
    public function clearContain()
    {
        $this->_containments = [];
        $this->_normalized = null;
        $this->_loadExternal = [];
        $this->_aliasList = [];
    }

    /**
     * Set whether or not contained associations will load fields automatically.
     *
     * @param bool|null $value The value to set.
     * @return bool The current value.
     */
    public function autoFields($value = null)
    {
        if ($value !== null) {
            $this->_autoFields = (bool)$value;
        }
        return $this->_autoFields;
    }

    /**
     * Adds a new association to the list that will be used to filter the results of
     * any given query based on the results of finding records for that association.
     * You can pass a dot separated path of associations to this method as its first
     * parameter, this will translate in setting all those associations with the
     * `matching` option.
     *
     * If called with no arguments it will return the current tree of associations to
     * be matched.
     *
     * @param string|null $assoc A single association or a dot separated path of associations.
     * @param callable|null $builder the callback function to be used for setting extra
     * options to the filtering query
     * @param array $options Extra options for the association matching, such as 'joinType'
     * and 'fields'
     * @return array The resulting containments array
     */
    public function matching($assoc = null, callable $builder = null, $options = [])
    {
        if ($this->_matching === null) {
            $this->_matching = new self();
        }

        if ($assoc === null) {
            return $this->_matching->contain();
        }

        $assocs = explode('.', $assoc);
        $last = array_pop($assocs);
        $containments = [];
        $pointer =& $containments;
        $options += ['joinType' => 'INNER'];
        $opts = ['matching' => true] + $options;
        unset($opts['negateMatch']);

        foreach ($assocs as $name) {
            $pointer[$name] = $opts;
            $pointer =& $pointer[$name];
        }

        $pointer[$last] = ['queryBuilder' => $builder, 'matching' => true] + $options;
        return $this->_matching->contain($containments);
    }

    /**
     * Returns the fully normalized array of associations that should be eagerly
     * loaded for a endpoint. The normalized array will restructure the original array
     * by sorting all associations under one key and special options under another.
     *
     * Each of the levels of the associations tree will converted to a \Cake\Datasource\EagerLoadable
     * object, that contains all the information required for the association objects
     * to load the information from the webservice.
     *
     * Additionally it will set an 'instance' key per association containing the
     * association instance from the corresponding source endpoint
     *
     * @param \Cake\datasource\RepositoryInterface $repository The endpoint containing the association that
     * will be normalized
     * @return array
     */
    public function normalized(RepositoryInterface $repository)
    {
        if ($this->_normalized !== null || empty($this->_containments)) {
            return (array)$this->_normalized;
        }

        $contain = [];
        foreach ($this->_containments as $alias => $options) {
            if (!empty($options['instance'])) {
                $contain = (array)$this->_containments;
                break;
            }
            $contain[$alias] = $this->_normalizeContain(
                $repository,
                $alias,
                $options,
                ['root' => null]
            );
        }

        return $this->_normalized = $contain;
    }

    /**
     * Formats the containments array so that associations are always set as keys
     * in the array. This function merges the original associations array with
     * the new associations provided
     *
     * @param array $associations user provided containments array
     * @param array $original The original containments array to merge
     * with the new one
     * @return array
     */
    protected function _reformatContain($associations, $original)
    {
        $result = $original;

        foreach ((array)$associations as $endpoint => $options) {
            $pointer =& $result;
            if (is_int($endpoint)) {
                $endpoint = $options;
                $options = [];
            }

            if ($options instanceof EagerLoadable) {
                $options = $options->asContainArray();
                $endpoint = key($options);
                $options = current($options);
            }

            if (isset($this->_containOptions[$endpoint])) {
                $pointer[$endpoint] = $options;
                continue;
            }

            if (strpos($endpoint, '.')) {
                $path = explode('.', $endpoint);
                $endpoint = array_pop($path);
                foreach ($path as $t) {
                    $pointer += [$t => []];
                    $pointer =& $pointer[$t];
                }
            }

            if (is_array($options)) {
                $options = isset($options['config']) ?
                    $options['config'] + $options['associations'] :
                    $options;
                $options = $this->_reformatContain(
                    $options,
                    isset($pointer[$endpoint]) ? $pointer[$endpoint] : []
                );
            }

            if ($options instanceof Closure) {
                $options = ['queryBuilder' => $options];
            }

            $pointer += [$endpoint => []];

            if (isset($options['queryBuilder']) && isset($pointer[$endpoint]['queryBuilder'])) {
                $first = $pointer[$endpoint]['queryBuilder'];
                $second = $options['queryBuilder'];
                $options['queryBuilder'] = function ($query) use ($first, $second) {
                    return $second($first($query));
                };
            }

            $pointer[$endpoint] = $options + $pointer[$endpoint];
        }

        return $result;
    }

    /**
     * Modifies the passed query to apply joins or any other transformation required
     * in order to eager load the associations described in the `contain` array.
     * This method will not modify the query for loading external associations, i.e.
     * those that cannot be loaded without executing a separate query.
     *
     * @param \Muffin]Webservice\Query $query The query to be modified
     * @param \Cake\Datasource\RepositoryInterface $repository The repository containing the associations
     * @param bool $includeFields whether to append all fields from the associations
     * to the passed query. This can be overridden according to the settings defined
     * per association in the containments array
     * @return void
     */
    public function attachAssociations(Query $query, RepositoryInterface $repository, $includeFields)
    {
        if (empty($this->_containments) && $this->_matching === null) {
            return;
        }

        $attachable = $this->attachableAssociations($repository);
        $processed = [];
        do {
            foreach ($attachable as $alias => $loadable) {
                $config = $loadable->config() + [
                    'aliasPath' => $loadable->aliasPath(),
                    'propertyPath' => $loadable->propertyPath(),
                    'includeFields' => $includeFields,
                ];
                $loadable->instance()->attachTo($query, $config);
                $processed[$alias] = true;
            }

            $newAttachable = $this->attachableAssociations($repository);
            $attachable = array_diff_key($newAttachable, $processed);
        } while (!empty($attachable));
    }

    /**
     * Returns an array with the associations that can be fetched using a single query,
     * the array keys are the association aliases and the values will contain an array
     * with \Cake\Datasource\EagerLoadable objects.
     *
     * @param \Cake\Datasource\RepositoryInterface $repository The endpoint containing the associations to be
     * attached
     * @return array
     */
    public function attachableAssociations(RepositoryInterface $repository)
    {
        $contain = $this->normalized($repository);

        $matching = $this->_matching ? $this->_matching->normalized($repository) : [];
        $this->_fixStrategies();
        $this->_loadExternal = [];
        return $this->_resolveJoins($contain, $matching);
    }

    /**
     * Returns an array with the associations that need to be fetched using a
     * separate query, each array value will contain a \Cake\Datasource\EagerLoadable object.
     *
     * @param \Cake\Datasource\RepositoryInterface $repository The endpoint containing the associations
     * to be loaded
     * @return \Cake\Datasource\EagerLoadable[]
     */
    public function externalAssociations(RepositoryInterface $repository)
    {
        if ($this->_loadExternal) {
            return $this->_loadExternal;
        }

        $this->attachableAssociations($repository);
        return $this->_loadExternal;
    }

    /**
     * Auxiliary function responsible for fully normalizing deep associations defined
     * using `contain()`
     *
     * @param \Cake\Datasource\RepositoryInterface $parent owning side of the association
     * @param string $alias name of the association to be loaded
     * @param array $options list of extra options to use for this association
     * @param array $paths An array with two values, the first one is a list of dot
     * separated strings representing associations that lead to this `$alias` in the
     * chain of associations to be loaded. The second value is the path to follow in
     * entities' properties to fetch a record of the corresponding association.
     * @return array normalized associations
     * @throws \InvalidArgumentException When containments refer to associations that do not exist.
     */
    protected function _normalizeContain(RepositoryInterface $parent, $alias, $options, $paths)
    {
        $defaults = $this->_containOptions;
        $instance = $parent->association($alias);
        if (!$instance) {
            throw new InvalidArgumentException(
                sprintf('%s is not associated with %s', $parent->alias(), $alias)
            );
        }
        if ($instance->alias() !== $alias) {
            throw new InvalidArgumentException(sprintf(
                "You have contained '%s' but that association was bound as '%s'.",
                $alias,
                $instance->alias()
            ));
        }

        $paths += ['aliasPath' => '', 'propertyPath' => '', 'root' => $alias];
        $paths['aliasPath'] .= '.' . $alias;
        $paths['propertyPath'] .= '.' . $instance->property();

        $endpoint = $instance->target();

        $extra = array_diff_key($options, $defaults);
        $config = [
            'associations' => [],
            'instance' => $instance,
            'config' => array_diff_key($options, $extra),
            'aliasPath' => trim($paths['aliasPath'], '.'),
            'propertyPath' => trim($paths['propertyPath'], '.')
        ];
        $config['canBeJoined'] = $instance->canBeJoined($config['config']);
        $eagerLoadable = new EagerLoadable($alias, $config);

        if ($config['canBeJoined']) {
            $this->_aliasList[$paths['root']][$alias][] = $eagerLoadable;
        } else {
            $paths['root'] = $config['aliasPath'];
        }

        foreach ($extra as $t => $assoc) {
            $eagerLoadable->addAssociation(
                $t,
                $this->_normalizeContain($endpoint, $t, $assoc, $paths)
            );
        }

        return $eagerLoadable;
    }

    /**
     * Iterates over the joinable aliases list and corrects the fetching strategies
     * in order to avoid aliases collision in the generated queries.
     *
     * This function operates on the array references that were generated by the
     * _normalizeContain() function.
     *
     * @return void
     */
    protected function _fixStrategies()
    {
        foreach ($this->_aliasList as $aliases) {
            foreach ($aliases as $configs) {
                if (count($configs) < 2) {
                    continue;
                }
                foreach ($configs as $loadable) {
                    if (strpos($loadable->aliasPath(), '.')) {
                        $this->_correctStrategy($loadable);
                    }
                }
            }
        }
    }

    /**
     * Changes the association fetching strategy if required because of duplicate
     * under the same direct associations chain
     *
     * @param \Cake\Datasource\EagerLoadable $loadable The association config
     * @return void
     */
    protected function _correctStrategy($loadable)
    {
        $config = $loadable->config();
        $currentStrategy = isset($config['strategy']) ?
            $config['strategy'] :
            'join';

        if (!$loadable->canBeJoined() || $currentStrategy !== 'join') {
            return;
        }

        $config['strategy'] = Association::STRATEGY_SELECT;
        $loadable->config($config);
        $loadable->canBeJoined(false);
    }

    /**
     * Helper function used to compile a list of all associations that can be
     * joined in the query.
     *
     * @param array $associations list of associations from which to obtain joins.
     * @param array $matching list of associations that should be forcibly joined.
     * @return array
     */
    protected function _resolveJoins($associations, $matching = [])
    {
        $result = [];
        foreach ($matching as $endpoint => $loadable) {
            $result[$endpoint] = $loadable;
            $result += $this->_resolveJoins($loadable->associations(), []);
        }
        foreach ($associations as $endpoint => $loadable) {
            $inMatching = isset($matching[$endpoint]);
            if (!$inMatching && $loadable->canBeJoined()) {
                $result[$endpoint] = $loadable;
                $result += $this->_resolveJoins($loadable->associations(), []);
                continue;
            }

            if ($inMatching) {
                $this->_correctStrategy($loadable);
            }

            $loadable->canBeJoined(false);
            $this->_loadExternal[] = $loadable;
        }
        return $result;
    }

    /**
     * Decorates the passed statement object in order to inject data from associations
     * that cannot be joined directly.
     *
     * @param \Cake\Datasource\QueryInterface $query The query for which to eager load external
     * associations
     * @param \Muffin\Webservice\WebserviceResultSetInterface $results The statement created after executing the $query
     * @return \Muffin\Webservice\WebserviceResultSetInterface statement modified statement with extra loaders
     */
    public function loadExternal($query, WebserviceResultSetInterface $results)
    {
        $external = $this->externalAssociations($query->repository());
        if (empty($external)) {
            return $results;
        }

//        $driver = $query->connection()->driver();
        list($collected, $results) = $this->_collectKeys($external, $query, $results);

        foreach ($external as $meta) {
            $contain = $meta->associations();
            $instance = $meta->instance();
            $config = $meta->config();
            $alias = $instance->source()->alias();
            $path = $meta->aliasPath();

            $requiresKeys = $instance->requiresKeys($config);
            if ($requiresKeys && empty($collected[$path][$alias])) {
                continue;
            }

            $keys = isset($collected[$path][$alias]) ? $collected[$path][$alias] : null;
            $f = $instance->eagerLoader(
                $config + [
                    'query' => $query,
                    'contain' => $contain,
                    'keys' => $keys,
                    'nestKey' => $meta->aliasPath()
                ]
            );
            foreach ($results as $index => &$result) {
                $result = $f($result);
            }
        }
        $results->rewind();

        return $results;
    }

    /**
     * Returns an array having as keys a dotted path of associations that participate
     * in this eager loader. The values of the array will contain the following keys
     *
     * - alias: The association alias
     * - instance: The association instance
     * - canBeJoined: Whether or not the association will be loaded using a JOIN
     * - resourceClass: The entity that should be used for hydrating the results
     * - nestKey: A dotted path that can be used to correctly insert the data into the results.
     * - matching: Whether or not it is an association loaded through `matching()`.
     *
     * @param \Cake\Datasource\RepositoryInterface $repository The endpoint containing the association that
     * will be normalized
     * @return array
     */
    public function associationsMap(RepositoryInterface $repository)
    {
        $map = [];

        if (!$this->matching() && !$this->contain() && empty($this->_joinsMap)) {
            return $map;
        }

        $visitor = function ($level, $matching = false) use (&$visitor, &$map) {
            /* @var \Cake\Datasource\EagerLoadable[] $level */
            foreach ($level as $assoc => $meta) {
                $canBeJoined = $meta->canBeJoined();
                $instance = $meta->instance();
                $associations = $meta->associations();
                $forMatching = $meta->forMatching();
                $map[] = [
                    'alias' => $assoc,
                    'instance' => $instance,
                    'canBeJoined' => $canBeJoined,
                    'resourceClass' => $instance->target()->resourceClass(),
                    'nestKey' => $canBeJoined ? $assoc : $meta->aliasPath(),
                    'matching' => $forMatching !== null ? $forMatching : $matching
                ];
                if ($canBeJoined && $associations) {
                    $visitor($associations, $matching);
                }
            }
        };
        $visitor($this->_matching->normalized($repository), true);
        $visitor($this->normalized($repository));
        $visitor($this->_joinsMap);
        return $map;
    }

    /**
     * Registers a endpoint alias, typically loaded as a join in a query, as belonging to
     * an association. This helps hydrators know what to do with the columns coming
     * from such joined endpoint.
     *
     * @param string $alias The endpoint
     * alias as it appears in the query.
     * @param \Cake\Datasource\AssociationInterface $assoc The association object the alias represents;
     * will be normalized
     * @param bool $asMatching Whether or not this join results should be treated as a
     * 'matching' association.
     * @return void
     */
    public function addToJoinsMap($alias, AssociationInterface $assoc, $asMatching = false)
    {
        $this->_joinsMap[$alias] = new EagerLoadable($alias, [
            'aliasPath' => $alias,
            'instance' => $assoc,
            'canBeJoined' => true,
            'forMatching' => $asMatching,
        ]);
    }

    /**
     * Helper function used to return the keys from the query records that will be used
     * to eagerly load associations.
     *
     * @param \Cake\Datasource\EagerLoadable[] $external the list of external associations to be loaded
     * @param \Cake\Datasource\QueryInterface $query The query from which the results where generated
     * @param \Iterator $results The statement to work on
     * @return array
     */
    protected function _collectKeys($external, $query, Iterator $results)
    {
        $collectKeys = [];
        foreach ($external as $meta) {
            $instance = $meta->instance();
            if (!$instance->requiresKeys($meta->config())) {
                continue;
            }

            $source = $instance->source();
            $keys = $instance->type() === Association::MANY_TO_ONE ?
                (array)$instance->foreignKey() :
                (array)$instance->bindingKey();

            $alias = $source->alias();
            $pkFields = [];
            foreach ($keys as $key) {
                $pkFields[] = key($query->aliasField($key, $alias));
            }
            $collectKeys[$meta->aliasPath()] = [$alias, $pkFields, count($pkFields) === 1];
        }

        if (empty($collectKeys)) {
            return [[], $results];
        }

        return [$this->_groupKeys($results, $collectKeys), $results];
    }

    /**
     * Helper function used to iterate a statement and extract the columns
     * defined in $collectKeys
     *
     * @param \Iterator $results The statement to read from.
     * @param array $collectKeys The keys to collect
     * @return array
     */
    protected function _groupKeys(Iterator $results, $collectKeys)
    {
        $keys = [];
        foreach ($results as $result) {
            foreach ($collectKeys as $nestKey => $parts) {
                // Missed joins will have null in the results.
                if ($parts[2] === true && !isset($result[$parts[1][0]])) {
                    continue;
                }
                if ($parts[2] === true) {
                    $value = $result[$parts[1][0]];
                    $keys[$nestKey][$parts[0]][$value] = $value;
                    continue;
                }

                // Handle composite keys.
                $collected = [];
                foreach ($parts[1] as $key) {
                    $collected[] = $result[$key];
                }
                $keys[$nestKey][$parts[0]][implode(';', $collected)] = $collected;
            }
        }

        $results->rewind();
        return $keys;
    }
}
<?php

declare(strict_types=1);

namespace Rinvex\Cacheable;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

trait CacheableEloquent
{
    /**
     * Indicate if the model cache clear is enabled.
     *
     * @var bool
     */
    protected static $cacheClearEnabled = true;

    /**
     * The model cache driver.
     *
     * @var string
     */
    protected $cacheDriver;

    /**
     * The model cache lifetime.
     *
     * @var float|int
     */
    protected $cacheLifetime = -1;

    /**
     * Register an updated model event with the dispatcher.
     *
     * @param \Closure|string $callback
     *
     * @return void
     */
    abstract public static function updated($callback);

    /**
     * Register a created model event with the dispatcher.
     *
     * @param \Closure|string $callback
     *
     * @return void
     */
    abstract public static function created($callback);

    /**
     * Register a deleted model event with the dispatcher.
     *
     * @param \Closure|string $callback
     *
     * @return void
     */
    abstract public static function deleted($callback);

    /**
     * Boot the cacheable eloquent trait for a model.
     *
     * @return void
     */
    public static function bootCacheableEloquent()
    {
        static::attacheEvents();
    }

    /**
     * Store the given cache key for the given model by mimicking cache tags.
     *
     * @param string $modelName
     * @param string $cacheKey
     *
     * @return void
     */
    protected static function storeCacheKey(string $modelName, string $cacheKey)
    {
        $keysFile = storage_path('framework/cache/data/rinvex.cacheable.json');
        $cacheKeys = static::getCacheKeys($keysFile);

        if (! isset($cacheKeys[$modelName]) || ! in_array($cacheKey, $cacheKeys[$modelName])) {
            $cacheKeys[$modelName][] = $cacheKey;
            file_put_contents($keysFile, json_encode($cacheKeys));
        }
    }

    /**
     * Get cache keys from the given file.
     *
     * @param string $file
     *
     * @return array
     */
    protected static function getCacheKeys($file)
    {
        if (! file_exists($file)) {
            file_put_contents($file, null);
        }

        return json_decode(file_get_contents($file), true) ?: [];
    }

    /**
     * Flush cache keys of the given model by mimicking cache tags.
     *
     * @param string $modelName
     *
     * @return array
     */
    protected static function flushCacheKeys(string $modelName): array
    {
        $flushedKeys = [];
        $keysFile = storage_path('framework/cache/data/rinvex.cacheable.json');
        $cacheKeys = static::getCacheKeys($keysFile);

        if (isset($cacheKeys[$modelName])) {
            $flushedKeys = $cacheKeys[$modelName];

            unset($cacheKeys[$modelName]);

            file_put_contents($keysFile, json_encode($cacheKeys));
        }

        return $flushedKeys;
    }

    /**
     * Set the model cache lifetime.
     *
     * @param float|int $cacheLifetime
     *
     * @return $this
     */
    public function setCacheLifetime($cacheLifetime)
    {
        $this->cacheLifetime = $cacheLifetime;

        return $this;
    }

    /**
     * Get the model cache lifetime.
     *
     * @return float|int
     */
    public function getCacheLifetime()
    {
        return $this->cacheLifetime;
    }

    /**
     * Set the model cache driver.
     *
     * @param string $cacheDriver
     *
     * @return $this
     */
    public function setCacheDriver($cacheDriver)
    {
        $this->cacheDriver = $cacheDriver;

        return $this;
    }

    /**
     * Get the model cache driver.
     *
     * @return string
     */
    public function getCacheDriver()
    {
        return $this->cacheDriver;
    }

    /**
     * Determine if model cache clear is enabled.
     *
     * @return bool
     */
    public static function isCacheClearEnabled()
    {
        return static::$cacheClearEnabled;
    }

    /**
     * Forget the model cache.
     *
     * @return void
     */
    public static function forgetCache()
    {
        static::fireCacheFlushEvent('cache.flushing');

        // Flush cache tags
        if (method_exists(app('cache')->getStore(), 'tags')) {
            app('cache')->tags(static::class)->flush();
        } else {
            // Flush cache keys, then forget actual cache
            foreach (static::flushCacheKeys(static::class) as $cacheKey) {
                app('cache')->forget($cacheKey);
            }
        }

        static::fireCacheFlushEvent('cache.flushed', false);
    }

    /**
     * Fire the given event for the model.
     *
     * @param string $event
     * @param bool   $halt
     *
     * @return mixed
     */
    protected static function fireCacheFlushEvent($event, $halt = true)
    {
        if (! isset(static::$dispatcher)) {
            return true;
        }

        // We will append the names of the class to the event to distinguish it from
        // other model events that are fired, allowing us to listen on each model
        // event set individually instead of catching event for all the models.
        $event = "eloquent.{$event}: ".static::class;

        $method = $halt ? 'until' : 'fire';

        return static::$dispatcher->$method($event, static::class);
    }

    /**
     * Reset cached model to its defaults.
     *
     * @return $this
     */
    public function resetCacheConfig()
    {
        $this->cacheDriver = null;
        $this->cacheLifetime = null;

        return $this;
    }

    /**
     * Generate unique cache key.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $builder
     * @param array                                                                    $columns
     *
     * @return string
     */
    protected function generateCacheKey($builder, array $columns)
    {
        $query = $builder instanceof Builder ? $builder->getQuery() : $builder;
        $vars = [
            'aggregate' => $query->aggregate,
            'columns' => $query->columns,
            'distinct' => $query->distinct,
            'from' => $query->from,
            'joins' => $query->joins,
            'wheres' => $query->wheres,
            'groups' => $query->groups,
            'havings' => $query->havings,
            'orders' => $query->orders,
            'limit' => $query->limit,
            'offset' => $query->offset,
            'unions' => $query->unions,
            'unionLimit' => $query->unionLimit,
            'unionOffset' => $query->unionOffset,
            'unionOrders' => $query->unionOrders,
            'lock' => $query->lock,
        ];

        return md5(json_encode([
            $vars,
            $columns,
            static::class,
            $this->getCacheDriver(),
            $this->getCacheLifetime(),
            $builder instanceof Builder ? $builder->getEagerLoads() : null,
            $builder->getBindings(),
            $builder->toSql(),
        ]));
    }

    /**
     * Cache given callback.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $builder
     * @param array                                                                    $columns
     * @param \Closure                                                                 $closure
     *
     * @return mixed
     */
    public function cacheQuery($builder, array $columns, Closure $closure)
    {
        $modelName = static::class;
        $lifetime = $this->getCacheLifetime();
        $cacheKey = $this->generateCacheKey($builder, $columns);

        // Switch cache driver on runtime
        if ($driver = $this->getCacheDriver()) {
            app('cache')->setDefaultDriver($driver);
        }

        // We need cache tags, check if default driver supports it
        if (method_exists(app('cache')->getStore(), 'tags')) {
            $result = $lifetime === -1 ? app('cache')->tags($modelName)->rememberForever($cacheKey, $closure) : app('cache')->tags($modelName)->remember($cacheKey, $lifetime, $closure);

            return $result;
        }

        $result = $lifetime === -1 ? app('cache')->rememberForever($cacheKey, $closure) : app('cache')->remember($cacheKey, $lifetime, $closure);

        // Default cache driver doesn't support tags, let's do it manually
        static::storeCacheKey($modelName, $cacheKey);

        // We're done, let's clean up!
        $this->resetCacheConfig();

        return $result;
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param \Illuminate\Database\Query\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function newEloquentBuilder($query)
    {
        return new EloquentBuilder($query);
    }

    /**
     * Attach events to the model.
     *
     * @return void
     */
    protected static function attacheEvents()
    {
        static::updated(function (Model $cachedModel) {
            if ($cachedModel::isCacheClearEnabled()) {
                $cachedModel::forgetCache();
            }
        });

        static::created(function (Model $cachedModel) {
            if ($cachedModel::isCacheClearEnabled()) {
                $cachedModel::forgetCache();
            }
        });

        static::deleted(function (Model $cachedModel) {
            if ($cachedModel::isCacheClearEnabled()) {
                $cachedModel::forgetCache();
            }
        });
    }

    /**
     * Define a many-to-many relationship.
     *
     * @param  string $related
     * @param  string $table
     * @param  string $foreignPivotKey
     * @param  string $relatedPivotKey
     * @param  string $parentKey
     * @param  string $relatedKey
     * @param  string $relation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null,
                                  $parentKey = null, $relatedKey = null, $relation = null)
    {
        // If no relationship name was passed, we will pull backtraces to get the
        // name of the calling function. We will use that function name as the
        // title of this relation since that is a great convention to apply.
        if (is_null($relation)) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // First, we'll need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we'll make the query
        // instances as well as the relationship instances we need for this.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // If no table name was provided, we can guess it by concatenating the two
        // models using underscores in alphabetical order. The two model names
        // are transformed to snake case from their default CamelCase also.
        if (is_null($table)) {
            $table = $this->joiningTable($related);
        }

        return new BelongsToMany(
            $instance->newQuery(), $this, $table, $foreignPivotKey,
            $relatedPivotKey, $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(), $relation
        );
    }
}

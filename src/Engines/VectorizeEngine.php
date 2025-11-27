<?php

namespace ScoutVectorize\Engines;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use ScoutVectorize\VectorizeClient;

class VectorizeEngine extends Engine
{
    public function __construct(
        protected VectorizeClient $client
    ) {}

    /**
     * Update the given model in the index.
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $documents = $models->map(function ($model) {
            $searchableData = $model->toSearchableArray();

            if (empty($searchableData)) {
                return null;
            }

            // Convert searchable array to text for embedding
            $text = $this->convertToSearchableText($model, $searchableData);

            return [
                'id' => $this->getScoutKey($model),
                'text' => $text,
                'metadata' => [
                    'model' => get_class($model),
                    'key' => $model->getScoutKey(),
                ],
            ];
        })->filter()->values()->all();

        if (empty($documents)) {
            return;
        }

        $this->client->batchUpsert($documents);
    }

    /**
     * Convert model data to searchable text.
     */
    protected function convertToSearchableText($model, array $searchableData): string
    {
        // If model has a custom toSearchableText method, use that
        if (method_exists($model, 'toSearchableText')) {
            return $model->toSearchableText();
        }

        // Otherwise, flatten the searchable array
        return collect($searchableData)
            ->filter()
            ->map(function ($value) {
                if (is_array($value)) {
                    return implode(' ', array_filter($value));
                }
                return $value;
            })
            ->filter()
            ->implode('. ');
    }

    /**
     * Get the Scout key for the model.
     */
    protected function getScoutKey($model): string
    {
        $class = str_replace('\\', '_', get_class($model));
        return "{$class}_{$model->getScoutKey()}";
    }

    /**
     * Remove the given model from the index.
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $ids = $models->map(fn($model) => $this->getScoutKey($model))->all();

        $this->client->deleteVectors($ids);
    }

    /**
     * Perform the given search on the engine.
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, [
            'limit' => $builder->limit ?? 10,
        ]);
    }

    /**
     * Perform the given search on the engine with pagination.
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        // Vector search doesn't support traditional offset-based pagination
        // We'll retrieve more results and slice them
        $limit = $perPage * $page;

        return $this->performSearch($builder, [
            'limit' => min($limit, 100), // Cap at 100 for performance
        ]);
    }

    /**
     * Perform the search.
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $limit = $options['limit'] ?? 10;

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->client,
                $builder->query,
                $options
            );
        }

        // Perform the vector search
        $results = $this->client->search(
            query: $builder->query,
            topK: $limit,
            filter: $this->buildFilter($builder)
        );

        return [
            'results' => $results,
            'total' => count($results),
        ];
    }

    /**
     * Build metadata filter from Scout builder.
     */
    protected function buildFilter(Builder $builder): ?array
    {
        if (empty($builder->wheres)) {
            // Always filter by model type
            return [
                'model' => $builder->model::class,
            ];
        }

        // Combine model filter with custom filters
        return array_merge(
            ['model' => $builder->model::class],
            $builder->wheres
        );
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds($results)
    {
        return collect($results['results'])->map(function ($result) {
            // Extract the original model key from our prefixed ID
            // Format: "App_Models_Product_123" -> "123"
            $metadata = $result['metadata'] ?? [];
            return isset($metadata['key']) ? (int) $metadata['key'] : null;
        })->filter()->values();
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results['results']) === 0) {
            return $model->newCollection();
        }

        $objectIds = $this->mapIds($results)->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds(
            $builder,
            $objectIds
        )->filter(function ($model) use ($objectIdPositions) {
            return isset($objectIdPositions[$model->getScoutKey()]);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if (count($results['results']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = $this->mapIds($results)->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds(
            $builder,
            $objectIds
        )->cursor()->filter(function ($model) use ($objectIdPositions) {
            return isset($objectIdPositions[$model->getScoutKey()]);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     */
    public function getTotalCount($results): int
    {
        return $results['total'] ?? 0;
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush($model): void
    {
        $modelClass = get_class($model);

        // Get embedding dimensions from the model
        $embeddingModel = $this->client->getEmbeddingModel();
        $dimensions = $this->getModelDimensions($embeddingModel);

        // Create a dummy embedding vector
        $dummyVector = array_fill(0, $dimensions, 0.0);

        $deletedIds = [];

        // Keep querying and deleting until no vectors remain for this model
        while (true) {
            // Query for next batch of vectors with model filter
            $result = $this->client->queryVectors($dummyVector, 100, [
                'model' => $modelClass,
            ]);

            if (!isset($result['result']['matches']) || count($result['result']['matches']) === 0) {
                break;
            }

            // Filter out already-deleted IDs
            $matches = array_filter($result['result']['matches'], function($match) use ($deletedIds) {
                return !in_array($match['id'], $deletedIds);
            });

            if (empty($matches)) {
                break;
            }

            $ids = array_column($matches, 'id');

            $this->client->deleteVectors($ids);
            $deletedIds = array_merge($deletedIds, $ids);
        }
    }

    /**
     * Get the dimensions for a given embedding model.
     */
    protected function getModelDimensions(string $model): int
    {
        return match($model) {
            '@cf/baai/bge-small-en-v1.5' => 384,
            '@cf/baai/bge-base-en-v1.5' => 768,
            '@cf/baai/bge-large-en-v1.5' => 1024,
            default => 768,
        };
    }

    /**
     * Create a search index.
     */
    public function createIndex($name, array $options = []): void
    {
        // Cloudflare Vectorize indexes are created via the dashboard/API
        // This is a no-op
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex($name): void
    {
        // Cloudflare Vectorize indexes are deleted via the dashboard/API
        // This is a no-op
    }
}

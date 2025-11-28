<?php

namespace ScoutVectorize\Tests;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Mockery;
use ScoutVectorize\Engines\VectorizeEngine;
use ScoutVectorize\VectorizeClient;

class VectorizeEngineTest extends TestCase
{
    protected VectorizeClient $client;
    protected VectorizeEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(VectorizeClient::class);
        $this->engine = new VectorizeEngine($this->client);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_update_with_empty_collection(): void
    {
        $models = new Collection([]);

        $this->client->shouldNotReceive('batchUpsert');

        $this->engine->update($models);

        $this->assertTrue(true);
    }

    public function test_update_with_valid_models(): void
    {
        $model1 = $this->createSearchableModel(1, ['title' => 'Test Title', 'content' => 'Test Content']);
        $model2 = $this->createSearchableModel(2, ['title' => 'Another Title', 'content' => 'More Content']);

        $models = new Collection([$model1, $model2]);

        $this->client->shouldReceive('batchUpsert')
            ->once()
            ->with(Mockery::on(function ($documents) {
                $this->assertCount(2, $documents);

                // Check first document
                $this->assertStringContainsString('_1', $documents[0]['id']);
                $this->assertEquals('Test Title. Test Content', $documents[0]['text']);
                $this->assertEquals('Test Title', $documents[0]['metadata']['title']);
                $this->assertEquals('Test Content', $documents[0]['metadata']['content']);
                $this->assertStringContainsString('SearchableModel', $documents[0]['metadata']['model']);
                $this->assertEquals(1, $documents[0]['metadata']['key']);

                // Check second document
                $this->assertStringContainsString('_2', $documents[1]['id']);
                $this->assertEquals('Another Title. More Content', $documents[1]['text']);

                return true;
            }));

        $this->engine->update($models);
    }

    public function test_update_with_custom_to_searchable_text(): void
    {
        $model1 = Mockery::mock(SearchableModel::class)->makePartial();
        $model1->shouldReceive('getScoutKey')->andReturn(1);
        $model1->shouldReceive('toSearchableArray')->andReturn(['title' => 'Test']);
        $model1->shouldReceive('method_exists')->andReturn(true);

        $model2 = new class extends SearchableModel {
            public function getScoutKey(): int|string
            {
                return 1;
            }

            public function toSearchableArray(): array
            {
                return ['title' => 'Test'];
            }

            public function toSearchableText(): string
            {
                return 'Custom searchable text';
            }
        };

        $models = new Collection([$model2]);

        $this->client->shouldReceive('batchUpsert')
            ->once()
            ->with(Mockery::on(function ($documents) {
                $this->assertEquals('Custom searchable text', $documents[0]['text']);
                return true;
            }));

        $this->engine->update($models);
    }

    public function test_update_filters_empty_searchable_data(): void
    {
        $model1 = $this->createSearchableModel(1, ['title' => 'Valid']);
        $model2 = $this->createSearchableModel(2, []);
        $model3 = $this->createSearchableModel(3, ['title' => 'Also Valid']);

        $models = new Collection([$model1, $model2, $model3]);

        $this->client->shouldReceive('batchUpsert')
            ->once()
            ->with(Mockery::on(function ($documents) {
                $this->assertCount(2, $documents);
                $this->assertStringContainsString('_1', $documents[0]['id']);
                $this->assertStringContainsString('_3', $documents[1]['id']);
                return true;
            }));

        $this->engine->update($models);
    }

    public function test_update_with_array_values_in_searchable_data(): void
    {
        $model = $this->createSearchableModel(1, [
            'title' => 'Test',
            'tags' => ['tag1', 'tag2', 'tag3'],
            'description' => 'Description'
        ]);

        $models = new Collection([$model]);

        $this->client->shouldReceive('batchUpsert')
            ->once()
            ->with(Mockery::on(function ($documents) {
                $this->assertStringContainsString('tag1 tag2 tag3', $documents[0]['text']);
                return true;
            }));

        $this->engine->update($models);
    }

    public function test_delete_with_empty_collection(): void
    {
        $models = new Collection([]);

        $this->client->shouldNotReceive('deleteVectors');

        $this->engine->delete($models);

        $this->assertTrue(true);
    }

    public function test_delete_with_valid_models(): void
    {
        $model1 = $this->createSearchableModel(1, ['title' => 'Test']);
        $model2 = $this->createSearchableModel(2, ['title' => 'Test']);

        $models = new Collection([$model1, $model2]);

        $this->client->shouldReceive('deleteVectors')
            ->once()
            ->with(Mockery::on(function ($ids) {
                $this->assertCount(2, $ids);
                $this->assertStringContainsString('_1', $ids[0]);
                $this->assertStringContainsString('_2', $ids[1]);
                return true;
            }));

        $this->engine->delete($models);
    }

    public function test_search_with_default_limit(): void
    {
        $builder = $this->createBuilder('test query');

        $this->client->shouldReceive('search')
            ->once()
            ->with('test query', 10, ['model' => SearchableModel::class])
            ->andReturn([
                ['id' => 'ScoutVectorize_Tests_SearchableModel_1', 'score' => 0.95, 'metadata' => ['key' => 1]],
            ]);

        $results = $this->engine->search($builder);

        $this->assertIsArray($results);
        $this->assertArrayHasKey('results', $results);
        $this->assertArrayHasKey('total', $results);
        $this->assertEquals(1, $results['total']);
    }

    public function test_search_with_custom_limit(): void
    {
        $builder = $this->createBuilder('test query');
        $builder->limit = 20;

        $this->client->shouldReceive('search')
            ->once()
            ->with('test query', 20, ['model' => SearchableModel::class])
            ->andReturn([]);

        $results = $this->engine->search($builder);

        $this->assertIsArray($results);
    }

    public function test_search_with_where_clauses(): void
    {
        $builder = $this->createBuilder('test query');
        $builder->wheres = ['status' => 'published', 'category' => 'tech'];

        $this->client->shouldReceive('search')
            ->once()
            ->with('test query', 10, [
                'model' => SearchableModel::class,
                'status' => 'published',
                'category' => 'tech',
            ])
            ->andReturn([]);

        $results = $this->engine->search($builder);

        $this->assertIsArray($results);
    }

    public function test_search_with_callback(): void
    {
        $builder = $this->createBuilder('test query');
        $callbackCalled = false;

        $builder->callback = function ($client, $query, $options) use (&$callbackCalled) {
            $callbackCalled = true;
            $this->assertEquals('test query', $query);
            $this->assertEquals(10, $options['limit']);
            return ['custom' => 'results'];
        };

        $this->client->shouldNotReceive('search');

        $results = $this->engine->search($builder);

        $this->assertTrue($callbackCalled);
        $this->assertEquals(['custom' => 'results'], $results);
    }

    public function test_paginate(): void
    {
        $builder = $this->createBuilder('test query');

        $this->client->shouldReceive('search')
            ->once()
            ->with('test query', 30, ['model' => SearchableModel::class])
            ->andReturn([]);

        $results = $this->engine->paginate($builder, 10, 3);

        $this->assertIsArray($results);
    }

    public function test_paginate_with_max_limit_cap(): void
    {
        $builder = $this->createBuilder('test query');

        $this->client->shouldReceive('search')
            ->once()
            ->with('test query', 100, ['model' => SearchableModel::class])
            ->andReturn([]);

        $results = $this->engine->paginate($builder, 50, 3);

        $this->assertIsArray($results);
    }

    public function test_map_ids(): void
    {
        $results = [
            'results' => [
                ['id' => 'ScoutVectorize_Tests_SearchableModel_1', 'metadata' => ['key' => 1]],
                ['id' => 'ScoutVectorize_Tests_SearchableModel_2', 'metadata' => ['key' => 2]],
                ['id' => 'ScoutVectorize_Tests_SearchableModel_3', 'metadata' => ['key' => 3]],
            ],
        ];

        $ids = $this->engine->mapIds($results);

        $this->assertEquals([1, 2, 3], $ids->all());
    }

    public function test_map_ids_filters_invalid_entries(): void
    {
        $results = [
            'results' => [
                ['id' => 'ScoutVectorize_Tests_SearchableModel_1', 'metadata' => ['key' => 1]],
                ['id' => 'ScoutVectorize_Tests_SearchableModel_2', 'metadata' => []],
                ['id' => 'ScoutVectorize_Tests_SearchableModel_3', 'metadata' => ['key' => 3]],
            ],
        ];

        $ids = $this->engine->mapIds($results);

        $this->assertEquals([1, 3], $ids->all());
    }

    public function test_map(): void
    {
        $model = Mockery::mock(SearchableModel::class)->makePartial();
        $builder = $this->createBuilder('test');

        $results = [
            'results' => [
                ['id' => 'ScoutVectorize_Tests_SearchableModel_2', 'metadata' => ['key' => 2]],
                ['id' => 'ScoutVectorize_Tests_SearchableModel_1', 'metadata' => ['key' => 1]],
            ],
        ];

        $dbModel1 = $this->createSearchableModel(1, ['title' => 'First']);
        $dbModel2 = $this->createSearchableModel(2, ['title' => 'Second']);

        $model->shouldReceive('getScoutModelsByIds')
            ->once()
            ->with($builder, [2, 1])
            ->andReturn(new Collection([$dbModel1, $dbModel2]));

        $mapped = $this->engine->map($builder, $results, $model);

        $this->assertInstanceOf(Collection::class, $mapped);
        $this->assertCount(2, $mapped);
        $this->assertEquals(2, $mapped[0]->getScoutKey());
        $this->assertEquals(1, $mapped[1]->getScoutKey());
    }

    public function test_map_with_empty_results(): void
    {
        $model = Mockery::mock(SearchableModel::class);
        $builder = $this->createBuilder('test');

        $results = ['results' => []];

        $model->shouldReceive('newCollection')
            ->once()
            ->andReturn(new Collection());

        $mapped = $this->engine->map($builder, $results, $model);

        $this->assertInstanceOf(Collection::class, $mapped);
        $this->assertCount(0, $mapped);
    }

    public function test_get_total_count(): void
    {
        $results = ['total' => 42];
        $this->assertEquals(42, $this->engine->getTotalCount($results));
    }

    public function test_get_total_count_with_missing_key(): void
    {
        $results = ['results' => []];
        $this->assertEquals(0, $this->engine->getTotalCount($results));
    }

    public function test_flush(): void
    {
        $model = new SearchableModel();

        $this->client->shouldReceive('getEmbeddingModel')
            ->once()
            ->andReturn('@cf/baai/bge-base-en-v1.5');

        $this->client->shouldReceive('queryVectors')
            ->once()
            ->with(Mockery::type('array'), 100, ['model' => SearchableModel::class])
            ->andReturn([
                'result' => [
                    'matches' => [
                        ['id' => 'ScoutVectorize_Tests_SearchableModel_1'],
                        ['id' => 'ScoutVectorize_Tests_SearchableModel_2'],
                    ],
                ],
            ]);

        $this->client->shouldReceive('deleteVectors')
            ->once()
            ->with([
                'ScoutVectorize_Tests_SearchableModel_1',
                'ScoutVectorize_Tests_SearchableModel_2',
            ]);

        $this->client->shouldReceive('queryVectors')
            ->once()
            ->with(Mockery::type('array'), 100, ['model' => SearchableModel::class])
            ->andReturn([
                'result' => [
                    'matches' => [],
                ],
            ]);

        $this->engine->flush($model);

        $this->assertTrue(true);
    }

    public function test_flush_with_different_embedding_models(): void
    {
        $model = new SearchableModel();

        $models = [
            '@cf/baai/bge-small-en-v1.5' => 384,
            '@cf/baai/bge-base-en-v1.5' => 768,
            '@cf/baai/bge-large-en-v1.5' => 1024,
            'unknown-model' => 768,
        ];

        foreach ($models as $embeddingModel => $expectedDimensions) {
            $this->client->shouldReceive('getEmbeddingModel')
                ->once()
                ->andReturn($embeddingModel);

            $this->client->shouldReceive('queryVectors')
                ->once()
                ->with(
                    Mockery::on(function ($vector) use ($expectedDimensions) {
                        return count($vector) === $expectedDimensions;
                    }),
                    100,
                    ['model' => SearchableModel::class]
                )
                ->andReturn(['result' => ['matches' => []]]);

            $this->engine->flush($model);
        }

        $this->assertTrue(true);
    }

    public function test_create_index_is_noop(): void
    {
        $this->expectNotToPerformAssertions();
        $this->engine->createIndex('test-index');
    }

    public function test_delete_index_is_noop(): void
    {
        $this->expectNotToPerformAssertions();
        $this->engine->deleteIndex('test-index');
    }

    protected function createBuilder(string $query): Builder
    {
        return new Builder(new SearchableModel(), $query);
    }

    protected function createSearchableModel(int $id, array $data): SearchableModel
    {
        $model = Mockery::mock(SearchableModel::class)->makePartial();
        $model->shouldReceive('getScoutKey')->andReturn($id);
        $model->shouldReceive('toSearchableArray')->andReturn($data);
        return $model;
    }

    protected function createSearchableModelWithCustomText(int $id, string $text): SearchableModel
    {
        $model = Mockery::mock(SearchableModel::class)->makePartial();
        $model->shouldReceive('getScoutKey')->andReturn($id);
        $model->shouldReceive('toSearchableArray')->andReturn(['title' => 'Test']);
        $model->shouldReceive('toSearchableText')->andReturn($text);
        return $model;
    }
}

class SearchableModel extends Model
{
    public function getScoutKey(): int|string
    {
        return $this->id ?? 1;
    }

    public function toSearchableArray(): array
    {
        return [];
    }

    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }
}

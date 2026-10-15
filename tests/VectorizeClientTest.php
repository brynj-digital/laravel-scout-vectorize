<?php

namespace ScoutVectorize\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ScoutVectorize\VectorizeClient;

class VectorizeClientTest extends TestCase
{
    protected array $history = [];

    protected function clientReturning(array $responses): VectorizeClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new VectorizeClient(
            'test-account-id',
            'test-api-token',
            'test-index',
            '@cf/baai/bge-base-en-v1.5',
            new Client(['handler' => $stack])
        );
    }

    protected function embeddingResponse(): Response
    {
        return new Response(200, [], json_encode(['result' => ['data' => [[0.1, 0.2, 0.3]]]]));
    }

    protected function mutationResponse(): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'result' => ['mutationId' => 'abc']]));
    }

    public function test_batch_upsert_overwrites_through_the_upsert_endpoint(): void
    {
        $client = $this->clientReturning([
            $this->embeddingResponse(),
            $this->embeddingResponse(),
            $this->mutationResponse(),
        ]);

        $client->batchUpsert([
            ['id' => 'Product_1', 'text' => 'First', 'metadata' => ['model' => 'Product']],
            ['id' => 'Product_2', 'text' => 'Second', 'metadata' => ['model' => 'Product']],
        ]);

        $write = end($this->history)['request'];
        $this->assertStringEndsWith('/vectorize/v2/indexes/test-index/upsert', (string) $write->getUri());

        $body = json_decode((string) $write->getBody(), true);
        $this->assertSame(['Product_1', 'Product_2'], array_column($body['vectors'], 'id'));
    }

    public function test_upsert_document_overwrites_through_the_upsert_endpoint(): void
    {
        $client = $this->clientReturning([$this->embeddingResponse(), $this->mutationResponse()]);

        $client->upsertDocument('Product_1', 'First');

        $write = end($this->history)['request'];
        $this->assertStringEndsWith('/test-index/upsert', (string) $write->getUri());
    }

    public function test_insert_vectors_still_uses_the_insert_endpoint(): void
    {
        $client = $this->clientReturning([$this->mutationResponse()]);

        $client->insertVectors([['id' => 'Product_1', 'values' => [0.1]]]);

        $write = end($this->history)['request'];
        $this->assertStringEndsWith('/test-index/insert', (string) $write->getUri());
    }

    public function test_metadata_indexes_are_read_from_cloudflares_camel_case_response(): void
    {
        $result = [
            'success' => true,
            'result' => ['metadataIndexes' => [
                ['propertyName' => 'model', 'indexType' => 'String'],
                ['propertyName' => 'category_id', 'indexType' => 'Number'],
            ]],
        ];

        $this->assertSame([
            ['property_name' => 'model', 'type' => 'String'],
            ['property_name' => 'category_id', 'type' => 'Number'],
        ], VectorizeClient::metadataIndexesFrom($result));
    }

    public function test_metadata_indexes_accept_snake_case_keys(): void
    {
        $result = ['result' => ['metadata_indexes' => [['property_name' => 'model', 'type' => 'string']]]];

        $this->assertSame(
            [['property_name' => 'model', 'type' => 'string']],
            VectorizeClient::metadataIndexesFrom($result)
        );
    }

    public function test_metadata_indexes_are_empty_when_none_exist(): void
    {
        $this->assertSame([], VectorizeClient::metadataIndexesFrom(['result' => ['metadataIndexes' => []]]));
        $this->assertSame([], VectorizeClient::metadataIndexesFrom(['success' => true]));
    }
}

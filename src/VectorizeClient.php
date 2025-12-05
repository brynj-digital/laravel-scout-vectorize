<?php

namespace ScoutVectorize;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class VectorizeClient
{
    protected Client $client;
    protected string $baseUrl;
    protected string $aiUrl;

    public function __construct(
        protected string $accountId,
        protected string $apiToken,
        protected string $indexName,
        protected string $embeddingModel = '@cf/baai/bge-base-en-v1.5'
    ) {
        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $this->baseUrl = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/vectorize/v2/indexes";
        $this->aiUrl = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/run/{$this->embeddingModel}";
    }

    /**
     * Generate embeddings for the given text using Cloudflare Workers AI.
     */
    public function generateEmbedding(string $text): array
    {
        try {
            $response = $this->client->post($this->aiUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => [$text],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['result']['data'][0])) {
                return $result['result']['data'][0];
            }

            throw new \Exception('Invalid response format from Cloudflare Workers AI');
        } catch (\Exception $e) {
            Log::error('Vectorize: Error generating embedding', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
            ]);
            throw $e;
        }
    }

    /**
     * Insert vectors into Cloudflare Vectorize.
     */
    public function insertVectors(array $vectors): array
    {
        try {
            $response = $this->client->post("{$this->baseUrl}/{$this->indexName}/insert", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'vectors' => $vectors,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error inserting vectors', [
                'error' => $e->getMessage(),
                'count' => count($vectors),
            ]);
            throw $e;
        }
    }

    /**
     * Upsert a single document with embedding.
     */
    public function upsertDocument(string $id, string $text, array $metadata = []): array
    {
        $embedding = $this->generateEmbedding($text);

        $vector = [
            'id' => $id,
            'values' => $embedding,
            'metadata' => array_merge($metadata, ['text' => $text]),
        ];

        return $this->insertVectors([$vector]);
    }

    /**
     * Batch upsert multiple documents.
     */
    public function batchUpsert(array $documents): array
    {
        $vectors = [];

        foreach ($documents as $doc) {
            $embedding = $this->generateEmbedding($doc['text']);
            $vectors[] = [
                'id' => $doc['id'],
                'values' => $embedding,
                'metadata' => array_merge($doc['metadata'] ?? [], ['text' => $doc['text']]),
            ];
        }

        return $this->insertVectors($vectors);
    }

    /**
     * Query vectors from Cloudflare Vectorize.
     */
    public function queryVectors(array $queryVector, int $topK = 5, ?array $filter = null): array
    {
        try {
            $payload = [
                'vector' => $queryVector,
                'topK' => $topK,
                'returnMetadata' => 'indexed',
            ];

            if ($filter !== null && !empty($filter)) {
                $payload['filter'] = $filter;
            }

            $response = $this->client->post("{$this->baseUrl}/{$this->indexName}/query", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error querying vectors', [
                'error' => $e->getMessage(),
                'topK' => $topK,
            ]);
            throw $e;
        }
    }

    /**
     * Search for similar documents using text query.
     */
    public function search(string $query, int $topK = 5, ?array $filter = null): array
    {
        $queryEmbedding = $this->generateEmbedding($query);
        $result = $this->queryVectors($queryEmbedding, $topK, $filter);

        if (isset($result['result']['matches'])) {
            return array_map(function ($match) {
                return [
                    'id' => $match['id'],
                    'score' => $match['score'],
                    'metadata' => $match['metadata'] ?? [],
                ];
            }, $result['result']['matches']);
        }

        return [];
    }

    /**
     * Delete vectors from the index.
     */
    public function deleteVectors(array $ids): array
    {
        try {
            $response = $this->client->post("{$this->baseUrl}/{$this->indexName}/delete_by_ids", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'ids' => $ids,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error deleting vectors', [
                'error' => $e->getMessage(),
                'count' => count($ids),
            ]);
            throw $e;
        }
    }

    /**
     * Get index information.
     */
    public function getIndexInfo(): array
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/{$this->indexName}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error getting index info', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the embedding model being used.
     */
    public function getEmbeddingModel(): string
    {
        return $this->embeddingModel;
    }

    /**
     * Get the index name being used.
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }

    /**
     * Delete the entire Vectorize index.
     */
    public function deleteIndex(): array
    {
        try {
            $url = "{$this->baseUrl}/{$this->indexName}";

            $response = $this->client->delete($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error deleting index', [
                'error' => $e->getMessage(),
                'index_name' => $this->indexName,
            ]);
            throw $e;
        }
    }

    /**
     * Check if the index exists.
     */
    public function indexExists(): bool
    {
        try {
            $url = "{$this->baseUrl}/{$this->indexName}";

            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return isset($result['success']) && $result['success'];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false; // Index doesn't exist
            }
            throw $e;
        }
    }

    /**
     * Create a new Vectorize index.
     */
    public function createIndex(string $name, int $dimensions, string $metric): array
    {
        try {
            $payload = [
                'name' => $name,
                'description' => "Vector index for {$name} with dimensions {$dimensions}",
                'config' => [
                    'dimensions' => $dimensions,
                    'metric' => $metric,
                ],
            ];

            $response = $this->client->post($this->baseUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error creating index', [
                'error' => $e->getMessage(),
                'index_name' => $name,
                'dimensions' => $dimensions,
                'metric' => $metric,
            ]);
            throw $e;
        }
    }

    /**
     * Create a metadata index on a specific property.
     */
    public function createMetadataIndex(string $propertyName, string $type, ?string $indexName = null): array
    {
        try {
            $targetIndex = $indexName ?? $this->indexName;
            $url = "{$this->baseUrl}/{$targetIndex}/metadata_index/create";

            $payload = [
                'propertyName' => $propertyName,
                'indexType' => $type,
            ];

            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error creating metadata index', [
                'error' => $e->getMessage(),
                'property_name' => $propertyName,
                'type' => $type,
                'index_name' => $indexName ?? $this->indexName,
            ]);
            throw $e;
        }
    }

    /**
     * Delete a metadata index.
     */
    public function deleteMetadataIndex(string $propertyName, ?string $indexName = null): array
    {
        try {
            $targetIndex = $indexName ?? $this->indexName;
            $url = "{$this->baseUrl}/{$targetIndex}/metadata_index/delete";

            $payload = [
                'propertyName' => $propertyName,
            ];

            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error deleting metadata index', [
                'error' => $e->getMessage(),
                'property_name' => $propertyName,
                'index_name' => $indexName ?? $this->indexName,
            ]);
            throw $e;
        }
    }

    /**
     * List all metadata indexes for an index.
     */
    public function listMetadataIndexes(?string $indexName = null): array
    {
        try {
            $targetIndex = $indexName ?? $this->indexName;
            $url = "{$this->baseUrl}/{$targetIndex}/metadata_index/list";

            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (\Exception $e) {
            Log::error('Vectorize: Error listing metadata indexes', [
                'error' => $e->getMessage(),
                'index_name' => $indexName ?? $this->indexName,
            ]);
            throw $e;
        }
    }
}
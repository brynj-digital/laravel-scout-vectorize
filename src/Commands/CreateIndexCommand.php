<?php

namespace ScoutVectorize\Commands;

use Illuminate\Console\Command;
use ScoutVectorize\VectorizeClient;

class CreateIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vectorize:create-index
                            {name? : The name of the Vectorize index (optional, uses config value if not provided)}
                            {--dimensions=768 : The dimensions for the vectors}
                            {--metric=cosine : The distance metric (cosine, euclidean, dotproduct)}
                            {--embedding-model=@cf/baai/bge-base-en-v1.5 : The embedding model to use}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a Cloudflare Vectorize index';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        // If no name provided, use config value
        if (empty($name)) {
            $name = config('scout-vectorize.index');
            if (empty($name)) {
                $this->error('No index name provided and no default configured. Please set CLOUDFLARE_VECTORIZE_INDEX in your .env file or provide a name argument.');
                return Command::FAILURE;
            }
            $this->info("Using index name from config: {$name}");
        }

        $dimensions = (int) $this->option('dimensions');
        $metric = $this->option('metric');
        $embeddingModel = $this->option('embedding-model');

        // Validate metric
        $validMetrics = ['cosine', 'euclidean', 'dotproduct'];
        if (!in_array($metric, $validMetrics)) {
            $this->error("Invalid metric: {$metric}. Valid options: " . implode(', ', $validMetrics));
            return Command::FAILURE;
        }

        // Validate embedding model dimensions
        $expectedDimensions = $this->getExpectedDimensions($embeddingModel);
        if ($dimensions !== $expectedDimensions) {
            $this->warn("Warning: Dimensions {$dimensions} may not match embedding model {$embeddingModel} (expected {$expectedDimensions})");
        }

        $this->info("Creating Vectorize index '{$name}'...");
        $this->line("Dimensions: {$dimensions}");
        $this->line("Metric: {$metric}");
        $this->line("Embedding Model: {$embeddingModel}");

        if (!$this->confirm('Do you want to continue?')) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        try {
            $accountId = config('scout-vectorize.cloudflare.account_id');
            $apiToken = config('scout-vectorize.cloudflare.api_token');

            if (!$accountId || !$apiToken) {
                $this->error('Cloudflare account ID and API token must be configured in config/scout-vectorize.php');
                return Command::FAILURE;
            }

            $client = new VectorizeClient($accountId, $apiToken, $name);
            $result = $client->createIndex($name, $dimensions, $metric);

            if (isset($result['success']) && $result['success']) {
                $this->info("✅ Successfully created Vectorize index '{$name}'");

                $this->line('');
                $this->info('Next steps:');
                $this->line('1. Update your .env file with the index name:');
                $this->line("   CLOUDFLARE_VECTORIZE_INDEX={$name}");
                $this->line('2. Run migrations if needed');
                $this->line('3. Import your models: php artisan scout:import "App\\Models\\YourModel"');

                return Command::SUCCESS;
            } else {
                $this->error('Failed to create Vectorize index');
                if (isset($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->line("  - {$error}");
                    }
                }
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error creating Vectorize index: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Get expected dimensions for an embedding model.
     */
    protected function getExpectedDimensions(string $model): int
    {
        return match($model) {
            '@cf/baai/bge-small-en-v1.5' => 384,
            '@cf/baai/bge-base-en-v1.5' => 768,
            '@cf/baai/bge-large-en-v1.5' => 1024,
            default => 768,
        };
    }
}
<?php

namespace ScoutVectorize\Commands;

use Illuminate\Console\Command;
use ScoutVectorize\VectorizeClient;

class CreateMetadataIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vectorize:create-metadata-index
                            {property-name : The name of the metadata property to index}
                            {type : The property type (string, number, boolean)}
                            {--index-name= : The name of the Vectorize index (optional, uses config value if not provided)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a metadata index on a specific property for filtering';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $propertyName = $this->argument('property-name');
        $type = $this->argument('type');
        $indexName = $this->option('index-name');

        // Validate property name
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $propertyName)) {
            $this->error('Invalid property name. Only alphanumeric characters, underscores, and hyphens are allowed.');
            return Command::FAILURE;
        }

        // Validate type
        $validTypes = ['string', 'number', 'boolean'];
        if (!in_array(strtolower($type), $validTypes)) {
            $this->error("Invalid type: {$type}. Valid options: " . implode(', ', $validTypes));
            return Command::FAILURE;
        }
        $type = strtolower($type);

        // If no index name provided, use config value
        if (empty($indexName)) {
            $indexName = config('scout-vectorize.index');
            if (empty($indexName)) {
                $this->error('No index name provided and no default configured. Please set CLOUDFLARE_VECTORIZE_INDEX in your .env file or use --index-name option.');
                return Command::FAILURE;
            }
        }

        try {
            $accountId = config('scout-vectorize.cloudflare.account_id');
            $apiToken = config('scout-vectorize.cloudflare.api_token');

            if (!$accountId || !$apiToken) {
                $this->error('Cloudflare account ID and API token must be configured in config/scout-vectorize.php');
                return Command::FAILURE;
            }

            // First, check existing metadata indexes to enforce the limit and prevent duplicates
            $client = new VectorizeClient($accountId, $apiToken, $indexName);
            $this->info("Checking existing metadata indexes for '{$indexName}'...");

            $existingIndexesResult = $client->listMetadataIndexes($indexName);

            if (isset($existingIndexesResult['success']) && $existingIndexesResult['success']) {
                $existingIndexes = $existingIndexesResult['result']['metadata_indexes'] ?? [];

                // Check for 10-property limit
                if (count($existingIndexes) >= 10) {
                    $this->error('Cannot create metadata index: Maximum limit of 10 metadata indexes has been reached.');
                    $this->info('Use "php artisan vectorize:list-metadata-indexes" to see all existing metadata indexes.');
                    return Command::FAILURE;
                }

                // Check for duplicates
                foreach ($existingIndexes as $index) {
                    if (($index['property_name'] ?? '') === $propertyName) {
                        $this->error("Metadata index for property '{$propertyName}' already exists.");
                        $this->info('Use "php artisan vectorize:list-metadata-indexes" to see all existing metadata indexes.');
                        return Command::FAILURE;
                    }
                }
            }

            $this->info("Creating metadata index for property '{$propertyName}' with type '{$type}' on index '{$indexName}'...");

            if (!$this->confirm('Do you want to continue?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }

            $result = $client->createMetadataIndex($propertyName, $type, $indexName);

            if (isset($result['success']) && $result['success']) {
                $this->info("✅ Successfully created metadata index for property '{$propertyName}'");

                $this->line('');
                $this->info('Metadata index details:');
                $this->line("  Property: {$propertyName}");
                $this->line("  Type: {$type}");
                $this->line("  Index: {$indexName}");

                $this->line('');
                $this->info('Next steps:');
                $this->line('1. Update your model data to include this metadata property');
                $this->line('2. Re-index your data: php artisan scout:import "App\\Models\\YourModel"');
                $this->line('3. Use metadata filtering in your searches');

                return Command::SUCCESS;
            } else {
                $this->error('Failed to create metadata index');
                if (isset($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->line("  - {$error['message'] ?? $error}");
                    }
                }
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error creating metadata index: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
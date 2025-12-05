<?php

namespace ScoutVectorize\Commands;

use Illuminate\Console\Command;
use ScoutVectorize\VectorizeClient;

class DeleteMetadataIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vectorize:delete-metadata-index
                            {property-name : The name of the metadata property to delete}
                            {--index-name= : The name of the Vectorize index (optional, uses config value if not provided)}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a metadata index from a Vectorize index';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $propertyName = $this->argument('property-name');
        $indexName = $this->option('index-name');
        $force = $this->option('force');

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

            $client = new VectorizeClient($accountId, $apiToken, $indexName);

            // First, check if the metadata index exists
            $this->info("Checking for metadata index '{$propertyName}' in '{$indexName}'...");

            $existingIndexesResult = $client->listMetadataIndexes($indexName);

            if (isset($existingIndexesResult['success']) && $existingIndexesResult['success']) {
                $existingIndexes = isset($existingIndexesResult['result']['metadata_indexes'])
                    ? $existingIndexesResult['result']['metadata_indexes']
                    : [];
                $targetIndex = null;

                foreach ($existingIndexes as $index) {
                    if ((isset($index['property_name']) ? $index['property_name'] : '') === $propertyName) {
                        $targetIndex = $index;
                        break;
                    }
                }

                if (!$targetIndex) {
                    $this->error("Metadata index for property '{$propertyName}' does not exist.");
                    $this->info('Use "php artisan vectorize:list-metadata-indexes" to see all existing metadata indexes.');
                    return Command::FAILURE;
                }

                $this->info("Found metadata index:");
                $this->line("  Property: " . (isset($targetIndex['property_name']) ? $targetIndex['property_name'] : 'Unknown'));
                $this->line("  Type: " . (isset($targetIndex['type']) ? $targetIndex['type'] : 'Unknown'));
                $this->line("  Created: " . (isset($targetIndex['created_at']) ? $targetIndex['created_at'] : 'Unknown'));
            } else {
                $this->error('Failed to check existing metadata indexes');
                return Command::FAILURE;
            }

            // Warning and confirmation
            $this->line('');
            $this->warn('⚠️  WARNING: This will permanently delete the metadata index!');
            $this->warn('   After deletion, you will not be able to filter on this metadata property.');
            $this->warn('   This operation cannot be undone.');

            if (!$force) {
                $this->line('');
                if (!$this->confirm('Are you sure you want to delete this metadata index?')) {
                    $this->info('Operation cancelled.');
                    return Command::SUCCESS;
                }

                // Double confirmation for safety
                $this->warn('Please type the property name to confirm deletion:');
                $confirmation = $this->ask("Property name to confirm: '{$propertyName}'");

                if ($confirmation !== $propertyName) {
                    $this->error('Confirmation failed. Property name does not match.');
                    $this->info('Operation cancelled for safety.');
                    return Command::FAILURE;
                }
            } else {
                $this->line('');
                $this->info('--force option detected, skipping confirmation prompts.');
            }

            $this->line('');
            $this->info("Deleting metadata index for property '{$propertyName}'...");

            $result = $client->deleteMetadataIndex($propertyName, $indexName);

            if (isset($result['success']) && $result['success']) {
                $this->info("✅ Successfully deleted metadata index for property '{$propertyName}'");

                $this->line('');
                $this->info('Metadata index has been removed. Any existing filters on this property will no longer work.');

                return Command::SUCCESS;
            } else {
                $this->error('Failed to delete metadata index');
                if (isset($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $errorMessage = is_array($error) ? ($error['message'] ?? 'Unknown error') : $error;
                        $this->line("  - {$errorMessage}");
                    }
                }
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error deleting metadata index: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
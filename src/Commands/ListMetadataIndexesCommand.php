<?php

namespace ScoutVectorize\Commands;

use Illuminate\Console\Command;
use ScoutVectorize\VectorizeClient;

class ListMetadataIndexesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vectorize:list-metadata-indexes
                            {--index-name= : The name of the Vectorize index (optional, uses config value if not provided)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all metadata indexes for a Vectorize index';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $indexName = $this->option('index-name');

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

            $this->info("Listing metadata indexes for '{$indexName}'...");

            $client = new VectorizeClient($accountId, $apiToken, $indexName);
            $result = $client->listMetadataIndexes($indexName);

            if (isset($result['success']) && $result['success']) {
                $metadataIndexes = isset($result['result']['metadata_indexes'])
                    ? $result['result']['metadata_indexes']
                    : [];

                if (empty($metadataIndexes)) {
                    $this->info("✅ No metadata indexes found for '{$indexName}'");
                    $this->line('');
                    $this->info('To create a metadata index, use:');
                    $this->line('  php artisan vectorize:create-metadata-index {property-name} {type}');
                    return Command::SUCCESS;
                }

                $this->info("✅ Found " . count($metadataIndexes) . " metadata index(es):");

                $tableData = [];
                foreach ($metadataIndexes as $index) {
                    $tableData[] = [
                        'property' => isset($index['property_name']) ? $index['property_name'] : 'Unknown',
                        'type' => isset($index['type']) ? $index['type'] : 'Unknown',
                        'created' => isset($index['created_at'])
                            ? date('Y-m-d H:i:s', strtotime($index['created_at']))
                            : 'Unknown',
                    ];
                }

                $this->table(
                    ['Property', 'Type', 'Created At'],
                    $tableData
                );

                $this->line('');
                $this->info('Total metadata indexes: ' . count($metadataIndexes) . '/10');

                if (count($metadataIndexes) >= 10) {
                    $this->warn('⚠️  You have reached the maximum limit of 10 metadata indexes.');
                }

                return Command::SUCCESS;
            } else {
                $this->error('Failed to list metadata indexes');
                if (isset($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $errorMessage = is_array($error) ? ($error['message'] ?? 'Unknown error') : $error;
                        $this->line("  - {$errorMessage}");
                    }
                }
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error listing metadata indexes: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
<?php

namespace ScoutVectorize\Commands;

use Illuminate\Console\Command;
use ScoutVectorize\VectorizeClient;

class DropIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vectorize:drop-index
                            {name? : The name of the Vectorize index to drop (optional, uses config value if not provided)}
                            {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drop (delete) a Cloudflare Vectorize index';

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

        $force = $this->option('force');

        $this->warn("⚠️  DANGER: This will permanently delete the Vectorize index '{$name}' and all its vectors!");
        $this->line('This action cannot be undone.');

        if (!$force) {
            if (!$this->confirm('Are you absolutely sure you want to continue?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }

            // Double confirmation
            $confirmName = $this->ask("To confirm, please type the index name '{$name}'");
            if ($confirmName !== $name) {
                $this->error('Index name confirmation does not match. Operation cancelled.');
                return Command::FAILURE;
            }
        }

        try {
            // Check if the index exists first
            $accountId = config('scout-vectorize.cloudflare.account_id');
            $apiToken = config('scout-vectorize.cloudflare.api_token');

            if (!$accountId || !$apiToken) {
                $this->error('Cloudflare account ID and API token must be configured in config/scout-vectorize.php');
                return Command::FAILURE;
            }

            $this->info("Checking if index '{$name}' exists...");
            $indexExists = $this->checkIndexExists($accountId, $apiToken, $name);

            if (!$indexExists) {
                $this->error("Vectorize index '{$name}' does not exist.");
                return Command::FAILURE;
            }

            $this->info("Deleting Vectorize index '{$name}'...");

            $result = $this->deleteVectorizeIndex($accountId, $apiToken, $name);

            if (isset($result['success']) && $result['success']) {
                $this->info("✅ Successfully deleted Vectorize index '{$name}'");

                $this->line('');
                $this->info('Next steps:');
                $this->line('1. Update your .env file to remove or change the index name:');
                $this->line("   # CLOUDFLARE_VECTORIZE_INDEX={$name}");
                $this->line('2. If needed, create a new index: php artisan vectorize:create-index new-index-name');
                $this->line('3. Re-import your models: php artisan scout:import "App\\Models\\YourModel"');

                return Command::SUCCESS;
            } else {
                $this->error('Failed to delete Vectorize index');
                if (isset($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->line("  - {$error}");
                    }
                }
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error deleting Vectorize index: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Check if a Vectorize index exists via Cloudflare API.
     */
    protected function checkIndexExists(string $accountId, string $apiToken, string $name): bool
    {
        $client = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/vectorize/indexes/{$name}";

        try {
            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => "Bearer {$apiToken}",
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
     * Delete a Vectorize index via Cloudflare API.
     */
    protected function deleteVectorizeIndex(string $accountId, string $apiToken, string $name): array
    {
        $client = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/vectorize/indexes/{$name}";

        $response = $client->delete($url, [
            'headers' => [
                'Authorization' => "Bearer {$apiToken}",
                'Content-Type' => 'application/json',
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }
}
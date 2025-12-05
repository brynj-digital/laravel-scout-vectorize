<?php

namespace ScoutVectorize;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use ScoutVectorize\Engines\VectorizeEngine;
use ScoutVectorize\Commands\CreateIndexCommand;
use ScoutVectorize\Commands\DropIndexCommand;

class VectorizeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/scout-vectorize.php',
            'scout-vectorize'
        );

        $this->app->singleton(VectorizeClient::class, function ($app) {
            return new VectorizeClient(
                accountId: config('scout-vectorize.cloudflare.account_id'),
                apiToken: config('scout-vectorize.cloudflare.api_token'),
                indexName: config('scout-vectorize.index'),
                embeddingModel: config('scout-vectorize.embedding_model')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/scout-vectorize.php' => config_path('scout-vectorize.php'),
        ], 'scout-vectorize-config');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateIndexCommand::class,
                DropIndexCommand::class,
            ]);
        }

        // Extend Scout with Vectorize engine
        resolve(EngineManager::class)->extend('vectorize', function () {
            return new VectorizeEngine($this->app->make(VectorizeClient::class));
        });
    }
}

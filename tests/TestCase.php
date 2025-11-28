<?php

namespace ScoutVectorize\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ScoutVectorize\VectorizeServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            VectorizeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('scout.driver', 'vectorize');
        config()->set('vectorize.account_id', 'test-account-id');
        config()->set('vectorize.api_token', 'test-api-token');
        config()->set('vectorize.index_name', 'test-index');
        config()->set('vectorize.embedding_model', '@cf/baai/bge-base-en-v1.5');
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Account Configuration
    |--------------------------------------------------------------------------
    |
    | Your Cloudflare account credentials. You can find these in your
    | Cloudflare dashboard under API Tokens.
    |
    */

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vectorize Index Name
    |--------------------------------------------------------------------------
    |
    | The name of your Cloudflare Vectorize index. You must create this
    | index in your Cloudflare dashboard before using this package.
    |
    */

    'index' => env('CLOUDFLARE_VECTORIZE_INDEX', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Embedding Model
    |--------------------------------------------------------------------------
    |
    | The Cloudflare Workers AI model to use for generating embeddings.
    | Default: @cf/baai/bge-base-en-v1.5 (768 dimensions)
    |
    | Other options:
    | - @cf/baai/bge-small-en-v1.5 (384 dimensions)
    | - @cf/baai/bge-large-en-v1.5 (1024 dimensions)
    |
    | Note: Your Vectorize index must be configured with the matching
    | dimension size for your chosen model.
    |
    */

    'embedding_model' => env('CLOUDFLARE_EMBEDDING_MODEL', '@cf/baai/bge-base-en-v1.5'),

];

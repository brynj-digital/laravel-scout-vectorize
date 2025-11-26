# Laravel Scout Vectorize Driver

A Laravel Scout driver for [Cloudflare Vectorize](https://developers.cloudflare.com/vectorize/), enabling semantic search using vector embeddings in your Laravel applications.

## Features

- **Semantic Search**: Search by meaning, not just keywords
- **Native Scout Integration**: Works seamlessly with Laravel Scout
- **Cloudflare Workers AI**: Automatic embedding generation using Cloudflare's AI models
- **Easy Setup**: Simple configuration and migration from other Scout drivers
- **Batch Operations**: Efficient bulk indexing and deletion
- **Multiple Models**: Support for searching across different Eloquent models

## Requirements

- PHP 8.1 or higher
- Laravel 10.x or 11.x
- Laravel Scout 10.x
- A [Cloudflare account](https://dash.cloudflare.com/) with Vectorize enabled
- Cloudflare API token with Vectorize permissions

## Installation

Install the package via Composer:

```bash
composer require your-vendor/laravel-scout-vectorize
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=scout-vectorize-config
```

## Configuration

### 1. Create a Vectorize Index

Create a Vectorize index in your Cloudflare dashboard or via the API:

```bash
# Using Wrangler CLI
wrangler vectorize create my-index --dimensions=768 --metric=cosine
```

The dimensions must match your chosen embedding model:
- `@cf/baai/bge-small-en-v1.5`: 384 dimensions
- `@cf/baai/bge-base-en-v1.5`: 768 dimensions (default)
- `@cf/baai/bge-large-en-v1.5`: 1024 dimensions

### 2. Environment Variables

Add the following to your `.env` file:

```env
SCOUT_DRIVER=vectorize

CLOUDFLARE_ACCOUNT_ID=your_account_id
CLOUDFLARE_API_TOKEN=your_api_token
VECTORIZE_INDEX=my-index
VECTORIZE_EMBEDDING_MODEL=@cf/baai/bge-base-en-v1.5
```

### 3. Scout Configuration

Ensure Scout is configured in `config/scout.php`:

```php
'driver' => env('SCOUT_DRIVER', 'vectorize'),
```

## Usage

### Basic Model Setup

Add the `Searchable` trait to your model:

```php
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand,
            'category' => $this->category,
        ];
    }
}
```

### Custom Text Conversion (Optional)

For more control over how your model is converted to searchable text, implement a `toSearchableText()` method:

```php
class Product extends Model
{
    use Searchable;

    /**
     * Convert the model to searchable text.
     * This method takes precedence over toSearchableArray().
     */
    public function toSearchableText(): string
    {
        return implode('. ', [
            $this->name,
            $this->brand,
            $this->description,
            implode(' ', $this->tags ?? []),
        ]);
    }

    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
```

### Searching

```php
// Simple search
$products = Product::search('wireless headphones')->get();

// Limit results
$products = Product::search('laptop')->take(20)->get();

// Paginate results
$products = Product::search('smartphone')->paginate(15);

// Get raw search results with scores
$results = Product::search('tablet')->raw();
```

### Indexing

```php
// Index a single model
$product = Product::find(1);
$product->searchable();

// Index all models
Product::makeAllSearchable();

// Using artisan command
php artisan scout:vectorize-import "App\Models\Product"
```

### Removing from Index

```php
// Remove a single model
$product->unsearchable();

// Remove all models of a type
Product::removeAllFromSearch();

// Using artisan command (flush by model)
php artisan scout:vectorize-flush "App\Models\Product"

// Clear entire index
php artisan scout:vectorize-clear
```

### Model Observers

Scout automatically syncs your models when you create, update, or delete them:

```php
// Automatically indexed
$product = Product::create([
    'name' => 'Wireless Headphones',
    'description' => 'High-quality Bluetooth headphones',
]);

// Automatically re-indexed
$product->update(['name' => 'Premium Wireless Headphones']);

// Automatically removed from index
$product->delete();
```

## Advanced Usage

### Custom Search Callbacks

For advanced search requirements, use a callback:

```php
$results = Product::search('laptop', function ($client, $query, $options) {
    // $client is the VectorizeClient instance
    return $client->search($query, 50, [
        'model' => Product::class,
        'in_stock' => true,
    ]);
})->get();
```

### Querying the Client Directly

```php
use ScoutVectorize\VectorizeClient;

$client = app(VectorizeClient::class);

// Get index information
$info = $client->getIndexInfo();

// Manual search
$results = $client->search('wireless headphones', topK: 10);

// Generate embedding for text
$embedding = $client->generateEmbedding('sample text');
```

## Available Commands

```bash
# Import all records of a model
php artisan scout:vectorize-import "App\Models\Product"

# Flush all vectors for a specific model
php artisan scout:vectorize-flush "App\Models\Product"

# Clear all vectors from the index
php artisan scout:vectorize-clear

# Clear with force (skip confirmation)
php artisan scout:vectorize-clear --force
```

## How It Works

1. **Indexing**: When a model is indexed, the driver:
   - Calls `toSearchableText()` or flattens `toSearchableArray()` to text
   - Generates an embedding using Cloudflare Workers AI
   - Stores the vector in Cloudflare Vectorize with metadata

2. **Searching**: When you search:
   - Your query text is converted to an embedding
   - Vectorize finds the most similar vectors
   - Results are mapped back to your Eloquent models
   - Models are fetched from your database and returned

3. **Vector IDs**: The driver prefixes vector IDs with the model class name to support multiple model types in one index (e.g., `App_Models_Product_123`)

## Limitations

- **No traditional filters**: Vector search doesn't support WHERE clauses like traditional search engines. Apply filters in PHP after retrieval or use metadata filtering (which may not work reliably in all cases)
- **No offset-based pagination**: Vector search returns top-K results. Use cursor-based pagination or retrieve more results upfront
- **Metadata filtering**: Cloudflare Vectorize metadata filtering may not be reliable for all use cases. Consider filtering in your application layer
- **Eventual consistency**: There may be a slight delay between indexing/deletion and seeing changes in search results

## Configuration Reference

```php
// config/scout-vectorize.php

return [
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    'index' => env('VECTORIZE_INDEX', 'default'),

    'embedding_model' => env('VECTORIZE_EMBEDDING_MODEL', '@cf/baai/bge-base-en-v1.5'),
];
```

## Troubleshooting

### Search returns no results

- Ensure your models are indexed: `php artisan scout:vectorize-import "App\Models\Product"`
- Check your Vectorize index has vectors: Use the Cloudflare dashboard or API
- Verify your API credentials are correct

### Indexing is slow

- Vector embedding generation requires API calls to Cloudflare
- Use `makeAllSearchable()` for batch operations (more efficient than individual saves)
- Consider queueing Scout updates in production

### Errors about dimensions

- Ensure your Vectorize index dimensions match your embedding model
- Default model `@cf/baai/bge-base-en-v1.5` uses 768 dimensions

## Testing

```bash
composer test
```

## Contributing

Contributions are welcome! Please submit pull requests or open issues on GitHub.

## License

This package is open-source software licensed under the [MIT license](LICENSE.md).

## Credits

- Built for use with [Cloudflare Vectorize](https://developers.cloudflare.com/vectorize/)
- Integrates with [Laravel Scout](https://laravel.com/docs/scout)

## Support

For issues, questions, or contributions, please visit the [GitHub repository](https://github.com/your-vendor/laravel-scout-vectorize).

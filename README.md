# Cloudflare Vectorize Driver for Laravel Scout

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
- Laravel 10.x, 11.x, or 12.x
- Laravel Scout 10.x or 11.x
- A [Cloudflare account](https://dash.cloudflare.com/) with Vectorize enabled
- Cloudflare API token with Vectorize permissions

## Installation

Install the package via Composer:

```bash
composer require brynj-digital/laravel-scout-vectorize
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
npx wrangler vectorize create my-index --dimensions=768 --metric=cosine
```

The dimensions must match your chosen embedding model:
- `@cf/baai/bge-small-en-v1.5`: 384 dimensions
- `@cf/baai/bge-base-en-v1.5`: 768 dimensions (default)
- `@cf/baai/bge-large-en-v1.5`: 1024 dimensions

### Create Metadata Indexes

Create metadata indexes to enable efficient filtering. The `model` and `key` indexes are **required** for the driver to function correctly:

```bash
# Required: Create metadata index for model filtering
npx wrangler vectorize create-metadata-index my-index --property-name=model --type=string

# Required: Create metadata index for key filtering
npx wrangler vectorize create-metadata-index my-index --property-name=key --type=number
```

#### Optional: Additional Metadata Indexes for `where()` Clauses

You can create additional metadata indexes for any custom fields you want to filter on using Scout's `where()` method:

```bash
# Example: Create index for filtering by status
npx wrangler vectorize create-metadata-index my-index --property-name=status --type=string

# Example: Create index for filtering by category_id
npx wrangler vectorize create-metadata-index my-index --property-name=category_id --type=number

# Example: Create index for boolean fields
npx wrangler vectorize create-metadata-index my-index --property-name=in_stock --type=boolean
```

To use these filters, include the fields in your model's `toSearchableArray()`:

```php
public function toSearchableArray(): array
{
    return [
        // These fields are used for both embeddings AND metadata
        'name' => $this->name,
        'description' => $this->description,

        // These fields are stored as metadata for filtering
        // (included in embeddings but primarily for where() clauses)
        'status' => $this->status,
        'category_id' => $this->category_id,
        'in_stock' => $this->in_stock,
    ];
}
```

Then use `where()` in your searches:

```php
Product::search('laptop')
    ->where('status', 'active')
    ->where('in_stock', true)
    ->get();
```

**How it works**: All fields from `toSearchableArray()` are:
1. **Converted to text** and used to generate the embedding vector for semantic search
2. **Stored as metadata** for filtering with `where()` clauses

This means you can search semantically while also applying exact-match filters.

### 2. Create API Token

You'll need a Cloudflare API token with Vectorize permissions to allow Laravel to interact with your Vectorize index.

#### Create the token in Cloudflare Dashboard:

1. Log in to your [Cloudflare Dashboard](https://dash.cloudflare.com/)
2. Navigate to **My Profile** (click your user icon in the top right)
3. Select **API Tokens** from the left sidebar
4. Click **Create Token**
5. Choose **Create Custom Token**
6. Configure your token:
   - **Token name**: Give it a descriptive name (e.g., "Laravel Scout Vectorize")
   - **Permissions**: Add the following two permissions:
     - Account → **Vectorize** → **Read**
     - Account → **Vectorize** → **Write**
   - **Account Resources**: Select your specific account (or "All accounts" if needed)
   - **TTL**: Set an expiration date or leave as default
7. Click **Continue to summary**
8. Review the permissions and click **Create Token**
9. **Important**: Copy the token immediately - it will only be shown once
10. Store the token securely (you'll add it to your `.env` file in the next step)

#### Token Permissions Summary

Your token must have these permissions:
- ✅ **Vectorize Read** - Allows reading from your Vectorize indexes
- ✅ **Vectorize Write** - Allows creating, updating, and deleting vectors

**Security Note**: Avoid using tokens with broader permissions (like "Account Settings: Read" or "Workers: Edit") unless absolutely necessary.

### 3. Environment Variables

Add the following to your `.env` file:

```env
SCOUT_DRIVER=vectorize

CLOUDFLARE_ACCOUNT_ID=your_account_id
CLOUDFLARE_API_TOKEN=your_api_token
CLOUDFLARE_VECTORIZE_INDEX=my-index
CLOUDFLARE_EMBEDDING_MODEL=@cf/baai/bge-base-en-v1.5
```

### 4. Scout Configuration

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
php artisan scout:import "App\Models\Product"
```

### Removing from Index

```php
// Remove a single model
$product->unsearchable();

// Remove all models of a type
Product::removeAllFromSearch();

// Using artisan command
php artisan scout:flush "App\Models\Product"
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

## Practical Examples

### E-commerce Product Search

```php
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'brand' => $this->brand,
            'description' => $this->description,
            'category' => $this->category->name,
            'features' => implode(', ', $this->features ?? []),
            // Metadata for filtering
            'status' => $this->status,
            'price' => $this->price,
            'in_stock' => $this->in_stock,
        ];
    }
}

// Search with semantic understanding
$results = Product::search('laptop for programming and gaming')
    ->where('in_stock', true)
    ->where('status', 'published')
    ->take(20)
    ->get();
```

### Blog Article Search

```php
class Article extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'content' => strip_tags($this->content),
            'author' => $this->author->name,
            'tags' => $this->tags->pluck('name')->join(', '),
            // Metadata
            'category_id' => $this->category_id,
            'published_at' => $this->published_at,
            'status' => $this->status,
        ];
    }

    public function toSearchableText(): string
    {
        // Custom text format for better embeddings
        return sprintf(
            '%s. %s. Written by %s. Tags: %s',
            $this->title,
            $this->excerpt,
            $this->author->name,
            $this->tags->pluck('name')->join(', ')
        );
    }
}

// Find related articles
$related = Article::search('introduction to machine learning')
    ->where('status', 'published')
    ->where('category_id', $article->category_id)
    ->take(5)
    ->get();
```

### Documentation Search

```php
class Documentation extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'content' => strip_tags($this->content),
            'section' => $this->section,
            'version' => $this->version,
        ];
    }
}

// Semantic search in docs
$docs = Documentation::search('how to handle file uploads')
    ->where('version', config('app.docs_version'))
    ->get();
```

### Customer Support Ticket Search

```php
class SupportTicket extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'subject' => $this->subject,
            'description' => $this->description,
            'customer_name' => $this->customer->name,
            'category' => $this->category,
            // Metadata
            'status' => $this->status,
            'priority' => $this->priority,
        ];
    }
}

// Find similar support tickets
$similar = SupportTicket::search($newTicket->description)
    ->where('status', 'resolved')
    ->take(10)
    ->get();
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

### Using Where Clauses for Filtering

You can combine semantic search with metadata filtering:

```php
// Search with filters
$products = Product::search('gaming laptop')
    ->where('status', 'published')
    ->where('price', '< 2000')
    ->get();

// Multiple filters
$articles = Article::search('machine learning')
    ->where('category', 'technology')
    ->where('published_at', '>', now()->subDays(30))
    ->get();
```

**Note**: Filters are applied to metadata stored in Vectorize. Make sure the fields you filter on are:
1. Included in your model's `toSearchableArray()`
2. Have corresponding metadata indexes created in Vectorize (see Configuration section)

### Querying the Client Directly

```php
use ScoutVectorize\VectorizeClient;

$client = app(VectorizeClient::class);

// Get index information
$info = $client->getIndexInfo();

// Manual search with filters
$results = $client->search(
    query: 'wireless headphones',
    topK: 10,
    filter: ['status' => 'active']
);

// Generate embedding for text
$embedding = $client->generateEmbedding('sample text');

// Batch upsert documents
$client->batchUpsert([
    [
        'id' => 'doc_1',
        'text' => 'Document content',
        'metadata' => ['category' => 'tech'],
    ],
    // ... more documents
]);

// Delete vectors by IDs
$client->deleteVectors(['doc_1', 'doc_2']);
```

### Queueing Scout Operations

For better performance in production, queue your Scout operations:

```php
// In config/scout.php
'queue' => true,

// Specify queue connection and queue name
'queue' => [
    'connection' => env('SCOUT_QUEUE_CONNECTION', 'redis'),
    'queue' => env('SCOUT_QUEUE_NAME', 'default'),
],
```

This will queue all indexing operations, preventing API rate limits and improving response times.

## Available Commands

This package uses the standard Laravel Scout commands:

```bash
# Import all records of a model
php artisan scout:import "App\Models\Product"

# Flush all vectors for a specific model
php artisan scout:flush "App\Models\Product"
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

    'index' => env('CLOUDFLARE_VECTORIZE_INDEX', 'default'),

    'embedding_model' => env('CLOUDFLARE_EMBEDDING_MODEL', '@cf/baai/bge-base-en-v1.5'),
];
```

## Troubleshooting

### Search returns no results

- **Ensure your models are indexed**: Run `php artisan scout:import "App\Models\Product"`
- **Check your Vectorize index has vectors**: Use the Cloudflare dashboard or API to verify
- **Verify your API credentials**: Double-check `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN` in your `.env`
- **Check model filters**: The driver automatically filters by model class. Ensure you're searching the right model

### Indexing is slow

- **API overhead**: Vector embedding generation requires API calls to Cloudflare Workers AI
- **Use batch operations**: Use `makeAllSearchable()` for bulk indexing (more efficient than individual saves)
- **Enable queuing**: Set `'queue' => true` in `config/scout.php` to process indexing in the background
- **Rate limits**: Cloudflare has rate limits on API calls. Implement throttling or use queues

### Errors about dimensions

- **Dimension mismatch**: Ensure your Vectorize index dimensions match your embedding model
  - `@cf/baai/bge-small-en-v1.5`: 384 dimensions
  - `@cf/baai/bge-base-en-v1.5`: 768 dimensions (default)
  - `@cf/baai/bge-large-en-v1.5`: 1024 dimensions
- **Recreate index**: If you changed embedding models, you'll need to create a new index with the correct dimensions

### Authentication errors

- **Invalid API token**: Verify your `CLOUDFLARE_API_TOKEN` has Vectorize permissions
- **Incorrect account ID**: Double-check your `CLOUDFLARE_ACCOUNT_ID`
- **Token permissions**: Ensure your API token has `Vectorize` read and write permissions

### Metadata filtering not working

- **Create metadata indexes**: Metadata filters require indexes. Run:
  ```bash
  npx wrangler vectorize create-metadata-index my-index --property-name=your_field --type=string
  ```
- **Check field types**: Ensure the metadata index type matches your data (string, number, boolean)
- **Include in searchable array**: The field must be in your model's `toSearchableArray()`

### Performance optimization

- **Limit result size**: Use `take()` or `paginate()` to limit results
- **Cache frequent queries**: Cache search results for common queries
- **Use metadata filters wisely**: Filters can reduce the search space and improve performance
- **Optimize text conversion**: Keep `toSearchableText()` concise to reduce embedding generation time

## Architecture

### Package Structure

```
src/
├── Engines/
│   └── VectorizeEngine.php    # Scout engine implementation
├── VectorizeClient.php         # Cloudflare API client
└── VectorizeServiceProvider.php # Service provider

tests/
├── TestCase.php                # Base test case
└── VectorizeEngineTest.php     # Engine tests
```

### How Embeddings Work

This package uses Cloudflare Workers AI to generate embeddings:

1. **Text Preparation**: Your model data is converted to text using `toSearchableText()` or by flattening `toSearchableArray()`
2. **Embedding Generation**: The text is sent to Cloudflare Workers AI which returns a vector (array of floats)
3. **Vector Storage**: The vector is stored in Vectorize along with metadata (model class, key, and searchable data)
4. **Semantic Search**: When you search, your query is also converted to a vector and compared against stored vectors using cosine similarity

### Supported Embedding Models

| Model | Dimensions | Best For |
|-------|-----------|----------|
| `@cf/baai/bge-small-en-v1.5` | 384 | Faster processing, lower memory |
| `@cf/baai/bge-base-en-v1.5` | 768 | Balanced (default) |
| `@cf/baai/bge-large-en-v1.5` | 1024 | Higher accuracy, slower |

### Vector ID Format

Vectors are stored with IDs in the format: `{ModelClass}_{ModelKey}`

Example: `App_Models_Product_123`

This allows multiple model types to coexist in the same Vectorize index.

## Testing

The package includes comprehensive tests covering all engine functionality:

```bash
# Run all tests
composer test

# Run with coverage
vendor/bin/phpunit --coverage-html coverage

# Run specific test
vendor/bin/phpunit tests/VectorizeEngineTest.php
```

### Test Coverage

The test suite includes 23+ tests covering:

- **Update operations**: Empty collections, valid models, custom text conversion, array values
- **Delete operations**: Empty collections, model deletion
- **Search operations**: Default limits, custom limits, filters, callbacks, pagination
- **Result mapping**: ID extraction, model mapping, ordering
- **Flush operations**: Batch deletion, different embedding models
- **Index operations**: Create/delete (no-op for Vectorize)

### Running Tests

Tests use Orchestra Testbench to simulate a Laravel environment and Mockery to mock the VectorizeClient, ensuring tests run without making actual API calls.

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Run tests with detailed output
./vendor/bin/phpunit --testdox
```

## Best Practices

### Optimizing Search Quality

1. **Use descriptive text**: Include context in your searchable content
   ```php
   public function toSearchableText(): string
   {
       // Good: Includes context
       return "Product: {$this->name}. Brand: {$this->brand}. {$this->description}";

       // Not ideal: Just raw values
       return "{$this->name} {$this->brand} {$this->description}";
   }
   ```

2. **Avoid overly long text**: Embeddings work best with focused, relevant content
   ```php
   public function toSearchableArray(): array
   {
       return [
           'title' => $this->title,
           'excerpt' => Str::limit($this->content, 500), // Limit long content
           'category' => $this->category->name,
       ];
   }
   ```

3. **Include relevant metadata**: Add fields you'll filter on
   ```php
   public function toSearchableArray(): array
   {
       return [
           'content' => $this->content,
           // Always include filterable fields
           'status' => $this->status,
           'created_at' => $this->created_at,
           'author_id' => $this->author_id,
       ];
   }
   ```

### Performance Tips

1. **Enable queueing for production**: Prevent blocking requests
   ```php
   // config/scout.php
   'queue' => env('SCOUT_QUEUE', true),
   ```

2. **Use batch operations**: Import in bulk rather than one-by-one
   ```bash
   # Efficient
   php artisan scout:import "App\Models\Product"

   # Less efficient
   Product::all()->each->searchable();
   ```

3. **Limit search results**: Only fetch what you need
   ```php
   // Good: Limited results
   Product::search('laptop')->take(20)->get();

   // Avoid: Fetching everything
   Product::search('laptop')->get();
   ```

4. **Cache frequent queries**: Use Laravel's cache for popular searches
   ```php
   $results = Cache::remember(
       "search:{$query}",
       now()->addMinutes(10),
       fn() => Product::search($query)->take(20)->get()
   );
   ```

### Security Considerations

1. **Sanitize user input**: Always validate and sanitize search queries
   ```php
   $query = request()->validate(['q' => 'required|string|max:255'])['q'];
   $results = Product::search($query)->get();
   ```

2. **Protect API credentials**: Never commit API tokens to version control
   ```env
   # .env (not in version control)
   CLOUDFLARE_API_TOKEN=your_secret_token
   ```

3. **Use scopes for access control**: Filter by user permissions
   ```php
   $results = Article::search('security')
       ->where('visibility', 'public')
       ->orWhere('author_id', auth()->id())
       ->get();
   ```

## Comparison with Other Search Solutions

| Feature | Vectorize (this package) | Algolia | Meilisearch | Elasticsearch |
|---------|-------------------------|---------|-------------|---------------|
| **Semantic Search** | ✅ Built-in | ❌ Keyword only | ⚠️ Limited | ⚠️ Via plugins |
| **Setup Complexity** | ⭐⭐ Easy | ⭐ Very Easy | ⭐⭐ Easy | ⭐⭐⭐⭐ Complex |
| **Cost** | 💰 Cloudflare pricing | 💰💰💰 Premium | 💰 Free/Cheap | 💰💰 Moderate |
| **Latency** | Fast (edge network) | Very Fast | Fast | Moderate |
| **Filtering** | ⚠️ Basic metadata | ✅ Advanced | ✅ Good | ✅ Advanced |
| **Typo Tolerance** | ❌ No | ✅ Yes | ✅ Yes | ✅ Yes |
| **Relevance by Keywords** | ❌ No | ✅ Excellent | ✅ Good | ✅ Excellent |
| **Relevance by Meaning** | ✅ Excellent | ❌ No | ⚠️ Limited | ⚠️ Via plugins |
| **Infrastructure** | Serverless | Managed | Self-host/Managed | Self-host/Managed |

### When to Use Vectorize

**Good fit:**
- Semantic/conceptual search (finding by meaning, not keywords)
- Multi-language search (embeddings understand concepts across languages)
- Finding similar content or recommendations
- Applications already using Cloudflare
- Budget-conscious projects needing semantic search

**Not ideal for:**
- Exact keyword matching
- Complex filtering and faceting requirements
- Typo-tolerant search
- Traditional full-text search
- Applications requiring instant consistency

## FAQ

**Q: Can I use multiple models in the same index?**
A: Yes! The driver automatically namespaces vectors by model class, so multiple models can coexist in one index.

**Q: How accurate is semantic search compared to keyword search?**
A: Semantic search excels at understanding intent and meaning, but may miss exact keyword matches. Consider your use case.

**Q: Can I migrate from Algolia/Meilisearch to Vectorize?**
A: Yes, but be aware that Vectorize uses semantic search, which behaves differently from keyword-based search engines.

**Q: What happens if I change the embedding model?**
A: You'll need to create a new index with the correct dimensions and re-index all your data.

**Q: Is there a limit on the number of vectors?**
A: Check Cloudflare's Vectorize pricing and limits for your account tier.

**Q: Can I use this with multilingual content?**
A: Yes! The BGE embedding models support multiple languages and can find semantically similar content across languages.

## Contributing

Contributions are welcome! Please submit pull requests or open issues on GitHub.

### Development Setup

```bash
# Clone the repository
git clone https://github.com/brynj-digital/laravel-scout-vectorize.git
cd laravel-scout-vectorize

# Install dependencies
composer install

# Run tests
composer test

# Run code style checks
composer format
```

## License

This package is open-source software licensed under the [MIT license](LICENSE.md).

## Credits

- Built for use with [Cloudflare Vectorize](https://developers.cloudflare.com/vectorize/)
- Integrates with [Laravel Scout](https://laravel.com/docs/scout)

## Support

For issues, questions, or contributions, please visit the [GitHub repository](https://github.com/brynj-digital/laravel-scout-vectorize).

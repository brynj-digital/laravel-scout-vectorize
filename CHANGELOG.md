# Changelog

All notable changes to `laravel-scout-vectorize` will be documented in this file.

## [Unreleased]

### Added
- Initial release
- Cloudflare Vectorize driver for Laravel Scout
- Support for semantic search using vector embeddings
- Automatic embedding generation using Cloudflare Workers AI
- Batch indexing and deletion operations
- Three artisan commands:
  - `scout:vectorize-import` - Import all records of a model
  - `scout:vectorize-flush` - Flush all vectors for a specific model
  - `scout:vectorize-clear` - Clear all vectors from the index
- Support for custom `toSearchableText()` method on models
- Automatic model prefixing for multi-model indexes
- Configuration file for Cloudflare credentials and embedding model selection
- Comprehensive documentation and usage examples

### Features
- PHP 8.1+ support
- Laravel 10.x and 11.x compatibility
- Laravel Scout 10.x integration
- Multiple embedding model support (@cf/baai/bge-small/base/large-en-v1.5)
- Eventual consistency handling in batch operations
- Metadata support for model identification and filtering

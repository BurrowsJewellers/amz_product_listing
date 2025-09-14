# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 11 application that manages product listings across multiple e-commerce platforms: Amazon, Shopify, and Catch. It synchronizes product data from RetailEdge POS system to these platforms.

## Key Architecture

### Service Layer
- **Amazon Services** (`app/Services/Amazon/`): Handles Amazon SP-API integration for catalog, listings, and reports
- **Shopify Services** (`app/Services/ShopifyService.php`): Manages Shopify GraphQL/REST API operations
- **RetailEdge Services** (`app/Services/RetailEdgeService.php`): Interfaces with RetailEdge POS system

### Command Structure
- **Amazon Commands** (`app/Console/Commands/Amazon/`): Product feed generation, inventory updates, order management
- **Shopify Commands** (`app/Console/Commands/Shopify/`): Product CRUD, inventory sync, metafield management, image uploads
- **Catch Commands** (`app/Console/Commands/Catch/`): CSV generation and marketplace integration

### Dynamic Metafield System
The application implements a sophisticated metafield assignment system that dynamically determines whether custom fields should be assigned at the product or variant level based on data analysis (see `MetafieldAssignmentService`).

## Common Development Commands

```bash
# Development server (runs server, queue, logs, and vite concurrently)
composer dev

# Run specific services
php artisan serve
php artisan queue:listen --tries=1
php artisan pail --timeout=0
npm run dev

# Build assets
npm run build

# Code quality
./vendor/bin/pint          # Laravel Pint for code formatting
php artisan test           # Run PHPUnit tests

# Database
php artisan migrate
php artisan db:seed

# Common artisan commands
php artisan shopify:create-product
php artisan shopify:update-product
php artisan shopify:update-inventory
php artisan shopify:create-metafield-definitions
php artisan amazon:generate-products-xml
php artisan amazon:submit-feed
```

## Important Integrations

### Shopify Integration
- Uses both REST API and GraphQL
- Implements webhook handling for order creation
- Dynamic metafield assignment based on product hierarchy
- Batch operations for performance

### Amazon SP-API
- Feed submission for products, prices, inventory, and images
- Report processing for merchant listings
- Order retrieval and processing

### RetailEdge POS
- Product data synchronization
- Inventory level management
- ISD (Item Specific Data) field mapping to marketplace metafields

## Database Considerations
- Uses SQLite by default (see `database/database.sqlite`)
- Queue jobs stored in database
- Session management via database
- Telescope available for debugging (disabled by default)

## Environment Configuration
- Copy `.env.example` to `.env` for initial setup
- Key integrations require API credentials for Shopify, Amazon, RetailEdge, and Catch
- Queue connection should be set to `database` for production
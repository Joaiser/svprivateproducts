# svprivateproducts

PrestaShop module to make products private per customer and per shop.

The module uses direct `customer <-> product` relations. It does not rely on customer groups as the main access rule.

## Features

- Mark a product as private by assigning one or more allowed customers.
- Hide private products from standard PrestaShop product listings for unauthorized customers.
- Block direct product URL access for unauthorized customers.
- Redirect unauthorized visitors to a configured product per private product.
- Return a real 404 when a private product has no redirect configured.
- Block unauthorized cart quantity/add-to-cart attempts.
- Multistore-aware checks using the current `id_shop`.
- Back office autocomplete for products and customers.
- Debug logs that can be enabled from the module configuration.

## Compatibility

- Tested target: PrestaShop 8.2.x.
- The module is intended for standard PrestaShop product controllers and product search flows.
- Third-party search engines, custom themes, custom controllers, and full page cache systems may need extra configuration. See the cache and search sections below.

## Installation

1. Copy the `svprivateproducts` folder into `modules/`.
2. Install the module from the PrestaShop Back Office.
3. Open the module configuration once after upgrading an existing installation, so the module can register new hooks and create missing tables.

The module creates these tables:

- `PREFIX_sv_private_product_access`
- `PREFIX_sv_private_product_redirect`

## How Access Works

A product is public by default.

A product becomes private when it has at least one active relation in `PREFIX_sv_private_product_access` for the current shop.

Rules:

- Product with no active relations: visible and accessible to everyone.
- Product with active relations: private.
- Private product: only customers with an active relation for that product and shop can access it.
- Unauthorized customer or guest: redirected to the product configured for that private product.
- Unauthorized customer or guest with no redirect configured: receives a 404 response.

## Back Office Usage

In the module configuration page you can:

1. Select the private product.
2. Select one or more customers allowed to access it.
3. Optionally select a redirect product for unauthorized visitors.
4. Save the relation.
5. Enable, disable, or delete existing relations.

The redirect is configured per private product.

Example:

- Private product: `1191`
- Allowed customers: `13036`, `14500`
- Redirect product: `957`

Result:

- Customers `13036` and `14500` can see product `1191`.
- Any other customer or guest visiting product `1191` is redirected to product `957`.
- If no redirect product is configured for `1191`, unauthorized visitors receive 404.

## Replace Existing Customers

By default, saving a product adds or updates the selected customers without deleting existing relations.

Use `Replace existing list` if you want the selected customers to become the complete allowed list for that product.

## Cache And Full Page Cache

The access protection is server-side and runs through PrestaShop hooks.

Without full page cache, product access is checked normally on each request.

If the shop uses a full page cache system, private product URLs must not be served from public cache. This includes systems such as:

- LiteSpeed Cache
- Varnish
- Cloudflare APO or similar full page caching
- Hosting-level HTML cache
- Any module that serves cached product HTML before PrestaShop executes PHP hooks

Recommended configuration for full page cache systems:

- Exclude private product URLs from public cache.
- Purge cache after creating, updating, disabling, or deleting private product rules.
- Do not cache private product pages for guests or logged-in customers.
- If using LiteSpeed Cache, add private product URL patterns to the URL blacklist and purge all LiteSpeed cache after changes.

The module sends `no-store, no-cache` headers when blocking access, but it cannot prevent an external cache from serving an already cached HTML response if that cache bypasses PrestaShop/PHP.

## Third-Party Search Engines

The module filters standard PrestaShop product listings through the ProductSearch flow.

Third-party search engines such as Doofinder, Algolia, Elasticsearch modules, or custom search modules may bypass PrestaShop ProductSearch. In that case:

- Private products may still appear in the third-party search results unless the search index is filtered separately.
- Direct product URL access remains protected when the request reaches PrestaShop/PHP.
- If full page cache serves the product page before PHP, configure the cache exclusions described above.

## Security Notes

- Back office actions use admin tokens.
- Product and customer IDs are validated before saving relations.
- Cart update/add-to-cart attempts are checked server-side.
- Unauthorized product access is blocked by `id_product`, not by reference or product name.
- Products with similar references or duplicated names must be configured separately if they need private access rules.

## Debug Logs

Debug logging is disabled by default.

When `Debug logs` is enabled in the module configuration, the module writes diagnostic entries to the PrestaShop logs and enables browser console logs for the Back Office autocomplete.

Keep debug logs disabled in production unless you are troubleshooting.

Useful SQL query while debugging:

```sql
SELECT id_log, date_add, message
FROM PREFIX_log
WHERE message LIKE '%svprivateproducts%'
ORDER BY date_add DESC
LIMIT 100;
```

Replace `PREFIX_` with your PrestaShop database prefix.

## Minimal Test Checklist

1. Product with no active relations is visible to everyone.
2. Product with an active relation for customer A is visible and accessible to customer A.
3. Product with an active relation for customer A is hidden from standard listings for customer B.
4. Product with an active relation for customer A is hidden from standard listings for guests.
5. Unauthorized direct access redirects to the configured product for that private product.
6. Unauthorized direct access returns 404 when no redirect is configured for that private product.
7. Authorized customer can add the product to cart if stock and PrestaShop rules allow it.
8. Unauthorized customer cannot add the product to cart through manual URL/POST requests.
9. If full page cache is enabled, private product URLs are excluded and cache is purged after rule changes.

## Known Limitations

- The module does not automatically filter every third-party search index.
- The module does not provide a universal sitemap exclusion for all third-party sitemap modules.
- Full page cache systems must be configured so private product pages are not served from public cache.
- Access rules are per `id_product`; duplicate products must be configured independently.

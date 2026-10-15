# Package Manager API

All management endpoints require `Authorization: Bearer <admin access token>`, an active admin, the `admin` token ability, and the `manage-packages` gate. This gate follows Categories and Destinations: any active admin may manage packages, regardless of the super-user flag. There is no owner or tenant column on packages; nested itinerary/photo IDs are always checked against their package.

Responses use the existing `status`, `data`, `pagination`, `message` envelope. Validation failures return HTTP 422 and add field-level `errors`; no step changes are committed. Reads/edits/deletes return 200; new packages return 201. Missing packages return 404, denied permissions 403, missing/wrong-model tokens 401, and database uniqueness races 409.

## Routes

| Method | URL | Route name | Purpose |
| --- | --- | --- | --- |
| GET | `/api/admin/packages` | `admin.packages.index` | Paginated admin listing, including unfinished packages |
| GET | `/api/admin/packages/{package}` | `admin.packages.show` | Restore the complete saved form |
| DELETE | `/api/admin/packages/{package}` | `admin.packages.destroy` | Delete package, pivots, itineraries, gallery, and files |
| POST | `/api/admin/packages/basic-details` | `admin.packages.basic-details.store` | Save step 1; create a package or update supplied `package_id` |
| PATCH | `/api/admin/packages/{package}/basic-details` | `admin.packages.basic-details.update` | Save step 1 on the existing package |
| PATCH | `/api/admin/packages/{package}/categories-destinations` | `admin.packages.categories-destinations.update` | Save step 2 |
| PATCH | `/api/admin/packages/{package}/itineraries` | `admin.packages.itineraries.update` | Save step 3 |
| PATCH | `/api/admin/packages/{package}/details` | `admin.packages.details.update` | Save step 4 |
| PATCH | `/api/admin/packages/{package}/photos` | `admin.packages.photos.update` | Save step 5 |
| GET | `/api/packages` | `packages.index` | Public listing: active packages with `current_step = 6` only |

`{package}` is the numeric package ID. Return `data.id` from step 1 must be retained by the client for all subsequent saves. Supplying `package_id` on the POST endpoint updates that record and returns 200; it never creates another package. If also supplied on a PATCH request, it must match the URL. Retrying a creation request without an ID uses normal unique-slug validation; this is not an idempotency-key endpoint.

## Progress and restore

`current_step` is the next unfinished step: 1 initially, then 2/3/4/5 after those preceding steps, and 6 after photos are saved. `is_complete` is true at 6. `completed_steps` is derived: `[]` initially and `[1,2,3,4,5]` at completion. Client-supplied nonempty `current_step` is rejected.

An unfinished package cannot skip ahead. Earlier steps can be saved repeatedly without decreasing progress. Each save locks the package and commits step data and progress together. The frontend should advance only after a successful response, and resume using GET show or the resource returned by any save. All step-save responses and show include all saved fields, `category_ids`, `destination_ids`, selected category/destination resources, ordered `itineraries`, ordered `images`, cover URL, and progress.

Only validated fields belonging to the requested step are persisted; unrelated top-level fields are ignored. Optional omitted fields keep their previous values; explicitly sending `null` clears nullable text fields. Rich-text strings are stored as submitted, matching Categories and Destinations; clients must sanitize trusted HTML appropriately when rendering it.

## Step 1: basic details

Send JSON. Required on both creation and editing: `title`, `slug`, `duration`. Example:

```json
{
  "title": "Royal Rajasthan Escape",
  "slug": "royal-rajasthan-escape",
  "duration": "6 Nights 7 Days",
  "tour_best_time": "October to March",
  "is_special_package": false,
  "status": true,
  "meta_title": "Luxury Rajasthan Tours",
  "meta_keyword": "heritage,rajasthan,luxury tours",
  "meta_description": "Explore Jaipur and Udaipur."
}
```

Title, slug, and meta title have a 255-character limit. Slugs use lowercase letters/numbers and single hyphens between words and are unique, ignoring the current record on update. Duration, best time, meta keyword, and meta description have a 65,535-character limit. Best time and SEO fields are optional/nullable. Booleans follow existing Laravel APIs: JSON `true`/`false`, `1`/`0`, or multipart `"1"`/`"0"`; strings `"true"`/`"false"` are not accepted. Status defaults to active and special-package defaults to false.

## Step 2: categories and destinations

```json
{
  "category_ids": [1, 2],
  "destination_ids": [3, 4]
}
```

Both arrays are required, contain 1–100 distinct existing integer IDs, and replace their respective pivot selections together. No active-only restriction is imposed on selected IDs.

Reuse GET `/api/admin/categories` (`keyword` search) and GET `/api/admin/destinations` (`search` search) for checkbox options. Both accept `page` and `per_page` (maximum 100). Fetch every page indicated by `pagination.next_page_url`/`last_page`; `per_page=100` does not mean all records. Keep already selected IDs from show separately when searching or paging options, including inactive selections.

## Multipart updates

For uploads, send an actual HTTP POST to the PATCH URL with `_method=PATCH` in the multipart body. This follows the existing upload-update convention and PHP multipart parsing. Let the HTTP client set the multipart boundary; send the bearer token and `Accept: application/json`. JSON PATCH is suitable when no files are uploaded.

All image fields accept JPG/JPEG, PNG, and WebP with validated image content and filename extension, up to 2,048 KB per file. SVG, arbitrary paths, and public URL strings are rejected. Storage uses unique generated filenames on `filesystems.package_images_disk`; responses expose a relative `image` path and generated `image_url`. Returned paths are display data, not write inputs.

## Step 3: itineraries

Send `itineraries` with 1–100 items. Each requires `title` (maximum 255 characters), `description` (rich-text string), and `sort_order` (integer 1–4,294,967,295). An existing `id` updates that item; omit it for a new item. An optional `image` file adds/replaces the image; omitting it retains the current image. An itinerary may have no image.

Example multipart field names:

```text
_method=PATCH
itineraries[0][id]=12
itineraries[0][title]=Day 1 – Arrival in Jaipur
itineraries[0][description]=<p>Airport transfer and hotel check-in.</p>
itineraries[0][sort_order]=1
itineraries[0][image]=<optional replacement file>
itineraries[1][title]=Day 2 – City tour
itineraries[1][description]=<p>Visit forts and palaces.</p>
itineraries[1][sort_order]=2
itineraries[1][image]=<optional new file>
deleted_itinerary_ids[0]=13
```

`deleted_itinerary_ids` is optional with up to 100 distinct IDs. Every updated/deleted ID must belong to this package. Saved and deleted ID lists must not overlap. Omitted records remain saved. To remove an item, explicitly send its deletion ID and at least one remaining/new itinerary. Repeating an existing ID updates it without creating duplicates. Ordering uses `sort_order` then `id`, making equal order values stable.

## Step 4: package details

```json
{
  "description": "<p>Package overview and highlights.</p>",
  "inclusion": "<ul><li>Hotels and breakfast</li></ul>",
  "exclusion": null,
  "accommodation_details": "<p>Luxury hotels.</p>",
  "transportation": "<p>Private air-conditioned vehicle.</p>"
}
```

`description` is required. The other four fields accept nullable strings. These are long-text columns with the same string-validation convention as existing description fields.

## Step 5: photos

Each `photos` item associates its upload or existing ID with a required `sort_order` (same bounds as itineraries). New items require an `image` file; existing items require their `id` and can be reordered without a file. An optional replacement upload on an existing ID is also supported.

```text
_method=PATCH
photos[0][id]=21
photos[0][sort_order]=2
photos[1][image]=<new image file>
photos[1][sort_order]=1
deleted_image_ids[0]=22
```

Both `photos` and `deleted_image_ids` are optional arrays of up to 100 items. Deletion IDs must be distinct; existing IDs in `photos` must also be distinct. Every ID belongs to the package, and saved/deleted IDs cannot overlap. Omitted existing photos are retained. At least one photo must remain after applying the request, including when editing a complete package; otherwise the whole transaction rolls back. An empty JSON body can complete step 5 when photos already exist.

The first image ordered by `sort_order`, then `id`, provides `cover_image_url`; there is no cover flag. To reorder the entire gallery, send all current IDs with the desired orders, plus any uploads. Photo rows have no timestamps.

## Listings

Both lists accept only `keyword` (title or slug), `status` (`1` or `0`), `page`, and `per_page`. Unknown filters do not affect the query. Search is grouped with status constraints, and wildcard characters in keywords are literal. Default page size is 15, maximum 100, sorted by descending package ID. Filtering affects totals and is preserved in pagination links. Empty pages return `data: []` and null `from`/`to` values.

The resource includes `id`, `title`, `duration`, boolean `status`, `cities_covered` (selected destination titles), `cover_image_url`, SEO/detail fields, and progress. Listing eager-loads destinations and the first image per package; its `images` array contains that cover preview only. Use show to retrieve the full gallery, categories, and itineraries. Dates use the existing ISO timestamp format. Public listings always require active status and completion, so requesting `status=0` there returns an empty list.

## File lifecycle and setup

Package observers store transient uploads inside the database transaction, remove new files after rollback, and delete old/removed files only after the outermost commit. Parent deletion gathers all child paths before database cascades. Missing files do not block deletion; cleanup failures after commit are reported internally without undoing a committed save.

The five new migrations have not been run against the application database. Apply them in the intended deployment environment:

```sh
php artisan migrate --no-interaction
php artisan storage:link --no-interaction
```

`PACKAGE_IMAGES_DISK` optionally overrides the default `public` disk. Configure `APP_URL` for correct generated URLs and ensure the upload directories are writable. Non-public disks must supply a suitable URL configuration. The optional `PackageSeeder` creates one inactive, unfinished sample only and is not registered in `DatabaseSeeder`.

Feature tests use the configured in-memory SQLite test database and fake storage; file lifecycle tests use real test transaction commits. Run:

```sh
php artisan test --compact tests/Feature/PackageControllerTest.php tests/Feature/PackageObserverTest.php
php artisan test --compact
```

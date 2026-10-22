<?php

use App\Models\Car;
use App\Models\CarCategory;
use App\Models\Category;
use App\Models\Destination;
use App\Models\Package;
use App\Models\PackageImage;
use App\Models\PackageItinerary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

dataset('public catalog listings', [
    'packages' => ['/api/packages', Package::class, ['current_step' => 6]],
    'destinations' => ['/api/destinations', Destination::class, []],
    'categories' => ['/api/categories', Category::class, []],
    'cars' => ['/api/cars', Car::class, []],
]);

test('taxonomy image URLs use the placeholder for absent images and preserve existing images', function (string $uri, string $model, string $diskSetting, ?string $image, bool $fileExists) {
    Storage::fake('public');
    Storage::fake('local');
    config(['filesystems.'.$diskSetting => 'local']);
    $model::factory()->create(['image' => $image]);
    if ($fileExists) {
        Storage::disk('local')->put($image, 'image contents');
    }

    $response = $this->getJson($uri);

    $response->assertOk()->assertJsonPath('data.0.image', $image)
        ->assertJsonPath('data.0.image_url', $fileExists ? Storage::disk('local')->url($image) : asset('image-not-available.png'));
})->with([
    'category' => ['/api/categories', Category::class, 'category_images_disk'],
    'destination' => ['/api/destinations', Destination::class, 'destination_images_disk'],
])->with([
    'no image' => [null, false],
    'blank image' => ['', false],
    'missing file' => ['taxonomy/missing.jpg', false],
    'existing file' => ['taxonomy/existing.jpg', true],
]);

test('public listings return only available records in their default order with pagination', function (string $uri, string $model, array $attributes) {
    if ($model === Destination::class) {
        $attributes['title'] = 'Destination';
    }

    $records = $model::factory()->count(16)->create($attributes);
    $model::factory()->inactive()->create($attributes);

    DB::enableQueryLog();

    try {
        $response = $this->getJson($uri.'?ignored=value');
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $response->assertOk()->assertJsonPath('status', true)->assertJsonCount(15, 'data')
        ->assertJsonPath('data.0.id', $records->last()->id)->assertJsonPath('pagination.total', 16)
        ->assertJsonPath('pagination.per_page', 15)->assertJsonPath('pagination.last_page', 2)
        ->assertJsonPath('pagination.from', 1)->assertJsonPath('pagination.to', 15)
        ->assertJsonPath('pagination.prev_page_url', null)->assertJsonMissingPath('data.data')
        ->assertJsonMissingPath('links')->assertJsonMissingPath('meta');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect($response->json('pagination.next_page_url'))->not->toContain('ignored');
    expect($queries)->toHaveCount(match ($model) {
        Package::class => 5,
        Car::class => 3,
        default => 2,
    });

    $this->getJson($uri.'?page=2')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $records->first()->id)->assertJsonPath('pagination.current_page', 2);
})->with('public catalog listings');

test('public package listings include only their related active categories', function () {
    $category = Category::factory()->create();
    $hiddenCategory = Category::factory()->inactive()->create();
    Category::factory()->create();
    $package = Package::factory()->atStep(6)->hasAttached($category)->hasAttached($hiddenCategory)->create();
    $emptyPackage = Package::factory()->atStep(6)->create();

    $response = $this->getJson('/api/packages');

    $response->assertOk()->assertJsonPath('data.0.id', $emptyPackage->id)
        ->assertJsonPath('data.0.categories', [])->assertJsonPath('data.0.category_ids', [])
        ->assertJsonPath('data.1.id', $package->id)->assertJsonCount(1, 'data.1.categories')
        ->assertJsonPath('data.1.categories.0.id', $category->id)
        ->assertJsonPath('data.1.categories.0.title', $category->title)
        ->assertJsonPath('data.1.categories.0.slug', $category->slug)
        ->assertJsonPath('data.1.category_ids', [$category->id]);
});

test('public listings return empty arrays and nullable bounds beyond the last page', function (string $uri, string $model, array $attributes) {
    $model::factory()->create($attributes);

    $response = $this->getJson($uri.'?page=100');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
})->with('public catalog listings');

test('public listings validate pagination using the standard 422 envelope', function (string $uri, string $model, array $attributes, array $query, array $errors) {
    $response = $this->get($uri.'?'.http_build_query($query));

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors($errors);
})->with('public catalog listings')->with([
    'zero page' => [['page' => 0], ['page' => 'The page field must be at least 1.']],
    'noninteger page' => [['page' => 'abc'], ['page' => 'The page field must be an integer.']],
    'zero page size' => [['per_page' => 0], ['per_page' => 'The per page field must be at least 1.']],
    'oversized page size' => [['per_page' => 101], ['per_page' => 'The per page field must not be greater than 100.']],
]);

test('public categories filter by their packages destination type without duplicate categories', function (int $type) {
    $domesticCategory = Category::factory()->create();
    $internationalCategory = Category::factory()->create();
    $domestic = Destination::factory()->create();
    $international = Destination::factory()->international()->create();
    Package::factory()->atStep(6)->hasAttached($domesticCategory)->hasAttached($domestic)->count(2)->create();
    Package::factory()->atStep(6)->hasAttached($internationalCategory)->hasAttached($international)->create();
    Category::factory()->create();

    $response = $this->getJson('/api/categories?destination_type='.$type);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.0.id', $type === 1 ? $domesticCategory->id : $internationalCategory->id);
})->with(['domestic' => [1], 'international' => [2]]);

test('public category destination type filters respect category package and destination visibility', function (bool $categoryStatus, bool $packageStatus, int $step, bool $destinationStatus) {
    $category = Category::factory()->create(['status' => $categoryStatus]);
    $destination = Destination::factory()->create(['status' => $destinationStatus]);
    Package::factory()->atStep($step)->hasAttached($category)->hasAttached($destination)->create(['status' => $packageStatus]);

    $response = $this->getJson('/api/categories?destination_type=1');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
})->with([
    'inactive category' => [false, true, 6, true],
    'inactive package' => [true, false, 6, true],
    'draft package' => [true, true, 5, true],
    'inactive destination' => [true, true, 6, false],
]);

test('public categories filter by their own popularity including false', function (int $popular) {
    $ordinary = Category::factory()->create();
    $recommended = Category::factory()->popular()->create();
    Category::factory()->inactive()->create(['is_most_popular' => (bool) $popular]);

    $response = $this->getJson('/api/categories?is_most_popular='.$popular);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.0.id', $popular === 1 ? $recommended->id : $ordinary->id)
        ->assertJsonPath('data.0.is_most_popular', (bool) $popular);
})->with(['ordinary' => [0], 'popular' => [1]]);

test('public categories combine filters and preserve them across package destination pagination', function () {
    $categories = Category::factory()->popular()->count(2)->create();
    $ordinaryCategory = Category::factory()->create();
    $destination = Destination::factory()->international()->create();
    Package::factory()->atStep(6)->hasAttached($categories)->hasAttached($destination)->count(2)->create();
    Package::factory()->atStep(6)->hasAttached($ordinaryCategory)->hasAttached($destination)->create();
    Category::factory()->popular()->create();

    $response = $this->getJson('/api/categories?destination_type=2&is_most_popular=1&per_page=1');

    $response->assertOk()->assertJsonPath('status', true)->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $categories->last()->id)->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.last_page', 2)->assertJsonPath('message', 'Categories retrieved successfully.');
    parse_str(parse_url($response->json('pagination.next_page_url'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['destination_type' => '2', 'is_most_popular' => '1', 'per_page' => '1', 'page' => '2']);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);

    $this->getJson('/api/categories?'.http_build_query($query))->assertOk()
        ->assertJsonPath('data.0.id', $categories->first()->id)->assertJsonPath('pagination.current_page', 2);
});

test('public categories ignore blank optional filters', function () {
    Category::factory()->create();
    Category::factory()->popular()->create();

    $response = $this->getJson('/api/categories?destination_type=&is_most_popular=');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2);
});

test('public categories return an empty list when combined filters have no matches', function () {
    $category = Category::factory()->create();
    $destination = Destination::factory()->international()->create();
    Package::factory()->atStep(6)->hasAttached($category)->hasAttached($destination)->create();
    Category::factory()->popular()->create();

    $response = $this->getJson('/api/categories?destination_type=2&is_most_popular=1');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('invalid public category filters receive 422', function (array $query, array $errors) {
    $response = $this->get('/api/categories?'.http_build_query($query));

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors($errors);
})->with([
    'unknown type' => [['destination_type' => 3], ['destination_type' => 'The selected destination type is invalid.']],
    'fractional type' => [['destination_type' => 1.5], ['destination_type' => 'The destination type field must be an integer.']],
    'array type' => [['destination_type' => [1]], ['destination_type' => 'The destination type field must be an integer.']],
    'invalid popularity' => [['is_most_popular' => 'yes'], ['is_most_popular' => 'The is most popular field must be true or false.']],
    'array popularity' => [['is_most_popular' => [1]], ['is_most_popular' => 'The is most popular field must be true or false.']],
]);

test('public destinations sort popular records first then titles ascending with stable pagination', function () {
    $popularZurich = Destination::factory()->popular()->create(['title' => 'Zurich']);
    $ordinaryAthens = Destination::factory()->create(['title' => 'Athens']);
    $popularBerlin = Destination::factory()->popular()->create(['title' => 'Berlin']);
    $tiedBerlin = Destination::factory()->popular()->create(['title' => 'Berlin']);
    $ordinaryZurich = Destination::factory()->create(['title' => 'Zurich']);

    $response = $this->getJson('/api/destinations?per_page=3');

    $response->assertOk()->assertJsonPath('pagination.total', 5);
    expect(array_column($response->json('data'), 'id'))->toBe([$tiedBerlin->id, $popularBerlin->id, $popularZurich->id]);

    $nextPage = $this->getJson('/api/destinations?per_page=3&page=2');

    $nextPage->assertOk()->assertJsonPath('pagination.current_page', 2);
    expect(array_column($nextPage->json('data'), 'id'))->toBe([$ordinaryAthens->id, $ordinaryZurich->id]);
});

test('public destinations filter by destination type', function (int $type) {
    $domestic = Destination::factory()->create();
    $international = Destination::factory()->international()->create();
    Destination::factory()->inactive()->create(['destination_type' => $type]);

    $response = $this->getJson('/api/destinations?destination_type='.$type);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.0.id', $type === 1 ? $domestic->id : $international->id)
        ->assertJsonPath('data.0.destination_type', $type);
})->with(['domestic' => [1], 'international' => [2]]);

test('public destinations filter by popularity including false', function (int $popular) {
    $ordinary = Destination::factory()->create();
    $recommended = Destination::factory()->popular()->create();
    Destination::factory()->inactive()->create(['is_most_popular' => (bool) $popular]);

    $response = $this->getJson('/api/destinations?is_most_popular='.$popular);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.0.id', $popular === 1 ? $recommended->id : $ordinary->id)
        ->assertJsonPath('data.0.is_most_popular', (bool) $popular);
})->with(['ordinary' => [0], 'popular' => [1]]);

test('public destinations combine filters and preserve them in pagination links', function () {
    $destinations = Destination::factory()->international()->popular()->count(2)
        ->sequence(['title' => 'Zurich'], ['title' => 'Athens'])->create();
    Destination::factory()->popular()->create();
    Destination::factory()->international()->create();
    Destination::factory()->international()->popular()->inactive()->create();

    $response = $this->getJson('/api/destinations?destination_type=2&is_most_popular=1&per_page=1');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $destinations->last()->id)
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2);
    parse_str(parse_url($response->json('pagination.next_page_url'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['destination_type' => '2', 'is_most_popular' => '1', 'per_page' => '1', 'page' => '2']);

    $this->getJson('/api/destinations?'.http_build_query($query))->assertOk()
        ->assertJsonPath('data.0.id', $destinations->first()->id)->assertJsonPath('pagination.current_page', 2);
});

test('public destinations ignore blank optional filters', function () {
    Destination::factory()->create();
    Destination::factory()->international()->popular()->create();

    $response = $this->getJson('/api/destinations?destination_type=&is_most_popular=');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2);
});

test('public destinations return an empty list when combined filters have no matches', function () {
    Destination::factory()->popular()->create();
    Destination::factory()->international()->create();

    $response = $this->getJson('/api/destinations?destination_type=2&is_most_popular=1');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('invalid public destination filters receive 422', function (array $query, array $errors) {
    $response = $this->get('/api/destinations?'.http_build_query($query));

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors($errors);
})->with([
    'unknown type' => [['destination_type' => 3], ['destination_type' => 'The selected destination type is invalid.']],
    'fractional type' => [['destination_type' => 1.5], ['destination_type' => 'The destination type field must be an integer.']],
    'array type' => [['destination_type' => [1]], ['destination_type' => 'The destination type field must be an integer.']],
    'invalid popularity' => [['is_most_popular' => 'yes'], ['is_most_popular' => 'The is most popular field must be true or false.']],
    'array popularity' => [['is_most_popular' => [1]], ['is_most_popular' => 'The is most popular field must be true or false.']],
]);

test('homepage collections apply visibility flags and database limits without nested collections', function (string $uri, string $model, string $table, array $attributes, int $limit) {
    $records = $model::factory()->count($limit + 1)->create($attributes);
    $model::factory()->inactive()->create($attributes);
    if ($model !== CarCategory::class) {
        $flag = $model === Package::class ? 'is_special_package' : 'is_most_popular';
        $model::factory()->create(array_replace($attributes, [$flag => false]));
    }
    if ($model === Package::class) {
        Package::factory()->atStep(5)->create(['is_special_package' => true]);
        PackageImage::factory()->count(3)->for($records->last())->create();
    }
    DB::enableQueryLog();

    try {
        $response = $this->getJson($uri.'?per_page=1&page=2');
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $response->assertOk()->assertJsonPath('status', true)->assertJsonCount($limit, 'data')
        ->assertJsonPath('pagination', null)->assertJsonMissingPath('data.0.packages')
        ->assertJsonMissingPath('data.0.itineraries')->assertJsonMissingPath('data.0.destinations')
        ->assertJsonMissingPath('data.0.cars');
    expect(array_column($response->json('data'), 'id'))->toBe($records->reverse()->take($limit)->values()->modelKeys());
    $mainQuery = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'from "'.$table.'"'));
    expect($mainQuery['query'])->toContain('limit '.$limit);
    if ($model === Package::class) {
        $response->assertJsonCount(1, 'data.0.images');
    }
})->with([
    'special packages' => ['/api/packages/special', Package::class, 'packages', ['current_step' => 6, 'is_special_package' => true], 20],
    'top destinations' => ['/api/destinations/top', Destination::class, 'destinations', ['is_most_popular' => true], 20],
    'popular categories' => ['/api/categories/popular', Category::class, 'categories', ['is_most_popular' => true], 20],
    'car categories' => ['/api/cars/categories', CarCategory::class, 'car_categories', [], 10],
]);

test('public category collections include count_packages for only their related published packages', function (string $uri) {
    $emptyCategory = Category::factory()->popular()->create();
    $category = Category::factory()->popular()->create();
    Package::factory()->atStep(6)->hasAttached($category)->count(2)->create();
    Package::factory()->atStep(5)->hasAttached($category)->create();
    Package::factory()->atStep(6)->inactive()->hasAttached($category)->create();
    Package::factory()->atStep(6)->create();

    $response = $this->getJson($uri);

    $response->assertOk()->assertJsonPath('data.0.id', $category->id)
        ->assertJsonPath('data.0.count_packages', 2)->assertJsonPath('data.1.id', $emptyCategory->id)
        ->assertJsonPath('data.1.count_packages', 0)->assertJsonMissingPath('data.0.packages')
        ->assertJsonMissingPath('data.0.packages_count');
})->with(['listing' => ['/api/categories'], 'popular categories' => ['/api/categories/popular']]);

test('filtered public categories include the total published package count for the category', function () {
    $category = Category::factory()->popular()->create();
    $domestic = Destination::factory()->create();
    $international = Destination::factory()->international()->create();
    Package::factory()->atStep(6)->hasAttached($category)->hasAttached($domestic)->create();
    Package::factory()->atStep(6)->hasAttached($category)->hasAttached($international)->create();
    Package::factory()->atStep(5)->hasAttached($category)->hasAttached($international)->create();

    $response = $this->getJson('/api/categories?destination_type=2&is_most_popular=1');

    $response->assertOk()->assertJsonPath('data.0.id', $category->id)
        ->assertJsonPath('data.0.count_packages', 2)->assertJsonPath('pagination.total', 1);
});

test('public category details include count_packages independently of the package page', function (int $publishedCount, int $page) {
    $category = Category::factory()->create();
    Package::factory()->atStep(6)->hasAttached($category)->count($publishedCount)->create();
    Package::factory()->atStep(5)->hasAttached($category)->create();
    Package::factory()->atStep(6)->inactive()->hasAttached($category)->create();

    $response = $this->getJson('/api/categories/'.$category->slug.'?per_page=1&page='.$page);

    $response->assertOk()->assertJsonPath('data.count_packages', $publishedCount)
        ->assertJsonPath('pagination.total', $publishedCount);
})->with([
    'first package page' => [3, 1],
    'empty package page' => [3, 99],
    'no published packages' => [0, 1],
]);

test('public destination collections include count_packages for only their related published packages', function (string $uri) {
    $emptyDestination = Destination::factory()->popular()->create(['title' => 'Zurich']);
    $destination = Destination::factory()->popular()->create(['title' => 'Athens']);
    $publishedPackages = Package::factory()->atStep(6)->count(2)->create();
    $draft = Package::factory()->atStep(5)->create();
    $inactive = Package::factory()->atStep(6)->inactive()->create();
    Package::factory()->atStep(6)->create();
    $destination->packages()->attach($publishedPackages->modelKeys());
    $destination->packages()->attach([$draft->id, $inactive->id]);

    $response = $this->getJson($uri);

    $response->assertOk()->assertJsonPath('data.0.id', $destination->id)
        ->assertJsonPath('data.0.count_packages', 2)->assertJsonPath('data.1.id', $emptyDestination->id)
        ->assertJsonPath('data.1.count_packages', 0)->assertJsonMissingPath('data.0.packages')
        ->assertJsonMissingPath('data.0.packages_count');
})->with([
    'listing' => ['/api/destinations'],
    'filtered listing' => ['/api/destinations?destination_type=1&is_most_popular=1'],
    'top destinations' => ['/api/destinations/top'],
]);

test('empty homepage collections return an unpaginated empty array', function (string $uri) {
    $response = $this->getJson($uri);

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination', null);
})->with(['/api/packages/special', '/api/destinations/top', '/api/categories/popular', '/api/cars/categories']);

test('unavailable details return the standard 404 envelope without authentication', function (string $uri, string $model, array $attributes) {
    $record = $model::factory()->inactive()->create($attributes);
    $identifier = $record instanceof Car ? $record->id : $record->slug;

    $response = $this->get($uri.'/'.$identifier);

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
})->with('public catalog listings');

test('missing details return the standard 404 envelope', function (string $uri, string $model, array $attributes) {
    $identifier = match ($model) {
        Category::class => 'missing-category',
        Destination::class => 'missing-destination',
        Package::class => 'missing-package',
        default => '999999',
    };

    $response = $this->getJson($uri.'/'.$identifier);

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
})->with('public catalog listings');

test('incomplete packages are absent from the public listing and return 404 on detail', function () {
    $package = Package::factory()->atStep(5)->create();

    $this->getJson('/api/packages')->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
    $this->getJson('/api/packages/'.$package->slug)->assertNotFound()->assertJsonPath('message', 'Resource not found.');
});

test('public package keywords retain literal matching and published visibility', function (string $keyword, string $title, string $slug) {
    $package = Package::factory()->atStep(6)->create(['title' => $title, 'slug' => $slug]);
    Package::factory()->atStep(6)->create(['title' => 'Other Tour', 'slug' => 'other-tour']);
    Package::factory()->atStep(5)->create(['title' => $title]);
    Package::factory()->atStep(6)->inactive()->create(['title' => $title]);

    $response = $this->getJson('/api/packages?'.http_build_query(['keyword' => $keyword]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $package->id)
        ->assertJsonPath('pagination.total', 1);
})->with([
    'title' => ['Royal', 'Royal Rajasthan', 'rajasthan-tour'],
    'slug' => ['royal', 'Rajasthan Tour', 'royal-rajasthan'],
    'trimmed' => ['  Royal  ', 'Royal Rajasthan', 'rajasthan-tour'],
    'percent' => ['%', '100% Rajasthan', 'rajasthan-tour'],
    'underscore' => ['_', 'Rajasthan_Tour', 'rajasthan-tour'],
    'escape character' => ['!', 'Rajasthan!', 'rajasthan-tour'],
    'zero' => ['0', 'Route 0', 'route-zero'],
]);

test('public package status filters cannot expose inactive packages', function () {
    Package::factory()->atStep(6)->create();
    Package::factory()->atStep(6)->inactive()->create();

    $response = $this->getJson('/api/packages?status=0');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
});

test('invalid public package filters receive 422', function (array $query, array $errors) {
    $response = $this->getJson('/api/packages?'.http_build_query($query));

    $response->assertUnprocessable()->assertJsonValidationErrors($errors);
})->with([
    'array keyword' => [['keyword' => ['tour']], ['keyword' => 'The keyword field must be a string.']],
    'oversized keyword' => [['keyword' => str_repeat('a', 256)], ['keyword' => 'The keyword field must not be greater than 255 characters.']],
    'invalid status' => [['status' => 'active'], ['status' => 'The status field must be true or false.']],
]);

test('package details include ordered galleries and itineraries and only active taxonomy', function () {
    $package = Package::factory()->atStep(6)->create();
    $category = Category::factory()->create();
    $hiddenCategory = Category::factory()->inactive()->create();
    $destination = Destination::factory()->create();
    $hiddenDestination = Destination::factory()->inactive()->create();
    $package->categories()->attach([$category->id, $hiddenCategory->id]);
    $package->destinations()->attach([$destination->id, $hiddenDestination->id]);
    $lastImage = PackageImage::factory()->for($package)->create(['sort_order' => 2]);
    $firstImage = PackageImage::factory()->for($package)->create(['sort_order' => 1]);
    $tiedImage = PackageImage::factory()->for($package)->create(['sort_order' => 1]);
    $lastDay = PackageItinerary::factory()->for($package)->create(['sort_order' => 2]);
    $firstDay = PackageItinerary::factory()->for($package)->create(['sort_order' => 1, 'image' => 'packages/itineraries/day-one.jpg']);

    $response = $this->getJson('/api/packages/'.$package->slug);

    $response->assertOk()->assertJsonPath('data.id', $package->id)->assertJsonPath('pagination', null)
        ->assertJsonPath('data.category_ids', [$category->id])->assertJsonPath('data.destination_ids', [$destination->id])
        ->assertJsonPath('data.cities_covered', [$destination->title])->assertJsonCount(1, 'data.categories')
        ->assertJsonCount(1, 'data.destinations')->assertJsonCount(3, 'data.images')->assertJsonCount(2, 'data.itineraries')
        ->assertJsonPath('data.images.0.id', $firstImage->id)->assertJsonPath('data.images.1.id', $tiedImage->id)
        ->assertJsonPath('data.images.2.id', $lastImage->id)->assertJsonPath('data.itineraries.0.id', $firstDay->id)
        ->assertJsonPath('data.itineraries.1.id', $lastDay->id)
        ->assertJsonPath('data.cover_image_url', Storage::disk(config('filesystems.package_images_disk'))->url($firstImage->image))
        ->assertJsonPath('data.itineraries.0.image_url', Storage::disk(config('filesystems.package_images_disk'))->url($firstDay->image));
});

test('public package details return 404 for IDs that do not match a slug', function () {
    $package = Package::factory()->atStep(6)->create(['slug' => 'royal-rajasthan']);

    $response = $this->getJson('/api/packages/'.$package->id);

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
});

test('public package details resolve numeric slugs independently of IDs', function () {
    $otherPackage = Package::factory()->atStep(6)->create(['slug' => 'royal-rajasthan']);
    $package = Package::factory()->atStep(6)->create(['slug' => (string) $otherPackage->id]);

    $response = $this->getJson('/api/packages/'.$package->slug);

    $response->assertOk()->assertJsonPath('data.id', $package->id)->assertJsonPath('data.slug', $package->slug);
});

test('public category details return 404 for IDs that do not match a slug', function () {
    $category = Category::factory()->create(['slug' => 'heritage-tours']);

    $response = $this->getJson('/api/categories/'.$category->id);

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
});

test('public category details resolve numeric slugs independently of IDs', function () {
    $otherCategory = Category::factory()->create(['slug' => 'heritage-tours']);
    $category = Category::factory()->create(['slug' => (string) $otherCategory->id]);

    $response = $this->getJson('/api/categories/'.$category->slug);

    $response->assertOk()->assertJsonPath('data.id', $category->id)->assertJsonPath('data.slug', $category->slug);
});

test('category destination options include unique active destinations from all published category packages regardless of page', function (int $page) {
    $category = Category::factory()->create(['slug' => 'golden-triangle-tours']);
    $otherCategory = Category::factory()->create();
    $delhi = Destination::factory()->create(['title' => 'Delhi', 'slug' => 'delhi']);
    $agra = Destination::factory()->create(['title' => 'Agra', 'slug' => 'agra']);
    $jaipur = Destination::factory()->create(['title' => 'Jaipur', 'slug' => 'jaipur']);
    $hiddenDestination = Destination::factory()->inactive()->create();
    $unrelatedDestination = Destination::factory()->create();
    Destination::factory()->create();
    $olderPackage = Package::factory()->atStep(6)->create();
    $newerPackage = Package::factory()->atStep(6)->create();
    $otherPackage = Package::factory()->atStep(6)->create();
    $category->packages()->attach([$olderPackage->id, $newerPackage->id]);
    $otherCategory->packages()->attach($otherPackage);
    $olderPackage->destinations()->attach([$delhi->id, $agra->id, $hiddenDestination->id]);
    $newerPackage->destinations()->attach([$delhi->id, $jaipur->id]);
    $otherPackage->destinations()->attach($unrelatedDestination);

    $response = $this->getJson('/api/categories/golden-triangle-tours?per_page=1&page='.$page);

    $response->assertOk()->assertJsonPath('status', true)
        ->assertJsonPath('data.destinations', [
            ['slug' => 'agra', 'title' => 'Agra'],
            ['slug' => 'delhi', 'title' => 'Delhi'],
            ['slug' => 'jaipur', 'title' => 'Jaipur'],
        ])->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.current_page', $page)
        ->assertJsonPath('message', 'Category retrieved successfully.');
})->with(['first page' => [1], 'second page' => [2], 'empty package page' => [99]]);

test('category destination options exclude inactive destinations and unpublished packages', function (bool $destinationStatus, bool $packageStatus, int $step) {
    $category = Category::factory()->create();
    $destination = Destination::factory()->create(['status' => $destinationStatus]);
    $package = Package::factory()->atStep($step)->create(['status' => $packageStatus]);
    $category->packages()->attach($package);
    $package->destinations()->attach($destination);

    $response = $this->getJson('/api/categories/'.$category->slug);

    $response->assertOk()->assertJsonPath('data.destinations', []);
})->with([
    'inactive destination' => [false, true, 6],
    'inactive package' => [true, false, 6],
    'draft package' => [true, true, 5],
]);

test('category destination options return an empty array when there are no related packages', function () {
    $category = Category::factory()->create();

    $response = $this->getJson('/api/categories/'.$category->slug);

    $response->assertOk()->assertJsonPath('data.destinations', [])->assertJsonPath('data.packages', []);
});

test('public destination details return 404 for IDs that do not match a slug', function () {
    $destination = Destination::factory()->create(['slug' => 'jaipur']);

    $response = $this->getJson('/api/destinations/'.$destination->id);

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
});

test('public destination details resolve numeric slugs independently of IDs', function () {
    $otherDestination = Destination::factory()->create(['slug' => 'jaipur']);
    $destination = Destination::factory()->create(['slug' => (string) $otherDestination->id]);

    $response = $this->getJson('/api/destinations/'.$destination->slug);

    $response->assertOk()->assertJsonPath('data.id', $destination->id)->assertJsonPath('data.slug', $destination->slug)
        ->assertJsonPath('data.categories', []);
});

test('destination category options include unique active categories from all active packages regardless of package page', function (int $page) {
    $destination = Destination::factory()->create(['slug' => 'jaipur']);
    $otherDestination = Destination::factory()->create();
    $heritage = Category::factory()->create(['title' => 'Heritage Tours', 'slug' => 'heritage-tours']);
    $family = Category::factory()->create(['title' => 'Family Tours', 'slug' => 'family-tours']);
    $adventure = Category::factory()->create(['title' => 'Adventure Tours', 'slug' => 'adventure-tours']);
    $inactiveCategory = Category::factory()->inactive()->create();
    $inactivePackageCategory = Category::factory()->create();
    $otherDestinationCategory = Category::factory()->create();
    Category::factory()->create();
    $olderPackage = Package::factory()->atStep(6)->create();
    $newerPackage = Package::factory()->atStep(6)->create();
    $draftPackage = Package::factory()->atStep(5)->create();
    $inactivePackage = Package::factory()->atStep(6)->inactive()->create();
    $unrelatedPackage = Package::factory()->atStep(6)->create();
    $destination->packages()->attach([$olderPackage->id, $newerPackage->id, $draftPackage->id, $inactivePackage->id]);
    $otherDestination->packages()->attach($unrelatedPackage);
    $olderPackage->categories()->attach([$family->id, $heritage->id, $inactiveCategory->id]);
    $newerPackage->categories()->attach($heritage);
    $draftPackage->categories()->attach($adventure);
    $inactivePackage->categories()->attach($inactivePackageCategory);
    $unrelatedPackage->categories()->attach($otherDestinationCategory);
    $packageIds = [$newerPackage->id, $olderPackage->id];
    $packageCategoryIds = [[$heritage->id], [$heritage->id, $family->id]];

    $response = $this->getJson('/api/destinations/jaipur?per_page=1&page='.$page);

    $response->assertOk()->assertJsonPath('data.id', $destination->id)->assertJsonPath('data.slug', 'jaipur')
        ->assertJsonPath('data.categories', [
            ['title' => 'Adventure Tours', 'slug' => 'adventure-tours'],
            ['title' => 'Family Tours', 'slug' => 'family-tours'],
            ['title' => 'Heritage Tours', 'slug' => 'heritage-tours'],
        ])->assertJsonCount(1, 'data.packages')->assertJsonPath('data.packages.0.id', $packageIds[$page - 1])
        ->assertJsonPath('data.packages.0.category_ids', $packageCategoryIds[$page - 1])
        ->assertJsonPath('data.packages.0.categories.0.slug', 'heritage-tours')
        ->assertJsonCount(count($packageCategoryIds[$page - 1]), 'data.packages.0.categories')
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.per_page', 1)
        ->assertJsonPath('pagination.current_page', $page)->assertJsonPath('pagination.last_page', 2)
        ->assertJsonPath('message', 'Destination retrieved successfully.');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
})->with(['first page' => [1], 'second page' => [2]]);

test('destination category options return an empty array when categories or packages are inactive', function (bool $categoryStatus, bool $packageStatus) {
    $destination = Destination::factory()->create();
    $category = Category::factory()->create(['status' => $categoryStatus]);
    $package = Package::factory()->atStep(6)->create(['status' => $packageStatus]);
    $package->destinations()->attach($destination);
    $package->categories()->attach($category);

    $response = $this->getJson('/api/destinations/'.$destination->slug);

    $response->assertOk()->assertJsonPath('data.categories', []);
})->with([
    'inactive category' => [false, true],
    'inactive package' => [true, false],
]);

test('destination category options remain available on an empty package page', function () {
    $destination = Destination::factory()->create();
    $category = Category::factory()->create(['title' => 'Family Tours', 'slug' => 'family-tours']);
    $package = Package::factory()->atStep(6)->create();
    $package->destinations()->attach($destination);
    $package->categories()->attach($category);

    $response = $this->getJson('/api/destinations/'.$destination->slug.'?per_page=1&page=99');

    $response->assertOk()->assertJsonPath('data.packages', [])
        ->assertJsonPath('data.categories', [['title' => 'Family Tours', 'slug' => 'family-tours']])
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('pagination.current_page', 99)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('taxonomy details paginate only their published packages with bounded cover images', function (string $uri, string $model) {
    $taxonomy = $model::factory()->create(['image' => 'taxonomy/cover.jpg']);
    $identifier = $taxonomy->slug;
    $other = $model::factory()->create();
    $packages = Package::factory()->atStep(6)->count(3)->create();
    $draft = Package::factory()->atStep(5)->create();
    $inactive = Package::factory()->atStep(6)->inactive()->create();
    $taxonomy->packages()->attach($packages->modelKeys());
    $taxonomy->packages()->attach([$draft->id, $inactive->id]);
    $other->packages()->attach(Package::factory()->atStep(6)->create());
    $cover = PackageImage::factory()->for($packages->last())->create(['sort_order' => 1]);
    PackageImage::factory()->for($packages->last())->create(['sort_order' => 2]);

    $response = $this->getJson($uri.'/'.$identifier.'?per_page=2');

    $response->assertOk()->assertJsonPath('data.id', $taxonomy->id)->assertJsonCount(2, 'data.packages')
        ->assertJsonPath('data.packages.0.id', $packages->last()->id)->assertJsonPath('pagination.total', 3)
        ->assertJsonPath('pagination.per_page', 2)->assertJsonPath('pagination.last_page', 2)
        ->assertJsonCount(1, 'data.packages.0.images')->assertJsonMissingPath('data.packages.0.itineraries')
        ->assertJsonPath('data.packages.0.cover_image_url', Storage::disk(config('filesystems.package_images_disk'))->url($cover->image));
    expect($response->json('pagination.next_page_url'))->toContain('per_page=2');

    $this->getJson($uri.'/'.$identifier.'?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data.packages')
        ->assertJsonPath('data.packages.0.id', $packages->first()->id)->assertJsonPath('pagination.current_page', 2);
    $this->getJson($uri.'/'.$identifier.'?page=100')->assertOk()->assertJsonPath('data.packages', [])
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
})->with([
    'category' => ['/api/categories', Category::class],
    'destination' => ['/api/destinations', Destination::class],
]);

test('taxonomy details reject invalid package pagination with 422', function (string $uri, string $model) {
    $taxonomy = $model::factory()->create();
    $identifier = $taxonomy->slug;

    $response = $this->getJson($uri.'/'.$identifier.'?page=0&per_page=101');

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'page' => 'The page field must be at least 1.',
        'per_page' => 'The per page field must not be greater than 100.',
    ]);
})->with([
    'category' => ['/api/categories', Category::class],
    'destination' => ['/api/destinations', Destination::class],
]);

test('recommended car categories include only active recommendations with the requested limit', function (string $query, int $expectedCount) {
    $categories = CarCategory::factory()->recommended()->count(11)->create();
    CarCategory::factory()->recommended()->inactive()->create();
    CarCategory::factory()->create();
    CarCategory::factory()->inactive()->create();

    $response = $this->getJson('/api/cars/categories/recommended'.$query);

    $response->assertOk()->assertJsonCount($expectedCount, 'data')
        ->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('message', 'Recommended car categories retrieved successfully.')
        ->assertJsonPath('data.0.status', true)->assertJsonPath('data.0.is_recommended', true);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_column($response->json('data'), 'id'))->toBe($categories->reverse()->take($expectedCount)->values()->modelKeys());
})->with([
    'default limit' => ['', 10],
    'custom limit' => ['?limit=2', 2],
    'minimum limit' => ['?limit=1', 1],
    'limit above available records' => ['?limit=20', 11],
]);

test('recommended car categories include the total car count for each category', function () {
    $emptyCategory = CarCategory::factory()->recommended()->create();
    $category = CarCategory::factory()->recommended()->create();
    Car::factory()->for($category)->count(2)->create();
    Car::factory()->for($category)->inactive()->create();
    Car::factory()->create();

    $response = $this->getJson('/api/cars/categories/recommended');

    $response->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $category->id)->assertJsonPath('data.0.car_count', 3)
        ->assertJsonPath('data.1.id', $emptyCategory->id)->assertJsonPath('data.1.car_count', 0)
        ->assertJsonMissingPath('data.0.cars_count')->assertJsonMissingPath('data.0.cars');
});

test('recommended car categories return 422 for invalid limits', function (string $query, string $message) {
    $response = $this->get('/api/cars/categories/recommended?'.$query);

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors(['limit' => $message]);
})->with([
    'zero' => ['limit=0', 'The limit field must be at least 1.'],
    'negative' => ['limit=-1', 'The limit field must be at least 1.'],
    'fraction' => ['limit=1.5', 'The limit field must be an integer.'],
    'text' => ['limit=abc', 'The limit field must be an integer.'],
    'empty' => ['limit=', 'The limit field must be an integer.'],
    'array' => ['limit[]=2', 'The limit field must be an integer.'],
]);

test('recommended car categories return an empty array when no active recommendations exist', function () {
    CarCategory::factory()->create();
    CarCategory::factory()->recommended()->inactive()->create();

    $response = $this->getJson('/api/cars/categories/recommended');

    $response->assertOk()->assertExactJson([
        'status' => true, 'data' => [], 'pagination' => null, 'message' => 'Recommended car categories retrieved successfully.',
    ]);
});

test('recommended car categories return a safe 500 response for database failures', function () {
    Schema::drop('car_categories');
    Exceptions::fake();

    $response = $this->getJson('/api/cars/categories/recommended');

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null,
        'message' => 'Unable to retrieve recommended car categories. Please try again later.',
    ]);
    Exceptions::assertReported(QueryException::class);
});

test('public car category collections expose boolean recommendation flags', function () {
    $ordinary = CarCategory::factory()->create();
    $recommended = CarCategory::factory()->recommended()->create();

    $response = $this->getJson('/api/cars/categories');

    $response->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $recommended->id)->assertJsonPath('data.0.is_recommended', true)
        ->assertJsonMissingPath('data.0.car_count')
        ->assertJsonPath('data.1.id', $ordinary->id)->assertJsonPath('data.1.is_recommended', false);
});

test('public car category details are retrieved by slug', function () {
    $category = CarCategory::factory()->create([
        'title' => 'Luxury SUV', 'slug' => 'luxury-suv',
        'meta_title' => 'Luxury SUV Rentals', 'image' => 'car-categories/luxury-suv.jpg',
    ]);

    $response = $this->get('/api/cars/categories/'.$category->slug);

    $response->assertOk()->assertJsonPath('status', true)
        ->assertJsonPath('data.id', $category->id)->assertJsonPath('data.title', 'Luxury SUV')
        ->assertJsonPath('data.slug', 'luxury-suv')->assertJsonPath('data.meta_title', 'Luxury SUV Rentals')
        ->assertJsonPath('data.image_url', $category->image_url)->assertJsonPath('data.is_recommended', false)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Car category retrieved successfully.');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
});

test('public car category details return 404 for inactive or missing slugs', function (string $slug) {
    CarCategory::factory()->inactive()->create(['slug' => 'inactive-category']);

    $response = $this->get('/api/cars/categories/'.$slug);

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
})->with(['inactive-category', 'missing-category']);

test('public car category details do not fall back to numeric IDs', function () {
    $category = CarCategory::factory()->create(['slug' => 'luxury-suv']);

    $response = $this->getJson('/api/cars/categories/'.$category->id);

    $response->assertNotFound()->assertJsonPath('message', 'Resource not found.');
});

test('public car category details return a safe 500 response for database failures', function () {
    Schema::drop('car_categories');
    Exceptions::fake();

    $response = $this->getJson('/api/cars/categories/luxury-suv');

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null,
        'message' => 'Unable to retrieve the car category. Please try again later.',
    ]);
    Exceptions::assertReported(QueryException::class);
});

test('car category filtering uses car categories and preserves filters in pagination links', function () {
    $category = CarCategory::factory()->recommended()->create(['image' => 'car-categories/suv.jpg']);
    $cars = Car::factory()->for($category)->count(3)->create();
    Car::factory()->create();
    Car::factory()->for($category)->inactive()->create();
    Category::factory()->create(['id' => $category->id]);

    $response = $this->getJson('/api/cars?car_category_id='.$category->id.'&per_page=2');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 3)
        ->assertJsonPath('data.0.id', $cars->last()->id)->assertJsonPath('data.0.car_category.id', $category->id)
        ->assertJsonPath('data.0.car_category.is_recommended', true)
        ->assertJsonPath('data.0.car_category.image_url', $category->image_url);
    parse_str(parse_url($response->json('pagination.next_page_url'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['car_category_id' => (string) $category->id, 'per_page' => '2', 'page' => '2']);
});

test('invalid or inactive car category filters receive 422', function (string $kind) {
    $inactive = CarCategory::factory()->inactive()->create();
    $value = match ($kind) {
        'inactive' => $inactive->id,
        'missing' => 999999,
        'invalid' => 'suv',
    };

    $response = $this->getJson('/api/cars?car_category_id='.$value);

    $response->assertUnprocessable()->assertJsonValidationErrors('car_category_id');
})->with(['inactive', 'missing', 'invalid']);

test('active cars in inactive car categories are hidden in lists and details', function () {
    $category = CarCategory::factory()->inactive()->create();
    $car = Car::factory()->for($category)->create();

    $this->getJson('/api/cars')->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
    $this->getJson('/api/cars/'.$car->id)->assertNotFound()->assertJsonPath('message', 'Resource not found.');
});

test('public car details expose the car and its category with existing image URLs', function () {
    $category = CarCategory::factory()->create();
    $car = Car::factory()->for($category)->create(['image' => 'cars/innova.jpg']);

    $response = $this->getJson('/api/cars/'.$car->id);

    $response->assertOk()->assertJsonPath('data.id', $car->id)->assertJsonPath('data.car_category.id', $category->id)
        ->assertJsonPath('data.passenger_seats', 6)->assertJsonPath('data.starting_rate', '18.50')
        ->assertJsonPath('data.image_url', $car->image_url)->assertJsonPath('pagination', null);
});

test('public database failures are reported with the existing safe error envelope', function (string $uri, string $table) {
    Schema::drop($table);
    Exceptions::fake();

    $response = $this->getJson($uri);

    $response->assertInternalServerError()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Unable to retrieve '.$table.'. Please try again later.');
    Exceptions::assertReported(QueryException::class);
})->with([
    'packages' => ['/api/packages', 'packages'], 'destinations' => ['/api/destinations', 'destinations'],
    'categories' => ['/api/categories', 'categories'], 'cars' => ['/api/cars', 'cars'],
]);

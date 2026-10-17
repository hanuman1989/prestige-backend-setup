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

test('public listings return only available records in descending ID order with pagination', function (string $uri, string $model, array $attributes) {
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
        Package::class => 4,
        Car::class => 3,
        default => 2,
    });

    $this->getJson($uri.'?page=2')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $records->first()->id)->assertJsonPath('pagination.current_page', 2);
})->with('public catalog listings');

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

test('top destinations include count_packages for only their related published packages', function () {
    $emptyDestination = Destination::factory()->popular()->create();
    $destination = Destination::factory()->popular()->create();
    $publishedPackages = Package::factory()->atStep(6)->count(2)->create();
    $draft = Package::factory()->atStep(5)->create();
    $inactive = Package::factory()->atStep(6)->inactive()->create();
    Package::factory()->atStep(6)->create();
    $destination->packages()->attach($publishedPackages->modelKeys());
    $destination->packages()->attach([$draft->id, $inactive->id]);

    $response = $this->getJson('/api/destinations/top');

    $response->assertOk()->assertJsonPath('data.0.id', $destination->id)
        ->assertJsonPath('data.0.count_packages', 2)->assertJsonPath('data.1.id', $emptyDestination->id)
        ->assertJsonPath('data.1.count_packages', 0)->assertJsonMissingPath('data.0.packages')
        ->assertJsonMissingPath('data.0.packages_count');
});

test('empty homepage collections return an unpaginated empty array', function (string $uri) {
    $response = $this->getJson($uri);

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination', null);
})->with(['/api/packages/special', '/api/destinations/top', '/api/categories/popular', '/api/cars/categories']);

test('unavailable details return the standard 404 envelope without authentication', function (string $uri, string $model, array $attributes) {
    $record = $model::factory()->inactive()->create($attributes);

    $response = $this->get($uri.'/'.$record->id);

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
})->with('public catalog listings');

test('missing details return the standard 404 envelope', function (string $uri, string $model, array $attributes) {
    $response = $this->getJson($uri.'/999999');

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
})->with('public catalog listings');

test('incomplete packages are absent from the public listing and return 404 on detail', function () {
    $package = Package::factory()->atStep(5)->create();

    $this->getJson('/api/packages')->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
    $this->getJson('/api/packages/'.$package->id)->assertNotFound()->assertJsonPath('message', 'Resource not found.');
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

    $response = $this->getJson('/api/packages/'.$package->id);

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

test('taxonomy details paginate only their published packages with bounded cover images', function (string $uri, string $model) {
    $taxonomy = $model::factory()->create(['image' => 'taxonomy/cover.jpg']);
    $other = $model::factory()->create();
    $packages = Package::factory()->atStep(6)->count(3)->create();
    $draft = Package::factory()->atStep(5)->create();
    $inactive = Package::factory()->atStep(6)->inactive()->create();
    $taxonomy->packages()->attach($packages->modelKeys());
    $taxonomy->packages()->attach([$draft->id, $inactive->id]);
    $other->packages()->attach(Package::factory()->atStep(6)->create());
    $cover = PackageImage::factory()->for($packages->last())->create(['sort_order' => 1]);
    PackageImage::factory()->for($packages->last())->create(['sort_order' => 2]);

    $response = $this->getJson($uri.'/'.$taxonomy->id.'?per_page=2');

    $response->assertOk()->assertJsonPath('data.id', $taxonomy->id)->assertJsonCount(2, 'data.packages')
        ->assertJsonPath('data.packages.0.id', $packages->last()->id)->assertJsonPath('pagination.total', 3)
        ->assertJsonPath('pagination.per_page', 2)->assertJsonPath('pagination.last_page', 2)
        ->assertJsonCount(1, 'data.packages.0.images')->assertJsonMissingPath('data.packages.0.itineraries')
        ->assertJsonPath('data.packages.0.cover_image_url', Storage::disk(config('filesystems.package_images_disk'))->url($cover->image));
    expect($response->json('pagination.next_page_url'))->toContain('per_page=2');

    $this->getJson($uri.'/'.$taxonomy->id.'?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data.packages')
        ->assertJsonPath('data.packages.0.id', $packages->first()->id)->assertJsonPath('pagination.current_page', 2);
    $this->getJson($uri.'/'.$taxonomy->id.'?page=100')->assertOk()->assertJsonPath('data.packages', [])
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
})->with([
    'category' => ['/api/categories', Category::class],
    'destination' => ['/api/destinations', Destination::class],
]);

test('taxonomy details reject invalid package pagination with 422', function (string $uri, string $model) {
    $taxonomy = $model::factory()->create();

    $response = $this->getJson($uri.'/'.$taxonomy->id.'?page=0&per_page=101');

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'page' => 'The page field must be at least 1.',
        'per_page' => 'The per page field must not be greater than 100.',
    ]);
})->with([
    'category' => ['/api/categories', Category::class],
    'destination' => ['/api/destinations', Destination::class],
]);

test('car category filtering uses car categories and preserves filters in pagination links', function () {
    $category = CarCategory::factory()->create(['image' => 'car-categories/suv.jpg']);
    $cars = Car::factory()->for($category)->count(3)->create();
    Car::factory()->create();
    Car::factory()->for($category)->inactive()->create();
    Category::factory()->create(['id' => $category->id]);

    $response = $this->getJson('/api/cars?car_category_id='.$category->id.'&per_page=2');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 3)
        ->assertJsonPath('data.0.id', $cars->last()->id)->assertJsonPath('data.0.car_category.id', $category->id)
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

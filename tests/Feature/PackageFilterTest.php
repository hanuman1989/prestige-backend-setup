<?php

use App\Models\Category;
use App\Models\Destination;
use App\Models\Package;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('public packages filter by either taxonomy and retain published visibility', function (string $model, string $relationship, string $field) {
    $taxonomy = $model::factory()->create();
    $otherTaxonomy = $model::factory()->create();
    $package = Package::factory()->atStep(6)->hasAttached($taxonomy, [], $relationship)->create();
    $package->{$relationship}()->attach($otherTaxonomy);
    Package::factory()->atStep(6)->hasAttached($otherTaxonomy, [], $relationship)->create();
    Package::factory()->atStep(5)->hasAttached($taxonomy, [], $relationship)->create();
    Package::factory()->atStep(6)->inactive()->hasAttached($taxonomy, [], $relationship)->create();

    $response = $this->getJson('/api/packages?'.$field.'='.$taxonomy->slug);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $package->id)
        ->assertJsonPath('pagination.total', 1);
})->with([
    'category' => [Category::class, 'categories', 'category_slug'],
    'destination' => [Destination::class, 'destinations', 'destination_slug'],
]);

test('combined package filters require both relationships and preserve filters in pagination links', function () {
    $category = Category::factory()->create();
    $destination = Destination::factory()->create();
    $packages = Package::factory()->atStep(6)->hasAttached($category)->hasAttached($destination)
        ->count(2)->create(['title' => 'Royal Rajasthan']);
    Package::factory()->atStep(6)->hasAttached($category)->create(['title' => 'Royal Rajasthan']);
    Package::factory()->atStep(6)->hasAttached($destination)->create(['title' => 'Royal Rajasthan']);
    Package::factory()->atStep(6)->hasAttached($category)->hasAttached($destination)->create(['title' => 'Other Tour']);
    $filters = ['category_slug' => $category->slug, 'destination_slug' => $destination->slug, 'destination_type' => 1, 'keyword' => 'Royal', 'status' => 1, 'per_page' => 1];

    $response = $this->getJson('/api/packages?'.http_build_query($filters));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $packages->last()->id)
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2);
    parse_str(parse_url($response->json('pagination.next_page_url'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(array_map(strval(...), $filters) + ['page' => '2']);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
});

test('empty package taxonomy filters leave the listing unfiltered', function () {
    $package = Package::factory()->atStep(6)->create();

    $response = $this->getJson('/api/packages?category_slug=&destination_slug=&destination_type=');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $package->id);
});

test('package taxonomy filters return an empty list when no package matches both', function () {
    $category = Category::factory()->create();
    $destination = Destination::factory()->create();
    Package::factory()->atStep(6)->hasAttached($category)->create();
    Package::factory()->atStep(6)->hasAttached($destination)->create();

    $response = $this->getJson('/api/packages?category_slug='.$category->slug.'&destination_slug='.$destination->slug);

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('invalid package taxonomy filters return 422', function (string $field, mixed $value, string $message) {
    $response = $this->get('/api/packages?'.http_build_query([$field => $value]));

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'missing category' => ['category_slug', 'missing-category', 'The selected category slug is invalid.'],
    'missing destination' => ['destination_slug', 'missing-destination', 'The selected destination slug is invalid.'],
    'oversized category' => ['category_slug', str_repeat('a', 256), 'The category slug field must not be greater than 255 characters.'],
    'oversized destination' => ['destination_slug', str_repeat('a', 256), 'The destination slug field must not be greater than 255 characters.'],
    'array category' => ['category_slug', ['tour'], 'The category slug field must be a string.'],
    'array destination' => ['destination_slug', ['jaipur'], 'The destination slug field must be a string.'],
]);

test('inactive package taxonomy filters return 422', function (string $model, string $field, string $message) {
    $taxonomy = $model::factory()->inactive()->create();

    $response = $this->getJson('/api/packages?'.$field.'='.$taxonomy->slug);

    $response->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
})->with([
    'category' => [Category::class, 'category_slug', 'The selected category slug is invalid.'],
    'destination' => [Destination::class, 'destination_slug', 'The selected destination slug is invalid.'],
]);

test('public packages filter by active destination type without duplicate packages', function (int $type) {
    $domestic = Destination::factory()->create();
    $international = Destination::factory()->international()->create();
    $otherDestination = Destination::factory()->create(['destination_type' => $type]);
    $domesticPackage = Package::factory()->atStep(6)->hasAttached($domestic)->create();
    $internationalPackage = Package::factory()->atStep(6)->hasAttached($international)->create();
    $mixedPackage = Package::factory()->atStep(6)->hasAttached($domestic)->hasAttached($international)
        ->hasAttached($otherDestination)->create();
    Package::factory()->atStep(6)->create();

    $response = $this->getJson('/api/packages?destination_type='.$type);

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('data.0.id', $mixedPackage->id)
        ->assertJsonPath('data.1.id', $type === 1 ? $domesticPackage->id : $internationalPackage->id);
})->with(['domestic' => [1], 'international' => [2]]);

test('package destination type filters exclude inactive destinations and unpublished packages', function (bool $destinationStatus, bool $packageStatus, int $step) {
    $destination = Destination::factory()->international()->create(['status' => $destinationStatus]);
    Package::factory()->atStep($step)->hasAttached($destination)->create(['status' => $packageStatus]);

    $response = $this->getJson('/api/packages?destination_type=2');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
})->with([
    'inactive destination' => [false, true, 6],
    'inactive package' => [true, false, 6],
    'incomplete package' => [true, true, 5],
]);

test('invalid package destination types return 422 without an accept header', function (mixed $value, string $message) {
    $response = $this->get('/api/packages?'.http_build_query(['destination_type' => $value]));

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors(['destination_type' => $message]);
})->with([
    'zero' => [0, 'The selected destination type is invalid.'],
    'unsupported type' => [3, 'The selected destination type is invalid.'],
    'text' => ['domestic', 'The destination type field must be an integer.'],
    'array' => [[1], 'The destination type field must be an integer.'],
]);

test('public packages scope by active destination type before pagination', function (int $destinationType) {
    $destination = Destination::factory()->create(['destination_type' => $destinationType]);
    $otherDestination = Destination::factory()->create(['destination_type' => $destinationType === 1 ? 2 : 1]);
    $inactiveDestination = Destination::factory()->inactive()->create(['destination_type' => $destinationType]);
    $otherMatchingDestination = Destination::factory()->create(['destination_type' => $destinationType]);
    $packages = Package::factory()->atStep(6)->hasAttached($destination)->count(2)->create();
    $packages->last()->destinations()->attach($otherMatchingDestination);
    Package::factory()->atStep(6)->hasAttached($otherDestination)->create();
    Package::factory()->atStep(6)->hasAttached($inactiveDestination)->create();
    Package::factory()->atStep(5)->hasAttached($destination)->create();
    Package::factory()->atStep(6)->inactive()->hasAttached($destination)->create();

    $response = $this->getJson('/api/packages?'.http_build_query(['destination_type' => $destinationType, 'per_page' => 1]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $packages->last()->id)
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2);
    parse_str(parse_url($response->json('pagination.next_page_url'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['destination_type' => (string) $destinationType, 'per_page' => '1', 'page' => '2']);
})->with(['domestic' => [1], 'international' => [2]]);

test('package destination type works with category and destination filters', function () {
    $category = Category::factory()->create();
    $domestic = Destination::factory()->create();
    $international = Destination::factory()->international()->create();
    $package = Package::factory()->atStep(6)->hasAttached($category)->hasAttached($domestic)->create();
    Package::factory()->atStep(6)->hasAttached($category)->hasAttached($international)->create();
    Package::factory()->atStep(6)->hasAttached($domestic)->create();

    $response = $this->getJson('/api/packages?'.http_build_query([
        'destination_type' => 1, 'category_slug' => $category->slug, 'destination_slug' => $domestic->slug,
    ]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $package->id)
        ->assertJsonPath('pagination.total', 1);
});

test('empty package destination type leaves domestic and international packages available', function () {
    $domestic = Destination::factory()->create();
    $international = Destination::factory()->international()->create();
    Package::factory()->atStep(6)->hasAttached($domestic)->create();
    Package::factory()->atStep(6)->hasAttached($international)->create();

    $response = $this->getJson('/api/packages?destination_type=');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2);
});

test('invalid package destination types return 422', function (mixed $value, string $message) {
    $response = $this->getJson('/api/packages?'.http_build_query(['destination_type' => $value]));

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors(['destination_type' => $message]);
})->with([
    'unsupported type' => [3, 'The selected destination type is invalid.'],
    'injection string' => ['1 OR 1=1', 'The destination type field must be an integer.'],
    'array type' => [[1], 'The destination type field must be an integer.'],
]);

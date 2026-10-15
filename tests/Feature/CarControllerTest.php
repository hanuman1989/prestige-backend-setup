<?php

use App\Models\AdminUser;
use App\Models\Car;
use App\Models\CarCategory;
use App\Models\User;
use Database\Seeders\CarSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

test('car endpoints return 401 without an admin bearer token', function (string $method, bool $single) {
    $car = Car::factory()->create();

    $response = $this->json($method, '/api/admin/cars'.($single ? '/'.$car->id : ''));

    $response->assertUnauthorized()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
    ]);
    $this->assertModelExists($car);
})->with(['index' => ['GET', false], 'store' => ['POST', false], 'show' => ['GET', true], 'update' => ['PATCH', true], 'destroy' => ['DELETE', true]]);

test('frontend tokens return 401 for car endpoints', function (string $method, bool $single) {
    $car = Car::factory()->create();
    $token = User::factory()->create()->createToken('test', ['*'])->plainTextToken;

    $response = $this->withToken($token)->json($method, '/api/admin/cars'.($single ? '/'.$car->id : ''));

    $response->assertUnauthorized();
    $this->assertModelExists($car);
})->with(['index' => ['GET', false], 'store' => ['POST', false], 'show' => ['GET', true], 'update' => ['PATCH', true], 'destroy' => ['DELETE', true]]);

test('inactive admins and tokens without admin abilities receive 403 for cars', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);
    $token = $admin->createToken('test', $abilities)->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/cars');

    $response->assertForbidden();
})->with(['inactive' => [false, ['admin']], 'wrong ability' => [true, ['frontend']]]);

test('the car gate permits active admins with either super-user flag only', function () {
    expect(Gate::forUser(AdminUser::factory()->make())->allows('manage-cars'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->superUser()->make())->allows('manage-cars'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->inactive()->make())->allows('manage-cars'))->toBeFalse();
    expect(Gate::forUser(User::factory()->make())->allows('manage-cars'))->toBeFalse();
});

test('car writes enforce their management gate and return 403', function () {
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    Gate::define('manage-cars', fn (Authenticatable $user): bool => false);

    $response = $this->withToken($token)->postJson('/api/admin/cars');

    $response->assertForbidden();
    $this->assertDatabaseCount('cars', 0);
});

test('active admins create cars with database defaults and category details', function (bool $isSuperUser) {
    $token = AdminUser::factory()->create(['is_super_user' => $isSuperUser])->createToken('test', ['admin'])->plainTextToken;
    $category = CarCategory::factory()->create(['title' => 'SUV']);

    $response = $this->withToken($token)->postJson('/api/admin/cars', [
        'car_category_id' => $category->id, 'title' => 'Toyota Innova', 'slug' => 'toyota-innova',
        'passenger_seats' => 6, 'starting_rate' => '18.50', 'image_url' => 'https://untrusted.test/car.jpg',
        'id' => 99999,
    ]);

    $response->assertCreated()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('data.car_category_id', $category->id)->assertJsonPath('data.car_category.title', 'SUV')
        ->assertJsonPath('data.passenger_seats', 6)->assertJsonPath('data.starting_rate', '18.50')
        ->assertJsonPath('data.luggage_bags', 0)->assertJsonPath('data.transmission', 'manual')
        ->assertJsonPath('data.has_ac', true)->assertJsonPath('data.rate_basis', 'per_km')
        ->assertJsonPath('data.is_featured', false)->assertJsonPath('data.status', true)
        ->assertJsonPath('data.image', null)->assertJsonPath('data.image_url', null)
        ->assertJsonPath('data.minimum_km_per_day', null)->assertJsonPath('data.extra_km_rate', null)
        ->assertJsonMissingPath('data.uploadedImage');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect($response->json('data.id'))->not->toBe(99999);
    $this->assertDatabaseHas('cars', ['title' => 'Toyota Innova', 'car_category_id' => $category->id, 'starting_rate' => 18.50, 'image' => null]);
})->with(['regular admin' => [false], 'super admin' => [true]]);

test('car updates persist editable fields and preserve omitted values and their own slug', function () {
    $car = Car::factory()->create(['title' => 'Original', 'slug' => 'original']);
    $newCategory = CarCategory::factory()->create(['title' => 'Luxury']);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    $payload = [
        'car_category_id' => $newCategory->id, 'slug' => 'original', 'brand' => 'Mercedes-Benz', 'model' => 'E Class',
        'description' => '<p>Comfortable travel.</p>', 'passenger_seats' => 4, 'luggage_bags' => 3,
        'transmission' => 'automatic', 'has_ac' => false, 'rate_basis' => 'per_day', 'starting_rate' => '2500.00',
        'minimum_km_per_day' => 250, 'driver_allowance_per_day' => '350.25', 'extra_km_rate' => '22.50',
        'inclusion' => 'Driver', 'exclusion' => 'Tolls', 'cancellation_policy' => '24 hours notice',
        'is_featured' => true, 'status' => false, 'meta_title' => 'Luxury rental', 'meta_keyword' => 'luxury, car',
        'meta_description' => 'Book a luxury car.',
    ];

    $response = $this->withToken($token)->patchJson('/api/admin/cars/'.$car->id, $payload);

    $response->assertOk()->assertJsonPath('data.title', 'Original')->assertJsonPath('data.car_category.title', 'Luxury');
    foreach ($payload as $field => $value) {
        $response->assertJsonPath('data.'.$field, $value);
    }
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'title' => 'Original', ...$payload]);
});

test('car details include nullable editing fields and the related category', function () {
    $category = CarCategory::factory()->create(['title' => 'Sedan']);
    $car = Car::factory()->for($category)->create(['meta_title' => 'Rental SEO']);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/cars/'.$car->id);

    $response->assertOk()->assertJsonPath('data.id', $car->id)->assertJsonPath('data.car_category.title', 'Sedan')
        ->assertJsonPath('data.meta_title', 'Rental SEO')->assertJsonPath('data.cancellation_policy', null)
        ->assertJsonPath('pagination', null);
});

test('cars accept each rate basis and schema boundary values', function (string $rateBasis) {
    $category = CarCategory::factory()->create();
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/admin/cars', [
        'car_category_id' => $category->id, 'title' => 'Boundary car', 'slug' => 'boundary-car',
        'passenger_seats' => 65535, 'luggage_bags' => 65535, 'minimum_km_per_day' => 65535,
        'starting_rate' => '99999999.99', 'extra_km_rate' => 0, 'driver_allowance_per_day' => 0, 'rate_basis' => $rateBasis,
    ]);

    $response->assertCreated()->assertJsonPath('data.starting_rate', '99999999.99')
        ->assertJsonPath('data.extra_km_rate', '0.00')->assertJsonPath('data.rate_basis', $rateBasis);
    $this->assertDatabaseHas('cars', ['slug' => 'boundary-car', 'rate_basis' => $rateBasis, 'minimum_km_per_day' => 65535]);
})->with(['per_km', 'per_day', 'per_trip']);

test('nullable car fields can be cleared without replacing omitted fields', function () {
    $car = Car::factory()->create(['brand' => 'Toyota', 'minimum_km_per_day' => 250, 'extra_km_rate' => 20, 'inclusion' => 'Driver']);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/cars/'.$car->id, [
        'brand' => null, 'minimum_km_per_day' => null, 'extra_km_rate' => null, 'inclusion' => null, 'slug' => 'updated-car',
    ]);

    $response->assertOk()->assertJsonPath('data.brand', null)->assertJsonPath('data.minimum_km_per_day', null)
        ->assertJsonPath('data.extra_km_rate', null)->assertJsonPath('data.inclusion', null)->assertJsonPath('data.passenger_seats', 6);
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'brand' => null, 'slug' => 'updated-car', 'extra_km_rate' => null]);
});

test('invalid car uploads return 422 without creating records or files', function (string $type) {
    Storage::fake('public');
    $category = CarCategory::factory()->create();
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    $image = match ($type) {
        'oversized' => UploadedFile::fake()->image('car.jpg')->size(2049),
        'gif' => UploadedFile::fake()->image('car.gif'),
        'text' => UploadedFile::fake()->createWithContent('car.jpg', 'Not an image.')->mimeType('text/plain'),
        'executable' => UploadedFile::fake()->image('car.php'),
    };

    $response = $this->withToken($token)->post('/api/admin/cars', [
        'car_category_id' => $category->id, 'title' => 'Car', 'slug' => 'car', 'passenger_seats' => 4, 'starting_rate' => 18, 'image' => $image,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->assertDatabaseCount('cars', 0);
    expect(Storage::disk('public')->allFiles('cars'))->toBe([]);
})->with(['oversized', 'gif', 'text', 'executable']);

test('missing required car fields return the standard 422 envelope', function () {
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/cars');

    $response->assertUnprocessable()->assertJsonPath('message', 'Validation failed.')
        ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null)
        ->assertJsonValidationErrors(['car_category_id', 'title', 'slug', 'passenger_seats', 'starting_rate']);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('cars', 0);
});

test('invalid car fields return 422 without changing persisted data', function (string $field, mixed $value, string $method) {
    $car = Car::factory()->create(['title' => 'Original']);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    $payload = [
        'car_category_id' => $car->car_category_id, 'title' => 'Changed', 'slug' => 'new-car',
        'passenger_seats' => 6, 'starting_rate' => '18.50', $field => $value,
    ];

    $response = $this->withToken($token)->json($method, '/api/admin/cars'.($method === 'PATCH' ? '/'.$car->id : ''), $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('cars', 1);
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'title' => 'Original']);
})->with([
    'missing category' => ['car_category_id', 99999],
    'null category' => ['car_category_id', null],
    'invalid slug' => ['slug', '../Car'],
    'long brand' => ['brand', str_repeat('a', 101)],
    'long model' => ['model', str_repeat('a', 151)],
    'no passenger seats' => ['passenger_seats', 0],
    'too many passenger seats' => ['passenger_seats', 65536],
    'fractional passenger seats' => ['passenger_seats', 2.5],
    'negative luggage' => ['luggage_bags', -1],
    'too much luggage' => ['luggage_bags', 65536],
    'invalid transmission' => ['transmission', 'electric'],
    'null transmission' => ['transmission', null],
    'invalid ac' => ['has_ac', 'yes'],
    'invalid rate basis' => ['rate_basis', 'per_hour'],
    'negative rate' => ['starting_rate', -1],
    'too large rate' => ['starting_rate', '100000000.00'],
    'excess rate precision' => ['starting_rate', '18.555'],
    'negative minimum distance' => ['minimum_km_per_day', -1],
    'too large minimum distance' => ['minimum_km_per_day', 65536],
    'negative allowance' => ['driver_allowance_per_day', -1],
    'too large allowance' => ['driver_allowance_per_day', '100000000.00'],
    'excess allowance precision' => ['driver_allowance_per_day', '10.123'],
    'negative extra km rate' => ['extra_km_rate', -1],
    'too large extra km rate' => ['extra_km_rate', '100000000.00'],
    'excess extra km precision' => ['extra_km_rate', '10.123'],
    'invalid featured flag' => ['is_featured', 'yes'],
    'invalid status' => ['status', 'active'],
    'array inclusion' => ['inclusion', []],
    'array exclusion' => ['exclusion', []],
    'array policy' => ['cancellation_policy', []],
    'client image path' => ['image', 'cars/untrusted.jpg'],
])->with(['create' => 'POST', 'update' => 'PATCH']);

test('duplicate car slugs return 422 on creation and update', function (string $method) {
    Car::factory()->create(['slug' => 'existing']);
    $car = Car::factory()->create(['slug' => 'original']);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->json($method, '/api/admin/cars'.($method === 'PATCH' ? '/'.$car->id : ''), [
        'car_category_id' => $car->car_category_id, 'title' => 'New', 'slug' => 'existing', 'passenger_seats' => 6, 'starting_rate' => 18,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['slug' => 'The slug has already been taken.']);
    $this->assertDatabaseCount('cars', 2);
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'slug' => 'original']);
})->with(['create' => 'POST', 'update' => 'PATCH']);

test('car listing combines filters before pagination and retains allowed filters in links', function () {
    $category = CarCategory::factory()->create();
    $cars = Car::factory()->count(2)->for($category)->create(['title' => 'Tour car', 'is_featured' => true, 'transmission' => 'automatic', 'rate_basis' => 'per_trip']);
    Car::factory()->for($category)->inactive()->create(['title' => 'Tour car', 'is_featured' => true]);
    Car::factory()->create(['title' => 'Tour car', 'is_featured' => true]);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/cars?keyword=Tour&status=1&is_featured=1&transmission=automatic&rate_basis=per_trip&car_category_id='.$category->id.'&per_page=1&unexpected=secret');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cars[1]->id)
        ->assertJsonPath('data.0.car_category.id', $category->id)->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.current_page', 1)->assertJsonPath('pagination.last_page', 2);
    expect($response->json('pagination.next_page_url'))->toContain('keyword=Tour', 'is_featured=1', 'car_category_id='.$category->id)->not->toContain('unexpected');
});

test('literal search characters cannot broaden car results', function (string $keyword) {
    Car::factory()->create(['title' => 'Plain car', 'slug' => 'plain-car']);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/cars?keyword='.urlencode($keyword));

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
})->with(['wildcard' => '%', 'underscore' => '_', 'escape' => '!', 'sql injection' => "' OR 1=1 --"]);

test('car status and featured filters accept false values', function () {
    $car = Car::factory()->inactive()->create(['is_featured' => false]);
    Car::factory()->create(['is_featured' => true]);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/cars?status=0&is_featured=0');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $car->id);
});

test('empty car pages return an empty list and nullable bounds', function () {
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/cars');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null)->assertJsonPath('pagination.per_page', 15);
});

test('invalid car filters and pagination return 422', function (string $field, mixed $value) {
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/cars?'.http_build_query([$field => $value]));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'page' => ['page', 0], 'per page' => ['per_page', 101], 'category' => ['car_category_id', 99999],
    'featured' => ['is_featured', 'yes'], 'transmission' => ['transmission', 'electric'], 'rate' => ['rate_basis', 'per_hour'],
]);

test('multipart car updates replace images and accept boolean strings using method override', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $car = Car::factory()->create(['image' => $path]);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/cars/'.$car->id, [
        '_method' => 'PATCH', 'image' => UploadedFile::fake()->image('new.png'), 'has_ac' => '0', 'status' => '0', 'is_featured' => '1',
    ], ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('data.has_ac', false)->assertJsonPath('data.status', false)->assertJsonPath('data.is_featured', true);
    $newPath = $response->json('data.image');
    Storage::disk('public')->assertExists($newPath);
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'image' => $newPath, 'has_ac' => false, 'is_featured' => true]);
});

test('direct multipart car updates return 422 instead of silently ignoring the body', function () {
    $car = Car::factory()->create(['title' => 'Original']);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->call('PATCH', '/api/admin/cars/'.$car->id, [], [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=test', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], "--test\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nChanged\r\n--test--\r\n");

    $response->assertUnprocessable()->assertJsonValidationErrors('_method');
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'title' => 'Original']);
});

test('missing cars return the standard 404 envelope', function (string $method) {
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->json($method, '/api/admin/cars/99999');

    $response->assertNotFound()->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.']);
})->with(['GET', 'PATCH', 'DELETE']);

test('optional car seeding preserves existing rental settings', function () {
    $category = CarCategory::factory()->create(['slug' => 'suv', 'title' => 'Custom SUV']);
    $car = Car::factory()->for($category)->create(['slug' => 'toyota-innova-crysta', 'starting_rate' => '25.00']);

    $this->seed(CarSeeder::class);

    $this->assertDatabaseCount('cars', 1);
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'starting_rate' => 25]);
    $this->assertDatabaseHas('car_categories', ['id' => $category->id, 'title' => 'Custom SUV']);
});

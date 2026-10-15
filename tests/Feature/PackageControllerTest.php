<?php

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Destination;
use App\Models\Package;
use App\Models\PackageImage;
use App\Models\PackageItinerary;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function packageAdminToken(): string
{
    return AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
}

/** @return array{title: string, slug: string, duration: string} */
function packageBasicPayload(): array
{
    return ['title' => 'Royal Rajasthan Escape', 'slug' => 'royal-rajasthan-escape', 'duration' => '6 Nights 7 Days'];
}

test('all package management endpoints require admin authentication and return 401', function (string $method, string $suffix) {
    $package = Package::factory()->create();
    $uri = str_replace('{id}', (string) $package->id, '/api/admin/packages'.$suffix);

    $this->json($method, $uri)->assertUnauthorized()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
    ]);

    $this->assertModelExists($package);
})->with([
    'index' => ['GET', ''], 'show' => ['GET', '/{id}'], 'delete' => ['DELETE', '/{id}'],
    'create basic' => ['POST', '/basic-details'], 'update basic' => ['PATCH', '/{id}/basic-details'],
    'relations' => ['PATCH', '/{id}/categories-destinations'],
    'itineraries' => ['PATCH', '/{id}/itineraries'], 'details' => ['PATCH', '/{id}/details'],
    'photos' => ['PATCH', '/{id}/photos'],
]);

test('frontend tokens cannot save packages and return 401', function () {
    $token = User::factory()->create()->createToken('test', ['admin', 'frontend'])->plainTextToken;

    $this->withToken($token)->postJson('/api/admin/packages/basic-details', packageBasicPayload())->assertUnauthorized();

    $this->assertDatabaseCount('packages', 0);
});

test('inactive admins and tokens without admin ability return 403', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);
    $token = $admin->createToken('test', $abilities)->plainTextToken;

    $this->withToken($token)->postJson('/api/admin/packages/basic-details', packageBasicPayload())->assertForbidden();

    $this->assertDatabaseCount('packages', 0);
})->with(['inactive' => [false, ['admin']], 'no ability' => [true, []]]);

test('package permissions are enforced and return 403', function () {
    $token = packageAdminToken();
    Gate::define('manage-packages', fn (Authenticatable $user): bool => false);

    $this->withToken($token)->postJson('/api/admin/packages/basic-details', packageBasicPayload())->assertForbidden();

    $this->assertDatabaseCount('packages', 0);
});

test('active admins with either super user flag have package permission', function (bool $superUser) {
    $admin = AdminUser::factory()->create(['is_super_user' => $superUser]);

    expect(Gate::forUser($admin)->allows('manage-packages'))->toBeTrue();
})->with([false, true]);

test('saves and resumes every step and keeps complete packages complete when editing', function () {
    Storage::fake('public');
    $token = packageAdminToken();
    $category = Category::factory()->create();
    $destination = Destination::factory()->create(['title' => 'Jaipur']);

    $created = $this->withToken($token)->postJson('/api/admin/packages/basic-details', [
        ...packageBasicPayload(), 'status' => '1', 'is_special_package' => '0',
        'meta_title' => 'Luxury Rajasthan', 'tour_best_time' => 'October to March',
    ])->assertCreated()->assertJsonPath('data.current_step', 2)->assertJsonPath('data.completed_steps', [1])
        ->assertJsonPath('data.is_complete', false)->assertJsonPath('data.status', true);
    $id = $created->json('data.id');
    $uri = '/api/admin/packages/'.$id;

    $this->withToken($token)->getJson($uri)->assertOk()->assertJsonPath('data.meta_title', 'Luxury Rajasthan')
        ->assertJsonPath('data.current_step', 2);
    $this->withToken($token)->patchJson($uri.'/categories-destinations', [
        'category_ids' => [$category->id], 'destination_ids' => [$destination->id],
    ])->assertOk()->assertJsonPath('data.current_step', 3)->assertJsonPath('data.category_ids', [$category->id])
        ->assertJsonPath('data.destination_ids', [$destination->id]);
    $this->withToken($token)->getJson($uri)->assertOk()->assertJsonPath('data.cities_covered', ['Jaipur']);

    $itinerary = $this->withToken($token)->post($uri.'/itineraries', [
        '_method' => 'PATCH', 'itineraries' => [[
            'title' => 'Day 1', 'description' => '<p>Arrival in Jaipur</p>',
            'sort_order' => '1', 'image' => UploadedFile::fake()->image('day.jpg'),
        ]],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.current_step', 4);
    $path = $itinerary->json('data.itineraries.0.image');
    Storage::disk('public')->assertExists($path);
    $this->withToken($token)->getJson($uri)->assertOk()->assertJsonPath('data.itineraries.0.image', $path);

    $this->withToken($token)->patchJson($uri.'/details', [
        'description' => '<p>Royal forts and palaces</p>', 'inclusion' => '<ul><li>Hotels</li></ul>',
        'title' => 'Ignored field',
    ])->assertOk()->assertJsonPath('data.current_step', 5)->assertJsonPath('data.title', 'Royal Rajasthan Escape');
    $this->withToken($token)->getJson($uri)->assertOk()->assertJsonPath('data.description', '<p>Royal forts and palaces</p>');

    $photos = $this->withToken($token)->post($uri.'/photos', [
        '_method' => 'PATCH', 'photos' => [
            ['image' => UploadedFile::fake()->image('second.jpg'), 'sort_order' => 2],
            ['image' => UploadedFile::fake()->image('cover.png'), 'sort_order' => 1],
        ],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.current_step', 6)
        ->assertJsonPath('data.completed_steps', [1, 2, 3, 4, 5])->assertJsonPath('data.is_complete', true)
        ->assertJsonPath('data.images.0.sort_order', 1);
    $cover = $photos->json('data.images.0.image_url');
    $photos->assertJsonPath('data.cover_image_url', $cover);
    $this->withToken($token)->getJson($uri)->assertOk()->assertJsonPath('data.cover_image_url', $cover)
        ->assertJsonPath('data.current_step', 6);
    $this->withToken($token)->patchJson($uri.'/basic-details', [
        ...packageBasicPayload(), 'title' => 'Updated title',
    ])->assertOk()->assertJsonPath('data.current_step', 6)->assertJsonPath('data.is_complete', true);
    $this->assertDatabaseCount('packages', 1);
    $this->assertDatabaseHas('packages', ['id' => $id, 'title' => 'Updated title', 'current_step' => 6]);
});

test('step one updates an existing body package id without creating another package', function () {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(4)->create(packageBasicPayload());

    $this->withToken($token)->postJson('/api/admin/packages/basic-details', [
        ...packageBasicPayload(), 'package_id' => $package->id, 'title' => 'Changed',
    ])->assertOk()->assertJsonPath('data.id', $package->id)->assertJsonPath('data.current_step', 4);

    $this->assertDatabaseCount('packages', 1);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'title' => 'Changed']);
});

test('step one rejects missing or mismatched package ids with 422', function (bool $missing) {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(2)->create();
    $other = Package::factory()->create();

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/basic-details', [
        ...packageBasicPayload(), 'package_id' => $missing ? 999999 : $other->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('package_id');

    $this->assertDatabaseCount('packages', 2);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'title' => $package->title, 'current_step' => 2]);
})->with([false, true]);

test('invalid basic details return 422 without changing progress or saved data', function (array $payload, array $errors) {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(2)->create();
    Package::factory()->create(['slug' => 'taken-slug']);

    $response = $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/basic-details', $payload);

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')->assertJsonValidationErrors($errors);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'title' => $package->title, 'current_step' => 2]);
})->with([
    'required' => [[], ['title', 'slug', 'duration']],
    'malformed slug' => [['title' => 'Tour', 'slug' => 'Invalid--slug', 'duration' => '7 Days'], ['slug']],
    'duplicate slug' => [['title' => 'Tour', 'slug' => 'taken-slug', 'duration' => '7 Days'], ['slug']],
    'invalid boolean' => [['title' => 'Tour', 'slug' => 'tour', 'duration' => '7 Days', 'status' => 'active'], ['status']],
    'client progress' => [['title' => 'Tour', 'slug' => 'tour', 'duration' => '7 Days', 'current_step' => 6], ['current_step']],
]);

test('rejects skipped unfinished steps with 422', function (string $suffix, array $payload) {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(2)->create();

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/'.$suffix, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('step')
        ->assertJsonPath('errors.step.0', 'Save the preceding steps before saving this step.');

    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 2, 'description' => null]);
    $this->assertDatabaseCount('package_itineraries', 0);
    $this->assertDatabaseCount('package_images', 0);
})->with([
    'itineraries' => ['itineraries', ['itineraries' => [['title' => 'Day 1', 'description' => 'Arrival', 'sort_order' => 1]]]],
    'details' => ['details', ['description' => 'Overview']],
    'photos' => ['photos', []],
]);

test('relations sync both selections and ignore other step fields', function () {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(6)->create();
    $oldCategory = Category::factory()->create();
    $oldDestination = Destination::factory()->create();
    $category = Category::factory()->create();
    $destination = Destination::factory()->create();
    $package->categories()->attach($oldCategory);
    $package->destinations()->attach($oldDestination);

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/categories-destinations', [
        'category_ids' => [$category->id], 'destination_ids' => [$destination->id], 'title' => 'Ignored',
    ])->assertOk()->assertJsonPath('data.category_ids', [$category->id])
        ->assertJsonPath('data.destination_ids', [$destination->id])->assertJsonPath('data.current_step', 6);

    $this->assertDatabaseMissing('category_package', ['package_id' => $package->id, 'category_id' => $oldCategory->id]);
    $this->assertDatabaseMissing('destination_package', ['package_id' => $package->id, 'destination_id' => $oldDestination->id]);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'title' => $package->title]);
});

test('invalid relationships return 422 and leave both selections and progress unchanged', function (string $invalid) {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(2)->create();
    $category = Category::factory()->create();
    $destination = Destination::factory()->create();
    $package->categories()->attach($category);
    $package->destinations()->attach($destination);
    $categories = match ($invalid) {
        'empty' => [], 'duplicates' => [$category->id, $category->id], 'missing' => [999999], 'wrong type' => 'bad',
    };

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/categories-destinations', [
        'category_ids' => $categories, 'destination_ids' => [$destination->id],
    ])->assertUnprocessable()->assertJsonPath('message', 'Validation failed.');

    $this->assertDatabaseHas('category_package', ['package_id' => $package->id, 'category_id' => $category->id]);
    $this->assertDatabaseHas('destination_package', ['package_id' => $package->id, 'destination_id' => $destination->id]);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 2]);
})->with(['empty', 'duplicates', 'missing', 'wrong type']);

test('itinerary updates retain omitted items and images and use stable ordering', function () {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(3)->create();
    $first = PackageItinerary::factory()->for($package)->create(['sort_order' => 2, 'image' => 'packages/itineraries/retained.jpg']);
    $omitted = PackageItinerary::factory()->for($package)->create(['sort_order' => 1]);
    $deleted = PackageItinerary::factory()->for($package)->create();

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/itineraries', [
        'itineraries' => [
            ['id' => $first->id, 'title' => 'Updated', 'description' => 'New plan', 'sort_order' => 1],
            ['title' => 'New day', 'description' => 'Explore', 'sort_order' => 2],
        ],
        'deleted_itinerary_ids' => [$deleted->id],
    ])->assertOk()->assertJsonPath('data.itineraries.0.id', $first->id)
        ->assertJsonPath('data.itineraries.1.id', $omitted->id)
        ->assertJsonPath('data.itineraries.0.image', 'packages/itineraries/retained.jpg')
        ->assertJsonPath('data.current_step', 4);

    $this->assertDatabaseCount('package_itineraries', 3);
    $this->assertDatabaseHas('package_itineraries', ['id' => $first->id, 'title' => 'Updated', 'sort_order' => 1]);
    $this->assertModelMissing($deleted);
    $this->assertModelExists($omitted);
});

test('foreign nested ids are rejected with 422 for updates and removals', function (string $kind, bool $deleting) {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(6)->create();
    $foreign = $kind === 'itineraries' ? PackageItinerary::factory()->create() : PackageImage::factory()->create();
    $own = PackageItinerary::factory()->for($package)->create();
    $payload = $kind === 'itineraries'
        ? ['itineraries' => [['id' => $deleting ? $own->id : $foreign->id, 'title' => 'Day 1', 'description' => 'Arrival', 'sort_order' => 1]]]
        : ['photos' => $deleting ? [] : [['id' => $foreign->id, 'sort_order' => 1]]];
    $field = $kind === 'itineraries' ? 'deleted_itinerary_ids' : 'deleted_image_ids';
    if ($deleting) {
        $payload[$field] = [$foreign->id];
    }

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/'.$kind, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($deleting ? $field.'.0' : ($kind === 'itineraries' ? 'itineraries.0.id' : 'photos.0.id'));

    $this->assertModelExists($foreign);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 6]);
})->with([
    'itinerary update' => ['itineraries', false], 'itinerary removal' => ['itineraries', true],
    'photo update' => ['photos', false], 'photo removal' => ['photos', true],
]);

test('invalid step data returns 422 without advancing progress', function (int $step, string $suffix, array $payload, string $error) {
    $token = packageAdminToken();
    $package = Package::factory()->atStep($step)->create();

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/'.$suffix, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($error);

    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => $step]);
    $this->assertDatabaseCount('package_itineraries', 0);
    $this->assertDatabaseCount('package_images', 0);
})->with([
    'empty itineraries' => [3, 'itineraries', ['itineraries' => []], 'itineraries'],
    'missing title' => [3, 'itineraries', ['itineraries' => [['description' => 'Arrival', 'sort_order' => 1]]], 'itineraries.0.title'],
    'bad order' => [3, 'itineraries', ['itineraries' => [['title' => 'Day 1', 'description' => 'Arrival', 'sort_order' => 0]]], 'itineraries.0.sort_order'],
    'missing description' => [4, 'details', [], 'description'],
    'no photos' => [5, 'photos', [], 'photos'],
    'storage path' => [5, 'photos', ['photos' => [['image' => 'other/photo.jpg', 'sort_order' => 1]]], 'photos.0.image'],
    'extra nested key' => [3, 'itineraries', ['itineraries' => [['title' => 'Day 1', 'description' => 'Arrival', 'sort_order' => 1, 'package_id' => 999]]], 'itineraries.0'],
]);

test('upload formats and sizes are enforced with 422', function (string $kind, string $invalid) {
    Storage::fake('public');
    $token = packageAdminToken();
    $package = Package::factory()->atStep(5)->create();
    $source = UploadedFile::fake()->image('real.jpg');
    $invalidContent = UploadedFile::fake()->createWithContent('image.jpg', 'not an image');
    $image = match ($invalid) {
        'extension' => new UploadedFile($source->getPathname(), 'image.txt', null, null, true),
        'content' => new UploadedFile($invalidContent->getPathname(), 'image.jpg', null, null, true),
        'size' => UploadedFile::fake()->image('large.png')->size(2049),
    };
    $payload = $kind === 'photos'
        ? ['photos' => [['image' => $image, 'sort_order' => 1]]]
        : ['itineraries' => [['title' => 'Day 1', 'description' => 'Arrival', 'image' => $image, 'sort_order' => 1]]];

    $this->withToken($token)->post('/api/admin/packages/'.$package->id.'/'.$kind, [
        '_method' => 'PATCH', ...$payload,
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors($kind.'.0.image');

    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 5]);
    expect(Storage::disk('public')->allFiles('packages'))->toBe([]);
})->with([
    'photo extension' => ['photos', 'extension'], 'photo content' => ['photos', 'content'], 'photo size' => ['photos', 'size'],
    'itinerary extension' => ['itineraries', 'extension'], 'itinerary content' => ['itineraries', 'content'], 'itinerary size' => ['itineraries', 'size'],
]);

test('photos reorder without uploads retain omitted images and change the cover', function () {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(6)->create();
    $first = PackageImage::factory()->for($package)->create(['sort_order' => 1]);
    $second = PackageImage::factory()->for($package)->create(['sort_order' => 2]);
    $omitted = PackageImage::factory()->for($package)->create(['sort_order' => 3]);

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/photos', [
        'photos' => [['id' => $first->id, 'sort_order' => 2], ['id' => $second->id, 'sort_order' => 1]],
    ])->assertOk()->assertJsonPath('data.images.0.id', $second->id)->assertJsonPath('data.images.2.id', $omitted->id)
        ->assertJsonPath('data.cover_image_url', Storage::disk('public')->url($second->image))
        ->assertJsonPath('data.current_step', 6);

    $this->assertDatabaseCount('package_images', 3);
    $this->assertDatabaseHas('package_images', ['id' => $first->id, 'image' => $first->image, 'sort_order' => 2]);
});

test('removing the last photo returns 422 and leaves complete packages and photos intact', function () {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(6)->create();
    $photo = PackageImage::factory()->for($package)->create();

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/photos', [
        'deleted_image_ids' => [$photo->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('photos');

    $this->assertModelExists($photo);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 6]);
});

test('saving and deleting the same nested item returns 422', function (string $kind) {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(6)->create();
    $item = $kind === 'photos'
        ? PackageImage::factory()->for($package)->create()
        : PackageItinerary::factory()->for($package)->create();
    $deletedField = $kind === 'photos' ? 'deleted_image_ids' : 'deleted_itinerary_ids';
    $payload = $kind === 'photos'
        ? ['photos' => [['id' => $item->id, 'sort_order' => 1]]]
        : ['itineraries' => [['id' => $item->id, 'title' => 'Day 1', 'description' => 'Arrival', 'sort_order' => 1]]];

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/'.$kind, [
        ...$payload, $deletedField => [$item->id],
    ])->assertUnprocessable()->assertJsonValidationErrors($deletedField);

    $this->assertModelExists($item);
})->with(['photos', 'itineraries']);

test('admin listing groups keyword conditions with inactive status and returns filtered pagination', function () {
    $token = packageAdminToken();
    Package::factory()->create(['title' => 'Royal active', 'slug' => 'royal-active']);
    $inactive = Package::factory()->inactive()->create(['title' => 'Unrelated', 'slug' => 'royal-inactive']);
    $newer = Package::factory()->inactive()->create(['title' => 'Royal second', 'slug' => 'second-tour']);
    Package::factory()->inactive()->create(['title' => 'Other', 'slug' => 'other-tour']);

    $response = $this->withToken($token)->getJson('/api/admin/packages?keyword=Royal&status=0&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.current_step', 1)->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.last_page', 2)->assertJsonPath('pagination.per_page', 1);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect($response->json('pagination.next_page_url'))->toContain('keyword=Royal', 'status=0', 'per_page=1');
    $this->withToken($token)->getJson('/api/admin/packages?keyword=Royal&status=0&per_page=1&page=2')
        ->assertOk()->assertJsonPath('data.0.id', $inactive->id);
    $this->withToken($token)->getJson('/api/admin/packages?keyword=Royal&status=0&page=3')
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('keyword wildcards are literal and pagination is validated', function (string $query, bool $valid) {
    $token = packageAdminToken();
    Package::factory()->create(['title' => 'Normal tour']);

    $response = $this->withToken($token)->getJson('/api/admin/packages?'.$query);

    if ($valid) {
        $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
    } else {
        $response->assertUnprocessable()->assertJsonPath('message', 'Validation failed.');
    }
})->with([
    'literal percent' => ['keyword=%25', true], 'literal underscore' => ['keyword=_', true],
    'zero page' => ['page=0', false], 'oversized page' => ['per_page=101', false],
    'invalid status' => ['status=active', false],
]);

test('public listing excludes incomplete and inactive packages', function () {
    Package::factory()->atStep(5)->create();
    Package::factory()->inactive()->atStep(6)->create();
    $published = Package::factory()->atStep(6)->create();
    $image = PackageImage::factory()->for($published)->create();
    $destination = Destination::factory()->create(['title' => 'Jaipur']);
    $published->destinations()->attach($destination);

    $this->getJson('/api/packages')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $published->id)->assertJsonPath('data.0.cities_covered', ['Jaipur'])
        ->assertJsonPath('data.0.cover_image_url', Storage::disk('public')->url($image->image))
        ->assertJsonPath('pagination.total', 1);
});

test('selection options can be retrieved beyond the first page using existing endpoints', function () {
    $token = packageAdminToken();
    Category::factory()->count(16)->create();
    Destination::factory()->count(16)->create();

    $this->withToken($token)->getJson('/api/admin/categories?page=2')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('pagination.total', 16);
    $this->withToken($token)->getJson('/api/admin/destinations?page=2')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('pagination.total', 16);
});

test('missing package routes return 404', function () {
    $token = packageAdminToken();

    $this->withToken($token)->getJson('/api/admin/packages/999999')->assertNotFound()->assertJsonPath('message', 'Resource not found.');
});

test('raw multipart PATCH uploads return 422 instead of reporting unparsed data as saved', function (string $kind) {
    $token = packageAdminToken();
    $package = Package::factory()->atStep(5)->create();
    $photo = PackageImage::factory()->for($package)->create();
    $body = "--package-boundary\r\nContent-Disposition: form-data; name=\"sort_order\"\r\n\r\n2\r\n--package-boundary--\r\n";

    $this->call('PATCH', '/api/admin/packages/'.$package->id.'/'.$kind, [], [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=package-boundary',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], $body)->assertUnprocessable()->assertJsonValidationErrors([
        '_method' => 'Send form-data updates using POST with _method set to PUT or PATCH, or send a JSON body with PUT or PATCH.',
    ]);

    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 5]);
    $this->assertDatabaseHas('package_images', ['id' => $photo->id, 'sort_order' => 1]);
})->with(['itineraries', 'photos']);

test('listing selects a stable cover for each package and show returns the complete ordered gallery', function () {
    $token = packageAdminToken();
    $older = Package::factory()->atStep(6)->create();
    $newer = Package::factory()->atStep(6)->create();
    $olderCover = PackageImage::factory()->for($older)->create(['sort_order' => 1]);
    $newerCover = PackageImage::factory()->for($newer)->create(['sort_order' => 1]);
    $tied = PackageImage::factory()->for($newer)->create(['sort_order' => 1]);
    $last = PackageImage::factory()->for($newer)->create(['sort_order' => 2]);

    $this->withToken($token)->getJson('/api/admin/packages')->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.cover_image_url', Storage::disk('public')->url($newerCover->image))
        ->assertJsonPath('data.1.cover_image_url', Storage::disk('public')->url($olderCover->image))
        ->assertJsonCount(1, 'data.0.images')->assertJsonCount(1, 'data.1.images');
    $this->withToken($token)->getJson('/api/admin/packages/'.$newer->id)->assertOk()->assertJsonCount(3, 'data.images')
        ->assertJsonPath('data.images.0.id', $newerCover->id)->assertJsonPath('data.images.1.id', $tied->id)
        ->assertJsonPath('data.images.2.id', $last->id);
});

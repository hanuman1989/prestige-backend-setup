<?php

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\User;
use Database\Seeders\DestinationSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

test('destination endpoints require an admin bearer token and return 401', function (string $method, bool $singleDestination) {
    $destination = Destination::factory()->create();
    $uri = '/api/admin/destinations'.($singleDestination ? '/'.$destination->id : '');

    $response = $this->json($method, $uri, ['destination_type' => 1, 'title' => 'New Destination', 'slug' => 'new-destination']);

    $response->assertUnauthorized()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
    ]);
    $this->assertDatabaseCount('destinations', 1);
    $this->assertModelExists($destination);
})->with([
    'index' => ['GET', false],
    'store' => ['POST', false],
    'show' => ['GET', true],
    'update' => ['PATCH', true],
    'destroy' => ['DELETE', true],
]);

test('frontend bearer tokens cannot access destination endpoints and return 401', function (string $method, bool $singleDestination) {
    $destination = Destination::factory()->create();
    $user = User::factory()->create();
    $token = $user->createToken('test', ['*'])->plainTextToken;
    $uri = '/api/admin/destinations'.($singleDestination ? '/'.$destination->id : '');

    $response = $this->withToken($token)->json($method, $uri, ['destination_type' => 1, 'title' => 'New Destination', 'slug' => 'new-destination']);

    $response->assertUnauthorized();
    $this->assertDatabaseCount('destinations', 1);
    $this->assertModelExists($destination);
})->with([
    'index' => ['GET', false],
    'store' => ['POST', false],
    'show' => ['GET', true],
    'update' => ['PATCH', true],
    'destroy' => ['DELETE', true],
]);

test('inactive admins cannot access destinations and receive 403', function () {
    $admin = AdminUser::factory()->inactive()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations');

    $response->assertForbidden()->assertJsonPath('message', 'Admin account is inactive.');
});

test('admin tokens without the admin ability receive 403 for destinations', function () {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['frontend'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations');

    $response->assertForbidden();
});

test('the destination gate allows active admins with either super-user flag', function () {
    $regularAdmin = AdminUser::factory()->make();
    $superAdmin = AdminUser::factory()->superUser()->make();
    $inactiveAdmin = AdminUser::factory()->inactive()->make();
    $frontendUser = User::factory()->make();

    expect(Gate::forUser($regularAdmin)->allows('manage-destinations'))->toBeTrue();
    expect(Gate::forUser($superAdmin)->allows('manage-destinations'))->toBeTrue();
    expect(Gate::forUser($inactiveAdmin)->allows('manage-destinations'))->toBeFalse();
    expect(Gate::forUser($frontendUser)->allows('manage-destinations'))->toBeFalse();
});

test('destination endpoints enforce the management gate and return 403', function () {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Gate::define('manage-destinations', fn (Authenticatable $user): bool => false);

    $response = $this->withToken($token)->postJson('/api/admin/destinations', ['destination_type' => 1, 'title' => 'New Destination', 'slug' => 'new-destination']);

    $response->assertForbidden();
    $this->assertDatabaseCount('destinations', 0);
});

test('active admins can create destinations with SEO fields and active defaults', function (bool $isSuperUser) {
    $this->travelTo('2026-10-11 12:00:00');
    $admin = AdminUser::factory()->create(['is_super_user' => $isSuperUser]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $payload = [
        'destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours',
        'meta_title' => 'Royal Heritage', 'meta_keyword' => 'forts, palaces',
        'meta_description' => 'Travel through Rajasthan.',
        'description' => '<p>Discover <strong>royal forts</strong>.</p>',
        'image_url' => 'https://untrusted.test/photo.jpg',
        'created_at' => '2000-01-01 00:00:00',
        'updated_at' => '2000-01-01 00:00:00',
    ];

    $response = $this->withToken($token)->postJson('/api/admin/destinations', $payload);

    $response->assertCreated()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('data.title', 'Heritage Tours')->assertJsonPath('data.slug', 'heritage-tours')
        ->assertJsonPath('data.meta_title', 'Royal Heritage')->assertJsonPath('data.meta_keyword', 'forts, palaces')
        ->assertJsonPath('data.meta_description', 'Travel through Rajasthan.')
        ->assertJsonPath('data.description', '<p>Discover <strong>royal forts</strong>.</p>')
        ->assertJsonPath('data.destination_type', 1)->assertJsonPath('data.is_most_popular', false)
        ->assertJsonPath('data.status', true)->assertJsonPath('data.image', null)->assertJsonPath('data.image_url', asset('image-not-available.png'));
    $this->assertDatabaseHas('destinations', [
        'destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'status' => 1,
        'created_at' => '2026-10-11 12:00:00', 'updated_at' => '2026-10-11 12:00:00',
        'meta_title' => 'Royal Heritage', 'meta_keyword' => 'forts, palaces',
        'meta_description' => 'Travel through Rajasthan.', 'description' => $payload['description'], 'image' => null,
    ]);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('data')))->toBe([
        'id', 'title', 'slug', 'meta_title', 'meta_keyword', 'meta_description', 'image', 'image_url',
        'banner', 'banner_url',
        'description', 'destination_type', 'is_most_popular', 'status', 'created_at', 'updated_at',
    ]);
})->with(['regular admin' => [false], 'super admin' => [true]]);

test('destinations accept multipart boolean status strings on creation', function (string $status, bool $expectedStatus) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/destinations', [
        'destination_type' => 1, 'title' => 'New Destination', 'slug' => 'new-destination', 'status' => $status,
    ], ['Accept' => 'application/json']);

    $response->assertCreated()->assertJsonPath('data.status', $expectedStatus);
    $this->assertDatabaseHas('destinations', ['slug' => 'new-destination', 'status' => $expectedStatus]);
})->with(['active' => ['1', true], 'inactive' => ['0', false]]);

test('destinations accept domestic and international types with multipart popularity flags', function (string $type, string $popular, bool $expectedPopular) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/destinations', [
        'title' => 'Bali', 'slug' => 'bali', 'destination_type' => $type, 'is_most_popular' => $popular,
    ]);

    $response->assertCreated()->assertJsonPath('data.destination_type', (int) $type)
        ->assertJsonPath('data.is_most_popular', $expectedPopular);
    $this->assertDatabaseHas('destinations', [
        'slug' => 'bali', 'destination_type' => (int) $type, 'is_most_popular' => $expectedPopular,
    ]);
})->with([
    'domestic popular' => ['1', '1', true],
    'international not popular' => ['2', '0', false],
]);

test('partial multipart updates change destination type and boolean flags', function (string $popular, bool $expectedPopular) {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/destinations/'.$destination->id, [
        '_method' => 'PATCH', 'destination_type' => '2', 'is_most_popular' => $popular, 'status' => '0',
        'created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-01 00:00:00',
    ]);

    $response->assertOk()->assertJsonPath('data.destination_type', 2)
        ->assertJsonPath('data.is_most_popular', $expectedPopular)->assertJsonPath('data.status', false);
    $this->assertDatabaseHas('destinations', [
        'id' => $destination->id, 'title' => $destination->title,
        'destination_type' => 2, 'is_most_popular' => $expectedPopular, 'status' => false,
        'created_at' => $destination->getRawOriginal('created_at'),
    ]);
    expect($destination->refresh()->getRawOriginal('updated_at'))->not->toBe('2000-01-01 00:00:00');
})->with(['popular' => ['1', true], 'not popular' => ['0', false]]);

test('invalid destination type and popularity updates return 422 without changing the destination', function (array $payload, array $errors) {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->popular()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/destinations/'.$destination->id, $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors($errors);
    $this->assertDatabaseHas('destinations', [
        'id' => $destination->id, 'destination_type' => 1, 'is_most_popular' => true,
    ]);
})->with([
    'null type' => [['destination_type' => null], ['destination_type' => 'The destination type field is required.']],
    'unknown type' => [['destination_type' => 3], ['destination_type' => 'The selected destination type is invalid.']],
    'fractional type' => [['destination_type' => 1.5], ['destination_type' => 'The destination type field must be an integer.']],
    'invalid popularity' => [['is_most_popular' => 'yes'], ['is_most_popular' => 'The is most popular field must be true or false.']],
]);

test('destination details expose editing fields and nullable values', function () {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->inactive()->create([
        'meta_title' => 'SEO Title', 'meta_keyword' => 'travel, luxury', 'meta_description' => 'SEO description',
    ]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations/'.$destination->id);

    $response->assertOk()->assertJsonPath('data.id', $destination->id)->assertJsonPath('data.title', $destination->title)
        ->assertJsonPath('data.slug', $destination->slug)->assertJsonPath('data.meta_title', 'SEO Title')
        ->assertJsonPath('data.meta_keyword', 'travel, luxury')->assertJsonPath('data.meta_description', 'SEO description')
        ->assertJsonPath('data.status', false)->assertJsonPath('data.image_url', asset('image-not-available.png'))
        ->assertJsonPath('data.description', $destination->description)->assertJsonPath('pagination', null);
});

test('destination updates are partial and can retain their own unique slug', function () {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->international()->popular()->create([
        'title' => 'Original Title', 'slug' => 'original-slug', 'meta_title' => 'Old SEO', 'description' => 'Keep this description.',
    ]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/destinations/'.$destination->id, [
        'title' => 'Updated Title', 'slug' => 'original-slug', 'meta_title' => null, 'status' => '0',
    ]);

    $response->assertOk()->assertJsonPath('data.title', 'Updated Title')->assertJsonPath('data.slug', 'original-slug')
        ->assertJsonPath('data.meta_title', null)->assertJsonPath('data.description', 'Keep this description.')
        ->assertJsonPath('data.destination_type', 2)->assertJsonPath('data.is_most_popular', true)
        ->assertJsonPath('data.status', false);
    $this->assertDatabaseHas('destinations', [
        'id' => $destination->id, 'destination_type' => 2, 'is_most_popular' => true, 'title' => 'Updated Title', 'slug' => 'original-slug',
        'meta_title' => null, 'description' => 'Keep this description.', 'status' => 0,
    ]);
});

test('destinations can change to another unused slug', function () {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->putJson('/api/admin/destinations/'.$destination->id, ['slug' => 'new-unique-slug']);

    $response->assertOk()->assertJsonPath('data.slug', 'new-unique-slug');
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'slug' => 'new-unique-slug']);
});

test('duplicate destination slugs return 422 on creation without adding a destination', function () {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/admin/destinations', ['destination_type' => 1, 'title' => 'New Destination', 'slug' => $destination->slug]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['slug' => 'The slug has already been taken.']);
    $this->assertDatabaseCount('destinations', 1);
});

test('duplicate destination slugs return 422 on update without changing the destination', function () {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['slug' => 'original-slug']);
    $otherDestination = Destination::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/destinations/'.$destination->id, ['slug' => $otherDestination->slug]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['slug' => 'The slug has already been taken.']);
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'slug' => 'original-slug']);
});

test('missing required destination fields return the standard 422 validation envelope', function () {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/destinations', []);

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors([
            'title' => 'The title field is required.',
            'slug' => 'The slug field is required.',
            'destination_type' => 'The destination type field is required.',
        ]);
    $this->assertDatabaseCount('destinations', 0);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
});

test('invalid destination fields return 422 without persistence', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $payload = array_replace(['destination_type' => 1, 'title' => 'New Destination', 'slug' => 'new-destination'], $changes);

    $response = $this->withToken($token)->postJson('/api/admin/destinations', $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('destinations', 0);
})->with([
    'oversized title' => [['destination_type' => 1, 'title' => str_repeat('a', 256)], 'title'],
    'array title' => [['destination_type' => 1, 'title' => ['New']], 'title'],
    'oversized slug' => [['slug' => str_repeat('a', 256)], 'slug'],
    'uppercase slug' => [['slug' => 'Heritage-Tours'], 'slug'],
    'spaces in slug' => [['slug' => 'heritage tours'], 'slug'],
    'path in slug' => [['slug' => '../heritage'], 'slug'],
    'double hyphen' => [['slug' => 'heritage--tours'], 'slug'],
    'oversized meta title' => [['meta_title' => str_repeat('a', 256)], 'meta_title'],
    'array meta keyword' => [['meta_keyword' => ['heritage']], 'meta_keyword'],
    'oversized meta keyword' => [['meta_keyword' => str_repeat('a', 65536)], 'meta_keyword'],
    'array meta description' => [['meta_description' => ['heritage']], 'meta_description'],
    'oversized meta description' => [['meta_description' => str_repeat('a', 65536)], 'meta_description'],
    'array description' => [['description' => ['heritage']], 'description'],
    'invalid status' => [['status' => 'active'], 'status'],
    'client-provided image path' => [['image' => 'destinations/untrusted.jpg'], 'image'],
    'invalid destination type' => [['destination_type' => 3], 'destination_type'],
    'zero destination type' => [['destination_type' => 0], 'destination_type'],
    'fractional destination type' => [['destination_type' => 1.5], 'destination_type'],
    'label destination type' => [['destination_type' => 'Domestic'], 'destination_type'],
    'null destination type' => [['destination_type' => null], 'destination_type'],
    'invalid popularity' => [['is_most_popular' => 'yes'], 'is_most_popular'],
]);

test('invalid partial updates receive 422 and retain the original destination', function () {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['destination_type' => 1, 'title' => 'Original Title', 'slug' => 'original-slug']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/destinations/'.$destination->id, ['destination_type' => 1, 'title' => '', 'slug' => '']);

    $response->assertUnprocessable()->assertJsonValidationErrors(['title', 'slug']);
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'destination_type' => 1, 'title' => 'Original Title', 'slug' => 'original-slug']);
});

test('destination images accept JPEG, PNG and WebP with generated filenames', function (string $extension) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/destinations', [
        'destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('original.'.$extension),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $path = $response->json('data.image');
    expect($path)->toStartWith('destinations/')->not->toContain('original');
    $response->assertJsonPath('data.image_url', Storage::disk('public')->url($path))->assertJsonMissingPath('data.uploadedImage');
    Storage::disk('public')->assertExists($path);
    $this->assertDatabaseHas('destinations', ['slug' => 'heritage-tours', 'image' => $path]);
})->with(['jpg' => ['jpg'], 'jpeg' => ['jpeg'], 'png' => ['png'], 'webp' => ['webp']]);

test('invalid destination images return 422 and create neither records nor files', function (string $type) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $image = match ($type) {
        'oversized' => UploadedFile::fake()->image('photo.jpg')->size(2049),
        'gif' => UploadedFile::fake()->image('photo.gif'),
        'disguised text' => UploadedFile::fake()->createWithContent('photo.jpg', 'This is not an image.')->mimeType('text/plain'),
        'executable extension' => UploadedFile::fake()->image('photo.php'),
    };

    $response = $this->withToken($token)->post('/api/admin/destinations', [
        'destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => $image,
    ], ['Accept' => 'application/json']);

    $response->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->assertDatabaseCount('destinations', 0);
    expect(Storage::disk('public')->allFiles('destinations'))->toBe([]);
})->with(['oversized' => ['oversized'], 'GIF' => ['gif'], 'disguised text' => ['disguised text'], 'executable extension' => ['executable extension']]);

test('omitted or null image updates retain the existing destination image', function (array $payload) {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/destinations/'.$destination->id, $payload);

    $response->assertOk()->assertJsonPath('data.image', $path);
    Storage::disk('public')->assertExists($path);
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'image' => $path]);
})->with(['omitted image' => [['destination_type' => 1, 'title' => 'Changed']], 'null image' => [['image' => null]]]);

test('multipart POST with a method override replaces the image and updates destination fields', function (string $method) {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['image' => $oldPath]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/destinations/'.$destination->id, [
        '_method' => $method, 'destination_type' => 1, 'title' => 'Updated Title', 'slug' => $destination->slug,
        'meta_title' => 'Updated SEO Title', 'description' => 'Updated description.',
        'image' => UploadedFile::fake()->image('new.png'), 'status' => '0',
    ], ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('data.status', false)->assertJsonPath('data.title', 'Updated Title')
        ->assertJsonPath('data.meta_title', 'Updated SEO Title')->assertJsonPath('data.description', 'Updated description.');
    $newPath = $response->json('data.image');
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($newPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('destinations', [
        'id' => $destination->id, 'destination_type' => 1, 'title' => 'Updated Title', 'slug' => $destination->slug,
        'meta_title' => 'Updated SEO Title', 'description' => 'Updated description.', 'image' => $newPath, 'status' => 0,
    ]);
})->with(['PUT' => ['PUT'], 'PATCH' => ['PATCH']]);

test('direct multipart destination updates return 422 rather than silently ignoring the payload', function (string $method) {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['title' => 'Original Title']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $body = "--destination-boundary\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nChanged Title\r\n--destination-boundary--\r\n";

    $response = $this->call($method, '/api/admin/destinations/'.$destination->id, [], [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=destination-boundary',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], $body);

    $response->assertUnprocessable()->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors([
            '_method' => 'Send form-data updates using POST with _method set to PUT or PATCH, or send a JSON body with PUT or PATCH.',
        ]);
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'title' => 'Original Title']);
})->with(['PUT' => ['PUT'], 'PATCH' => ['PATCH']]);

test('destination listing uses descending IDs and the standard pagination envelope', function () {
    $admin = AdminUser::factory()->create();
    $destinations = Destination::factory()->count(3)->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?per_page=2');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $destinations->last()->id)
        ->assertJsonPath('data.1.id', $destinations[1]->id)->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.last_page', 2)->assertJsonPath('pagination.per_page', 2)
        ->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.from', 1)->assertJsonPath('pagination.to', 2)
        ->assertJsonPath('pagination.prev_page_url', null)->assertJsonMissingPath('data.data')->assertJsonMissingPath('errors');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect($response->json('pagination.next_page_url'))->toContain('per_page=2');
});

test('destination keywords partially match the title or slug', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'royal-forts']);
    Destination::factory()->create(['destination_type' => 1, 'title' => 'Beach Holidays', 'slug' => 'beach-holidays']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?'.http_build_query(['search' => $keyword]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $destination->id)->assertJsonPath('pagination.total', 1);
})->with(['title' => ['ritage'], 'slug' => ['yal-for'], 'trimmed title' => ['  Heritage  ']]);

test('destination keyword matches the literal zero string', function () {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['destination_type' => 1, 'title' => 'Route 0', 'slug' => 'route-zero']);
    Destination::factory()->create(['destination_type' => 1, 'title' => 'Beach Holiday', 'slug' => 'beach-holiday']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?search=0');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $destination->id);
});

test('destination status filters support active and inactive values', function (string $status, bool $expectedStatus) {
    $admin = AdminUser::factory()->create();
    $active = Destination::factory()->create();
    $inactive = Destination::factory()->inactive()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $expectedDestination = $expectedStatus ? $active : $inactive;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?status='.$status);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expectedDestination->id)
        ->assertJsonPath('data.0.status', $expectedStatus)->assertJsonPath('pagination.total', 1);
})->with(['active' => ['1', true], 'inactive' => ['0', false]]);

test('destination type filters return only the selected type', function (string $type) {
    $admin = AdminUser::factory()->create();
    Destination::factory()->create();
    Destination::factory()->international()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?destination_type='.$type);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.destination_type', (int) $type)
        ->assertJsonPath('pagination.total', 1);
})->with(['domestic' => ['1'], 'international' => ['2']]);

test('popularity parameters do not filter destinations or appear in pagination links', function () {
    $admin = AdminUser::factory()->create();
    Destination::factory()->create();
    Destination::factory()->popular()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?is_most_popular=1&per_page=1');

    $response->assertOk()->assertJsonPath('pagination.total', 2);
    expect($response->json('pagination.next_page_url'))->not->toContain('is_most_popular');
});

test('grouped destination search combines with type and status before pagination and remains in links', function () {
    $admin = AdminUser::factory()->create();
    Destination::factory()->create(['destination_type' => 1, 'title' => 'Heritage Active', 'slug' => 'heritage-active']);
    Destination::factory()->inactive()->create(['destination_type' => 1, 'title' => 'Beach Holiday', 'slug' => 'beach-holiday']);
    Destination::factory()->international()->inactive()->create(['title' => 'Heritage Abroad', 'slug' => 'heritage-abroad']);
    $matched = Destination::factory()->inactive()->create(['destination_type' => 1, 'title' => 'Heritage Inactive', 'slug' => 'heritage-inactive']);
    $slugMatch = Destination::factory()->inactive()->create(['destination_type' => 1, 'title' => 'Royal Palaces', 'slug' => 'heritage-palaces']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?search=heritage&destination_type=1&status=0&per_page=1');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $slugMatch->id)
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2);
    parse_str(parse_url($response->json('pagination.next_page_url'), PHP_URL_QUERY), $nextPageQuery);
    expect($nextPageQuery)->toMatchArray(['search' => 'heritage', 'destination_type' => '1', 'status' => '0', 'per_page' => '1', 'page' => '2']);
    $this->withToken($token)->getJson('/api/admin/destinations?search=heritage&destination_type=1&status=0&per_page=1&page=2')
        ->assertOk()->assertJsonPath('data.0.id', $matched->id);
});

test('resetting destination filters returns active and inactive destinations', function (string $query) {
    $admin = AdminUser::factory()->create();
    Destination::factory()->create();
    Destination::factory()->international()->inactive()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations'.$query);

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.per_page', 15);
})->with(['omitted filters' => [''], 'blank filters' => ['?search=%20%20&destination_type=&status=']]);

test('special keyword characters cannot broaden destination matches', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['destination_type' => 1, 'title' => 'Match '.$keyword, 'slug' => 'matching-destination']);
    Destination::factory()->create(['destination_type' => 1, 'title' => 'Other Destination', 'slug' => 'other-destination']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?'.http_build_query(['search' => $keyword]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $destination->id);
})->with(['percent' => ['%'], 'underscore' => ['_'], 'escape' => ['!'], 'SQL fragment' => ["' OR 1=1 --"]]);

test('empty destination pages return empty arrays with nullable bounds', function (string $query, int $total) {
    $admin = AdminUser::factory()->create();
    Destination::factory()->create(['destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?'.$query);

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', $total)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
})->with(['no matches' => ['search=nonexistent', 0], 'page beyond last page' => ['page=100', 1]]);

test('invalid destination filters and pagination return 422', function (array $query, string $field) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/destinations?'.http_build_query($query));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'array keyword' => [['search' => ['heritage']], 'search'],
    'oversized keyword' => [['search' => str_repeat('a', 256)], 'search'],
    'invalid destination type' => [['destination_type' => 3], 'destination_type'],
    'fractional destination type' => [['destination_type' => 1.5], 'destination_type'],
    'array destination type' => [['destination_type' => [1]], 'destination_type'],
    'invalid status' => [['status' => 'active'], 'status'],
    'zero page' => [['page' => '0'], 'page'],
    'noninteger page' => [['page' => 'abc'], 'page'],
    'zero page size' => [['per_page' => '0'], 'per_page'],
    'oversized page size' => [['per_page' => '101'], 'per_page'],
]);

test('deleting a destination without an image returns a 200 envelope', function () {
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/destinations/'.$destination->id);

    $response->assertOk()->assertExactJson([
        'status' => true, 'data' => null, 'pagination' => null, 'message' => 'Destination deleted successfully.',
    ]);
    $this->assertModelMissing($destination);
});

test('missing destination records return the standard 404 envelope', function (string $method) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->json($method, '/api/admin/destinations/999');

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
})->with(['show' => ['GET'], 'update' => ['PATCH'], 'destroy' => ['DELETE']]);

test('destination database list failures return a safe 500 and are reported', function () {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Schema::drop('destinations');
    Exceptions::fake();

    $response = $this->withToken($token)->getJson('/api/admin/destinations');

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null,
        'message' => 'Unable to retrieve destinations. Please try again later.',
    ]);
    Exceptions::assertReported(QueryException::class);
});

test('the optional destination seeder does not overwrite existing destinations', function () {
    $existing = Destination::factory()->inactive()->create(['destination_type' => 1, 'title' => 'Custom Jaipur', 'slug' => 'jaipur']);

    $this->seed(DestinationSeeder::class);

    $this->assertDatabaseCount('destinations', 6);
    expect($existing->refresh()->title)->toBe('Custom Jaipur');
    expect($existing->status)->toBeFalse();

    $this->seed(DestinationSeeder::class);

    $this->assertDatabaseCount('destinations', 6);
});

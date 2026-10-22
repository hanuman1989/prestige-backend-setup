<?php

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/**
 * Real commits are required to verify upload cleanup after transactions complete.
 */
uses(DatabaseMigrations::class);

dataset('banner taxonomies', [
    'category' => [[
        'model' => Category::class, 'table' => 'categories', 'disk_key' => 'category_images_disk',
        'payload' => ['title' => 'Heritage Tours', 'slug' => 'heritage-tours'],
    ]],
    'destination' => [[
        'model' => Destination::class, 'table' => 'destinations', 'disk_key' => 'destination_images_disk',
        'payload' => ['title' => 'Jaipur', 'slug' => 'jaipur', 'destination_type' => 1],
    ]],
]);

test('taxonomy banners store separately from images and expose their URL', function (array $taxonomy, string $extension) {
    Storage::fake('public');
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    $table = $taxonomy['table'];

    $response = $this->withToken($token)->post('/api/admin/'.$table, array_merge($taxonomy['payload'], [
        'image' => UploadedFile::fake()->image('thumbnail.jpg'),
        'banner' => UploadedFile::fake()->image('original-banner.'.$extension)->size(10240),
    ]), ['Accept' => 'application/json']);

    $response->assertCreated();
    $banner = $response->json('data.banner');
    $image = $response->json('data.image');
    expect($banner)->toStartWith($table.'/banners/')->not->toContain('original-banner');
    expect($image)->toStartWith($table.'/')->not->toStartWith($table.'/banners/');
    Storage::disk('public')->assertExists([$banner, $image]);
    $this->assertDatabaseHas($table, ['id' => $response->json('data.id'), 'banner' => $banner, 'image' => $image]);
    $response->assertJsonPath('data.banner_url', Storage::disk('public')->url($banner))
        ->assertJsonMissingPath('data.uploadedBanner');
})->with('banner taxonomies')->with(['jpg', 'jpeg', 'png', 'webp']);

test('taxonomy banners are optional and ignore client supplied URLs', function (array $taxonomy, array $payload) {
    Storage::fake('public');
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    $table = $taxonomy['table'];

    $response = $this->withToken($token)->postJson('/api/admin/'.$table, array_merge($taxonomy['payload'], $payload, [
        'banner_url' => 'https://untrusted.test/banner.jpg',
    ]));

    $response->assertCreated()->assertJsonPath('data.banner', null)->assertJsonPath('data.banner_url', null);
    $this->assertDatabaseHas($table, ['id' => $response->json('data.id'), 'banner' => null]);
    expect(Storage::disk('public')->allFiles($table))->toBe([]);
})->with('banner taxonomies')->with(['omitted' => [[]], 'null' => [['banner' => null]]]);

test('invalid taxonomy banners return 422 without changing records or files', function (array $taxonomy, string $method, string $invalid) {
    Storage::fake('public');
    $table = $taxonomy['table'];
    $banner = UploadedFile::fake()->image('old.jpg')->store($table.'/banners', 'public');
    $record = $taxonomy['model']::factory()->create(['banner' => $banner]);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    $upload = match ($invalid) {
        'oversized' => UploadedFile::fake()->image('banner.jpg')->size(10241),
        'GIF' => UploadedFile::fake()->image('banner.gif'),
        'executable extension' => UploadedFile::fake()->image('banner.php'),
        'disguised text' => UploadedFile::fake()->create('banner.jpg', 10, 'text/plain'),
        'path' => $table.'/banners/untrusted.jpg',
    };
    $payload = array_merge($taxonomy['payload'], ['banner' => $upload]);
    $uri = '/api/admin/'.$table;
    if ($method === 'PATCH') {
        $payload['_method'] = 'PATCH';
        $uri .= '/'.$record->id;
    }

    $response = $this->withToken($token)->post($uri, $payload, ['Accept' => 'application/json']);

    $response->assertUnprocessable()->assertJsonValidationErrors('banner');
    if ($invalid === 'oversized') {
        $response->assertJsonValidationErrors(['banner' => 'The banner field must not be greater than 10240 kilobytes.']);
    }
    $this->assertDatabaseCount($table, 1);
    $this->assertDatabaseHas($table, ['id' => $record->id, 'banner' => $banner, 'title' => $record->title]);
    expect(Storage::disk('public')->allFiles($table))->toBe([$banner]);
})->with('banner taxonomies')->with(['POST', 'PATCH'])->with(['oversized', 'GIF', 'executable extension', 'disguised text', 'path']);

test('banner replacements delete the old banner and retain the existing image', function (array $taxonomy, string $method) {
    Storage::fake('public');
    $table = $taxonomy['table'];
    $image = UploadedFile::fake()->image('thumbnail.jpg')->store($table, 'public');
    $banner = UploadedFile::fake()->image('old.jpg')->store($table.'/banners', 'public');
    $record = $taxonomy['model']::factory()->create(['banner' => $banner, 'image' => $image]);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/'.$table.'/'.$record->id, [
        '_method' => $method, 'banner' => UploadedFile::fake()->image('replacement.png')->size(10240),
    ], ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('data.image', $image);
    $replacement = $response->json('data.banner');
    expect($replacement)->not->toBe($banner)->toStartWith($table.'/banners/');
    Storage::disk('public')->assertExists([$replacement, $image])->assertMissing($banner);
    $this->assertDatabaseHas($table, ['id' => $record->id, 'banner' => $replacement, 'image' => $image]);
})->with('banner taxonomies')->with(['PUT', 'PATCH']);

test('omitted or null banner updates preserve the current banner when replacing the image', function (array $taxonomy, array $payload) {
    Storage::fake('public');
    $table = $taxonomy['table'];
    $banner = UploadedFile::fake()->image('old.jpg')->store($table.'/banners', 'public');
    $record = $taxonomy['model']::factory()->create(['banner' => $banner]);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/'.$table.'/'.$record->id, array_merge($payload, [
        '_method' => 'PATCH', 'image' => UploadedFile::fake()->image('thumbnail.jpg'),
    ]), ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('data.banner', $banner);
    $image = $response->json('data.image');
    Storage::disk('public')->assertExists([$banner, $image]);
    $this->assertDatabaseHas($table, ['id' => $record->id, 'banner' => $banner, 'image' => $image]);
})->with('banner taxonomies')->with(['omitted' => [[]], 'null' => [['banner' => null]]]);

test('deleting a taxonomy removes both the banner and image from storage', function (array $taxonomy) {
    Storage::fake('public');
    $table = $taxonomy['table'];
    $banner = UploadedFile::fake()->image('banner.jpg')->store($table.'/banners', 'public');
    $image = UploadedFile::fake()->image('thumbnail.jpg')->store($table, 'public');
    $record = $taxonomy['model']::factory()->create(['banner' => $banner, 'image' => $image]);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/'.$table.'/'.$record->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($record);
    Storage::disk('public')->assertMissing([$banner, $image]);
})->with('banner taxonomies');

test('failed taxonomy creates clean up both newly uploaded files', function (array $taxonomy) {
    Storage::fake('public');
    $table = $taxonomy['table'];
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_banner_insert BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/'.$table, array_merge($taxonomy['payload'], [
        'banner' => UploadedFile::fake()->image('banner.jpg'), 'image' => UploadedFile::fake()->image('thumbnail.jpg'),
    ]), ['Accept' => 'application/json']);

    $response->assertInternalServerError();
    $this->assertDatabaseCount($table, 0);
    expect(Storage::disk('public')->allFiles($table))->toBe([]);
    Exceptions::assertReported(QueryException::class);
})->with('banner taxonomies');

test('failed taxonomy updates retain old files and remove both replacements', function (array $taxonomy) {
    Storage::fake('public');
    $table = $taxonomy['table'];
    $banner = UploadedFile::fake()->image('banner.jpg')->store($table.'/banners', 'public');
    $image = UploadedFile::fake()->image('thumbnail.jpg')->store($table, 'public');
    $record = $taxonomy['model']::factory()->create(['banner' => $banner, 'image' => $image]);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_banner_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/'.$table.'/'.$record->id, [
        '_method' => 'PATCH', 'title' => 'Changed', 'banner' => UploadedFile::fake()->image('new-banner.jpg'),
        'image' => UploadedFile::fake()->image('new-thumbnail.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError();
    $this->assertDatabaseHas($table, ['id' => $record->id, 'banner' => $banner, 'image' => $image, 'title' => $record->title]);
    expect(Storage::disk('public')->allFiles($table))->toHaveCount(2);
    Storage::disk('public')->assertExists([$banner, $image]);
    Exceptions::assertReported(QueryException::class);
})->with('banner taxonomies');

test('rolling back a taxonomy deletion preserves its banner and image', function (array $taxonomy) {
    Storage::fake('public');
    $table = $taxonomy['table'];
    $banner = UploadedFile::fake()->image('banner.jpg')->store($table.'/banners', 'public');
    $image = UploadedFile::fake()->image('thumbnail.jpg')->store($table, 'public');
    $record = $taxonomy['model']::factory()->create(['banner' => $banner, 'image' => $image]);
    DB::beginTransaction();
    $record->delete();

    DB::rollBack();

    $this->assertDatabaseHas($table, ['id' => $record->id, 'banner' => $banner, 'image' => $image]);
    Storage::disk('public')->assertExists([$banner, $image]);
})->with('banner taxonomies');

test('banner replacements in committed savepoints are cleaned up on outer rollback', function (array $taxonomy) {
    Storage::fake('public');
    $table = $taxonomy['table'];
    $banner = UploadedFile::fake()->image('old.jpg')->store($table.'/banners', 'public');
    $record = $taxonomy['model']::factory()->create(['banner' => $banner]);
    DB::beginTransaction();
    DB::transaction(function () use ($record): void {
        $record->uploadedBanner = UploadedFile::fake()->image('new.jpg');
        $record->save();
    });
    $replacement = $record->banner;
    Storage::disk('public')->assertExists([$banner, $replacement]);

    DB::rollBack();

    $this->assertDatabaseHas($table, ['id' => $record->id, 'banner' => $banner]);
    Storage::disk('public')->assertExists($banner)->assertMissing($replacement);
})->with('banner taxonomies');

test('banner storage failures clean up partial banners and preceding image uploads', function (array $taxonomy) {
    $disk = Storage::fake('public');
    $table = $taxonomy['table'];
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('putFileAs')->with($table, Mockery::any(), Mockery::any(), Mockery::any())
        ->once()->andReturnUsing(fn (string $directory, UploadedFile $file, string $filename, array $options): string|false => $disk->putFileAs($directory, $file, $filename, $options));
    $faultyDisk->shouldReceive('putFileAs')->with($table.'/banners', Mockery::any(), Mockery::any(), Mockery::any())
        ->once()->andReturnUsing(function (string $directory, UploadedFile $file, string $filename, array $options) use ($disk): bool {
            $disk->putFileAs($directory, $file, $filename, $options);

            return false;
        });
    Storage::set('public', $faultyDisk);
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/'.$table, array_merge($taxonomy['payload'], [
        'banner' => UploadedFile::fake()->image('banner.jpg'), 'image' => UploadedFile::fake()->image('thumbnail.jpg'),
    ]), ['Accept' => 'application/json']);

    $response->assertInternalServerError();
    $this->assertDatabaseCount($table, 0);
    expect($disk->allFiles($table))->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
})->with('banner taxonomies');

test('taxonomy banners use the configured image disk and are exposed on public and admin details', function (array $taxonomy) {
    config(['filesystems.'.$taxonomy['disk_key'] => 'taxonomy_media']);
    Storage::fake('taxonomy_media');
    Storage::fake('public');
    $table = $taxonomy['table'];
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    $response = $this->withToken($token)->post('/api/admin/'.$table, array_merge($taxonomy['payload'], [
        'banner' => UploadedFile::fake()->image('banner.jpg'),
    ]), ['Accept' => 'application/json']);
    $response->assertCreated();
    $banner = $response->json('data.banner');
    $id = $response->json('data.id');
    $identifier = $taxonomy['model'] === Destination::class ? $response->json('data.slug') : $id;
    Storage::disk('taxonomy_media')->assertExists($banner);
    expect(Storage::disk('public')->allFiles($table))->toBe([]);

    $this->getJson('/api/'.$table.'/'.$identifier)->assertOk()->assertJsonPath('data.banner', $banner)
        ->assertJsonPath('data.banner_url', Storage::disk('taxonomy_media')->url($banner));
    $this->getJson('/api/admin/'.$table.'/'.$id)->assertOk()->assertJsonPath('data.banner', $banner)
        ->assertJsonPath('data.banner_url', Storage::disk('taxonomy_media')->url($banner));
})->with('banner taxonomies');

test('frontend tokens cannot upload taxonomy banners and receive 401', function (array $taxonomy) {
    Storage::fake('public');
    $token = User::factory()->create()->createToken('test', ['frontend'])->plainTextToken;
    $table = $taxonomy['table'];

    $response = $this->withToken($token)->post('/api/admin/'.$table, array_merge($taxonomy['payload'], [
        'banner' => UploadedFile::fake()->image('banner.jpg'),
    ]), ['Accept' => 'application/json']);

    $response->assertUnauthorized();
    $this->assertDatabaseCount($table, 0);
    expect(Storage::disk('public')->allFiles($table))->toBe([]);
})->with('banner taxonomies');

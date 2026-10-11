<?php

use App\Models\AdminUser;
use App\Models\Destination;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/**
 * Real commits are required to verify image cleanup at the outermost transaction.
 */
uses(DatabaseMigrations::class);

test('failed destination creation cleans up the newly uploaded image and returns 500', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_destination_insert BEFORE INSERT ON destinations BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/destinations', [
        'destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to create the destination. Please try again later.')
        ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null);
    $this->assertDatabaseCount('destinations', 0);
    expect(Storage::disk('public')->allFiles('destinations'))->toBe([]);
    Exceptions::assertReported(QueryException::class);
});

test('failed destination updates preserve the old image and remove the replacement', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['destination_type' => 1, 'title' => 'Original', 'image' => $oldPath]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_destination_update BEFORE UPDATE ON destinations BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/destinations/'.$destination->id, [
        '_method' => 'PATCH', 'destination_type' => 1, 'title' => 'Changed', 'image' => UploadedFile::fake()->image('new.png'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to update the destination. Please try again later.');
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'destination_type' => 1, 'title' => 'Original', 'image' => $oldPath]);
    Storage::disk('public')->assertExists($oldPath);
    expect(Storage::disk('public')->allFiles('destinations'))->toBe([$oldPath]);
    Exceptions::assertReported(QueryException::class);
});

test('slug conflicts after validation return 409 and clean up the uploaded image', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER conflicting_destination_insert BEFORE INSERT ON destinations BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: destinations.slug'); END");

    $response = $this->withToken($token)->post('/api/admin/destinations', [
        'destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertConflict()->assertJsonPath('message', 'A record with these details already exists.');
    $this->assertDatabaseCount('destinations', 0);
    expect(Storage::disk('public')->allFiles('destinations'))->toBe([]);
});

test('replaced images are removed only after the outermost transaction commits', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $destination = Destination::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();

    DB::transaction(function () use ($destination): void {
        $destination->uploadedImage = UploadedFile::fake()->image('new.png');
        $destination->save();
    });

    $newPath = $destination->image;
    Storage::disk('public')->assertExists([$oldPath, $newPath]);
    DB::commit();
    Storage::disk('public')->assertExists($newPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'image' => $newPath]);
});

test('outer transaction rollbacks restore old image paths and clean up committed savepoint uploads', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $destination = Destination::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();

    DB::transaction(function () use ($destination): void {
        $destination->uploadedImage = UploadedFile::fake()->image('new.png');
        $destination->save();
    });

    $newPath = $destination->image;
    DB::rollBack();
    Storage::disk('public')->assertExists($oldPath)->assertMissing($newPath);
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'image' => $oldPath]);
});

test('rolling back a later savepoint preserves earlier uploads until the outer commit', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $destination = Destination::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();
    DB::transaction(function () use ($destination): void {
        $destination->uploadedImage = UploadedFile::fake()->image('first.png');
        $destination->save();
    });
    $firstPath = $destination->image;

    DB::beginTransaction();
    $destination->uploadedImage = UploadedFile::fake()->image('second.png');
    $destination->save();
    $secondPath = $destination->image;
    DB::rollBack();

    Storage::disk('public')->assertExists([$oldPath, $firstPath])->assertMissing($secondPath);
    DB::commit();
    Storage::disk('public')->assertExists($firstPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'image' => $firstPath]);
});

test('failed destination deletion preserves both the destination and its image', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_destination_delete BEFORE DELETE ON destinations BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->deleteJson('/api/admin/destinations/'.$destination->id);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to delete the destination. Please try again later.');
    $this->assertModelExists($destination);
    Storage::disk('public')->assertExists($path);
    Exceptions::assertReported(QueryException::class);
});

test('successful destination deletion removes its image', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/destinations/'.$destination->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($destination);
    Storage::disk('public')->assertMissing($path);
});

test('missing destination images do not prevent successful deletion', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['image' => 'destinations/missing.jpg']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/destinations/'.$destination->id);

    $response->assertOk();
    $this->assertModelMissing($destination);
    expect(Storage::disk('public')->allFiles('destinations'))->toBe([]);
});

test('deletion retains the image until commit and preserves it on rollback', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $destination = Destination::factory()->create(['image' => $path]);
    DB::beginTransaction();

    $destination->delete();

    Storage::disk('public')->assertExists($path);
    DB::rollBack();
    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'image' => $path]);
    Storage::disk('public')->assertExists($path);
});

test('destination images use the configured storage disk and URL', function () {
    config(['filesystems.destination_images_disk' => 'destination_media']);
    Storage::fake('destination_media');
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/destinations', [
        'destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $path = $response->json('data.image');
    Storage::disk('destination_media')->assertExists($path);
    expect(Storage::disk('public')->allFiles('destinations'))->toBe([]);
    $response->assertJsonPath('data.image_url', Storage::disk('destination_media')->url($path));
    $this->assertDatabaseHas('destinations', ['slug' => 'heritage-tours', 'image' => $path]);
});

test('failed storage writes clean up partial uploads and create no destination', function () {
    $disk = Storage::fake('public');
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('putFileAs')->once()->andReturnUsing(
        function (string $directory, UploadedFile $file, string $filename, array $options) use ($disk): bool {
            $disk->putFileAs($directory, $file, $filename, $options);

            return false;
        },
    );
    Storage::set('public', $faultyDisk);
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/destinations', [
        'destination_type' => 1, 'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'An unexpected error occurred.',
    ]);
    $this->assertDatabaseCount('destinations', 0);
    expect($disk->allFiles('destinations'))->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('image cleanup failures are reported after a successful destination deletion', function () {
    $disk = Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('delete')->once()->with($path)->andThrow(new RuntimeException('Storage is unavailable.'));
    Storage::set('public', $faultyDisk);
    $admin = AdminUser::factory()->create();
    $destination = Destination::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Exceptions::fake();

    $response = $this->withToken($token)->deleteJson('/api/admin/destinations/'.$destination->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($destination);
    $disk->assertExists($path);
    Exceptions::assertReported(RuntimeException::class);
});

test('reusing an upload for another destination keeps their images independent', function () {
    Storage::fake('public');
    $upload = UploadedFile::fake()->image('photo.jpg');
    $firstDestination = Destination::factory()->create();
    $secondDestination = Destination::factory()->create();
    DB::transaction(function () use ($firstDestination, $secondDestination, $upload): void {
        $firstDestination->uploadedImage = $upload;
        $firstDestination->save();
        $secondDestination->uploadedImage = $upload;
        $secondDestination->save();
    });
    $firstPath = $firstDestination->image;
    $secondPath = $secondDestination->image;

    DB::transaction(fn (): ?bool => $firstDestination->delete());

    $this->assertModelMissing($firstDestination);
    $this->assertDatabaseHas('destinations', ['id' => $secondDestination->id, 'image' => $secondPath]);
    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath)->assertExists($secondPath);
});

test('rolling back a replacement with a reused upload preserves the persisted image', function () {
    Storage::fake('public');
    $upload = UploadedFile::fake()->image('photo.jpg');
    $destination = Destination::factory()->create();
    DB::transaction(function () use ($destination, $upload): void {
        $destination->uploadedImage = $upload;
        $destination->save();
    });
    $oldPath = $destination->image;
    DB::beginTransaction();
    $destination->uploadedImage = $upload;
    $destination->save();
    $replacementPath = $destination->image;

    DB::rollBack();

    $this->assertDatabaseHas('destinations', ['id' => $destination->id, 'image' => $oldPath]);
    expect($replacementPath)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath)->assertMissing($replacementPath);
});

test('deletion removes the persisted image instead of an unsaved image path', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('destinations', 'public');
    $unrelatedPath = UploadedFile::fake()->image('unrelated.jpg')->store('destinations', 'public');
    $destination = Destination::factory()->create(['image' => $oldPath]);
    $destination->image = $unrelatedPath;

    DB::transaction(fn (): ?bool => $destination->delete());

    $this->assertModelMissing($destination);
    Storage::disk('public')->assertMissing($oldPath)->assertExists($unrelatedPath);
});

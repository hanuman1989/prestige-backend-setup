<?php

use App\Models\AdminUser;
use App\Models\Car;
use App\Models\CarCategory;
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

test('deleting a category that has cars returns 409 and preserves records and images', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('category.jpg')->store('car-categories', 'public');
    $category = CarCategory::factory()->create(['image' => $path]);
    $car = Car::factory()->for($category)->create();
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/car-categories/'.$category->id);

    $response->assertConflict()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Cannot delete a car category that has cars.',
    ]);
    $this->assertModelExists($category);
    $this->assertModelExists($car);
    Storage::disk('public')->assertExists($path);
});

test('a car added during category deletion still returns 409 and preserves the category', function () {
    $category = CarCategory::factory()->create();
    $token = AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
    CarCategory::deleting(function (CarCategory $category): void {
        Car::factory()->for($category)->create();
    });

    $response = $this->withToken($token)->deleteJson('/api/admin/car-categories/'.$category->id);

    $response->assertConflict()->assertJsonPath('message', 'Cannot delete a car category that has cars.');
    $this->assertModelExists($category);
    $this->assertDatabaseCount('cars', 0);
});

test('failed car category creation cleans up the newly uploaded image and returns 500', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_car_category_insert BEFORE INSERT ON car_categories BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/car-categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to create the car category. Please try again later.')
        ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null);
    $this->assertDatabaseCount('car_categories', 0);
    expect(Storage::disk('public')->allFiles('car-categories'))->toBe([]);
    Exceptions::assertReported(QueryException::class);
});

test('failed car category updates preserve the old image and remove the replacement', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $admin = AdminUser::factory()->create();
    $carCategory = CarCategory::factory()->create(['title' => 'Original', 'image' => $oldPath]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_car_category_update BEFORE UPDATE ON car_categories BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/car-categories/'.$carCategory->id, [
        '_method' => 'PATCH', 'title' => 'Changed', 'image' => UploadedFile::fake()->image('new.png'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to update the car category. Please try again later.');
    $this->assertDatabaseHas('car_categories', ['id' => $carCategory->id, 'title' => 'Original', 'image' => $oldPath]);
    Storage::disk('public')->assertExists($oldPath);
    expect(Storage::disk('public')->allFiles('car-categories'))->toBe([$oldPath]);
    Exceptions::assertReported(QueryException::class);
});

test('slug conflicts after validation return 409 and clean up the uploaded image', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER conflicting_car_category_insert BEFORE INSERT ON car_categories BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: car_categories.slug'); END");

    $response = $this->withToken($token)->post('/api/admin/car-categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertConflict()->assertJsonPath('message', 'A record with these details already exists.');
    $this->assertDatabaseCount('car_categories', 0);
    expect(Storage::disk('public')->allFiles('car-categories'))->toBe([]);
});

test('replaced images are removed only after the outermost transaction commits', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $carCategory = CarCategory::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();

    DB::transaction(function () use ($carCategory): void {
        $carCategory->uploadedImage = UploadedFile::fake()->image('new.png');
        $carCategory->save();
    });

    $newPath = $carCategory->image;
    Storage::disk('public')->assertExists([$oldPath, $newPath]);
    DB::commit();
    Storage::disk('public')->assertExists($newPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('car_categories', ['id' => $carCategory->id, 'image' => $newPath]);
});

test('outer transaction rollbacks restore old image paths and clean up committed savepoint uploads', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $carCategory = CarCategory::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();

    DB::transaction(function () use ($carCategory): void {
        $carCategory->uploadedImage = UploadedFile::fake()->image('new.png');
        $carCategory->save();
    });

    $newPath = $carCategory->image;
    DB::rollBack();
    Storage::disk('public')->assertExists($oldPath)->assertMissing($newPath);
    $this->assertDatabaseHas('car_categories', ['id' => $carCategory->id, 'image' => $oldPath]);
});

test('rolling back a later savepoint preserves earlier uploads until the outer commit', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $carCategory = CarCategory::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();
    DB::transaction(function () use ($carCategory): void {
        $carCategory->uploadedImage = UploadedFile::fake()->image('first.png');
        $carCategory->save();
    });
    $firstPath = $carCategory->image;

    DB::beginTransaction();
    $carCategory->uploadedImage = UploadedFile::fake()->image('second.png');
    $carCategory->save();
    $secondPath = $carCategory->image;
    DB::rollBack();

    Storage::disk('public')->assertExists([$oldPath, $firstPath])->assertMissing($secondPath);
    DB::commit();
    Storage::disk('public')->assertExists($firstPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('car_categories', ['id' => $carCategory->id, 'image' => $firstPath]);
});

test('failed car category deletion preserves both the car category and its image', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $admin = AdminUser::factory()->create();
    $carCategory = CarCategory::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_car_category_delete BEFORE DELETE ON car_categories BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->deleteJson('/api/admin/car-categories/'.$carCategory->id);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to delete the car category. Please try again later.');
    $this->assertModelExists($carCategory);
    Storage::disk('public')->assertExists($path);
    Exceptions::assertReported(QueryException::class);
});

test('successful car category deletion removes its image', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $admin = AdminUser::factory()->create();
    $carCategory = CarCategory::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/car-categories/'.$carCategory->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($carCategory);
    Storage::disk('public')->assertMissing($path);
});

test('missing car category images do not prevent successful deletion', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $carCategory = CarCategory::factory()->create(['image' => 'car_categories/missing.jpg']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/car-categories/'.$carCategory->id);

    $response->assertOk();
    $this->assertModelMissing($carCategory);
    expect(Storage::disk('public')->allFiles('car-categories'))->toBe([]);
});

test('deletion retains the image until commit and preserves it on rollback', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $carCategory = CarCategory::factory()->create(['image' => $path]);
    DB::beginTransaction();

    $carCategory->delete();

    Storage::disk('public')->assertExists($path);
    DB::rollBack();
    $this->assertDatabaseHas('car_categories', ['id' => $carCategory->id, 'image' => $path]);
    Storage::disk('public')->assertExists($path);
});

test('car category images use the configured storage disk and URL', function () {
    config(['filesystems.car_category_images_disk' => 'car_category_media']);
    Storage::fake('car_category_media');
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/car-categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $path = $response->json('data.image');
    Storage::disk('car_category_media')->assertExists($path);
    expect(Storage::disk('public')->allFiles('car-categories'))->toBe([]);
    $response->assertJsonPath('data.image_url', Storage::disk('car_category_media')->url($path));
    $this->assertDatabaseHas('car_categories', ['slug' => 'heritage-tours', 'image' => $path]);
});

test('failed storage writes clean up partial uploads and create no car category', function () {
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

    $response = $this->withToken($token)->post('/api/admin/car-categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'An unexpected error occurred.',
    ]);
    $this->assertDatabaseCount('car_categories', 0);
    expect($disk->allFiles('car-categories'))->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('image cleanup failures are reported after a successful car category deletion', function () {
    $disk = Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('delete')->once()->with($path)->andThrow(new RuntimeException('Storage is unavailable.'));
    Storage::set('public', $faultyDisk);
    $admin = AdminUser::factory()->create();
    $carCategory = CarCategory::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Exceptions::fake();

    $response = $this->withToken($token)->deleteJson('/api/admin/car-categories/'.$carCategory->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($carCategory);
    $disk->assertExists($path);
    Exceptions::assertReported(RuntimeException::class);
});

test('reusing an upload for another car category keeps their images independent', function () {
    Storage::fake('public');
    $upload = UploadedFile::fake()->image('photo.jpg');
    $firstCarCategory = CarCategory::factory()->create();
    $secondCarCategory = CarCategory::factory()->create();
    DB::transaction(function () use ($firstCarCategory, $secondCarCategory, $upload): void {
        $firstCarCategory->uploadedImage = $upload;
        $firstCarCategory->save();
        $secondCarCategory->uploadedImage = $upload;
        $secondCarCategory->save();
    });
    $firstPath = $firstCarCategory->image;
    $secondPath = $secondCarCategory->image;

    DB::transaction(fn (): ?bool => $firstCarCategory->delete());

    $this->assertModelMissing($firstCarCategory);
    $this->assertDatabaseHas('car_categories', ['id' => $secondCarCategory->id, 'image' => $secondPath]);
    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath)->assertExists($secondPath);
});

test('rolling back a replacement with a reused upload preserves the persisted image', function () {
    Storage::fake('public');
    $upload = UploadedFile::fake()->image('photo.jpg');
    $carCategory = CarCategory::factory()->create();
    DB::transaction(function () use ($carCategory, $upload): void {
        $carCategory->uploadedImage = $upload;
        $carCategory->save();
    });
    $oldPath = $carCategory->image;
    DB::beginTransaction();
    $carCategory->uploadedImage = $upload;
    $carCategory->save();
    $replacementPath = $carCategory->image;

    DB::rollBack();

    $this->assertDatabaseHas('car_categories', ['id' => $carCategory->id, 'image' => $oldPath]);
    expect($replacementPath)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath)->assertMissing($replacementPath);
});

test('deletion removes the persisted image instead of an unsaved image path', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('car-categories', 'public');
    $unrelatedPath = UploadedFile::fake()->image('unrelated.jpg')->store('car-categories', 'public');
    $carCategory = CarCategory::factory()->create(['image' => $oldPath]);
    $carCategory->image = $unrelatedPath;

    DB::transaction(fn (): ?bool => $carCategory->delete());

    $this->assertModelMissing($carCategory);
    Storage::disk('public')->assertMissing($oldPath)->assertExists($unrelatedPath);
});

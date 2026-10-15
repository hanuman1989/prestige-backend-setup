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

test('failed car creation cleans up the newly uploaded image and returns 500', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_car_insert BEFORE INSERT ON cars BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/cars', [
        'car_category_id' => CarCategory::factory()->create()->id, 'passenger_seats' => 6, 'starting_rate' => '18.50',
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to create the car. Please try again later.')
        ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null);
    $this->assertDatabaseCount('cars', 0);
    expect(Storage::disk('public')->allFiles('cars'))->toBe([]);
    Exceptions::assertReported(QueryException::class);
});

test('failed car updates preserve the old image and remove the replacement', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $admin = AdminUser::factory()->create();
    $car = Car::factory()->create(['title' => 'Original', 'image' => $oldPath]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_car_update BEFORE UPDATE ON cars BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/cars/'.$car->id, [
        '_method' => 'PATCH', 'title' => 'Changed', 'image' => UploadedFile::fake()->image('new.png'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to update the car. Please try again later.');
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'title' => 'Original', 'image' => $oldPath]);
    Storage::disk('public')->assertExists($oldPath);
    expect(Storage::disk('public')->allFiles('cars'))->toBe([$oldPath]);
    Exceptions::assertReported(QueryException::class);
});

test('slug conflicts after validation return 409 and clean up the uploaded image', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER conflicting_car_insert BEFORE INSERT ON cars BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: cars.slug'); END");

    $response = $this->withToken($token)->post('/api/admin/cars', [
        'car_category_id' => CarCategory::factory()->create()->id, 'passenger_seats' => 6, 'starting_rate' => '18.50',
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertConflict()->assertJsonPath('message', 'A record with these details already exists.');
    $this->assertDatabaseCount('cars', 0);
    expect(Storage::disk('public')->allFiles('cars'))->toBe([]);
});

test('replaced images are removed only after the outermost transaction commits', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $car = Car::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();

    DB::transaction(function () use ($car): void {
        $car->uploadedImage = UploadedFile::fake()->image('new.png');
        $car->save();
    });

    $newPath = $car->image;
    Storage::disk('public')->assertExists([$oldPath, $newPath]);
    DB::commit();
    Storage::disk('public')->assertExists($newPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'image' => $newPath]);
});

test('outer transaction rollbacks restore old image paths and clean up committed savepoint uploads', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $car = Car::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();

    DB::transaction(function () use ($car): void {
        $car->uploadedImage = UploadedFile::fake()->image('new.png');
        $car->save();
    });

    $newPath = $car->image;
    DB::rollBack();
    Storage::disk('public')->assertExists($oldPath)->assertMissing($newPath);
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'image' => $oldPath]);
});

test('rolling back a later savepoint preserves earlier uploads until the outer commit', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $car = Car::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();
    DB::transaction(function () use ($car): void {
        $car->uploadedImage = UploadedFile::fake()->image('first.png');
        $car->save();
    });
    $firstPath = $car->image;

    DB::beginTransaction();
    $car->uploadedImage = UploadedFile::fake()->image('second.png');
    $car->save();
    $secondPath = $car->image;
    DB::rollBack();

    Storage::disk('public')->assertExists([$oldPath, $firstPath])->assertMissing($secondPath);
    DB::commit();
    Storage::disk('public')->assertExists($firstPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'image' => $firstPath]);
});

test('failed car deletion preserves both the car and its image', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $admin = AdminUser::factory()->create();
    $car = Car::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_car_delete BEFORE DELETE ON cars BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->deleteJson('/api/admin/cars/'.$car->id);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to delete the car. Please try again later.');
    $this->assertModelExists($car);
    Storage::disk('public')->assertExists($path);
    Exceptions::assertReported(QueryException::class);
});

test('successful car deletion removes its image', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $admin = AdminUser::factory()->create();
    $car = Car::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/cars/'.$car->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($car);
    Storage::disk('public')->assertMissing($path);
});

test('missing car images do not prevent successful deletion', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $car = Car::factory()->create(['image' => 'cars/missing.jpg']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/cars/'.$car->id);

    $response->assertOk();
    $this->assertModelMissing($car);
    expect(Storage::disk('public')->allFiles('cars'))->toBe([]);
});

test('deletion retains the image until commit and preserves it on rollback', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $car = Car::factory()->create(['image' => $path]);
    DB::beginTransaction();

    $car->delete();

    Storage::disk('public')->assertExists($path);
    DB::rollBack();
    $this->assertDatabaseHas('cars', ['id' => $car->id, 'image' => $path]);
    Storage::disk('public')->assertExists($path);
});

test('car images use the configured storage disk and URL', function () {
    config(['filesystems.car_images_disk' => 'car_media']);
    Storage::fake('car_media');
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/cars', [
        'car_category_id' => CarCategory::factory()->create()->id, 'passenger_seats' => 6, 'starting_rate' => '18.50',
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $path = $response->json('data.image');
    Storage::disk('car_media')->assertExists($path);
    expect(Storage::disk('public')->allFiles('cars'))->toBe([]);
    $response->assertJsonPath('data.image_url', Storage::disk('car_media')->url($path));
    $this->assertDatabaseHas('cars', ['slug' => 'heritage-tours', 'image' => $path]);
});

test('failed storage writes clean up partial uploads and create no car', function () {
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

    $response = $this->withToken($token)->post('/api/admin/cars', [
        'car_category_id' => CarCategory::factory()->create()->id, 'passenger_seats' => 6, 'starting_rate' => '18.50',
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'An unexpected error occurred.',
    ]);
    $this->assertDatabaseCount('cars', 0);
    expect($disk->allFiles('cars'))->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('image cleanup failures are reported after a successful car deletion', function () {
    $disk = Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('delete')->once()->with($path)->andThrow(new RuntimeException('Storage is unavailable.'));
    Storage::set('public', $faultyDisk);
    $admin = AdminUser::factory()->create();
    $car = Car::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Exceptions::fake();

    $response = $this->withToken($token)->deleteJson('/api/admin/cars/'.$car->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($car);
    $disk->assertExists($path);
    Exceptions::assertReported(RuntimeException::class);
});

test('reusing an upload for another car keeps their images independent', function () {
    Storage::fake('public');
    $upload = UploadedFile::fake()->image('photo.jpg');
    $firstCar = Car::factory()->create();
    $secondCar = Car::factory()->create();
    DB::transaction(function () use ($firstCar, $secondCar, $upload): void {
        $firstCar->uploadedImage = $upload;
        $firstCar->save();
        $secondCar->uploadedImage = $upload;
        $secondCar->save();
    });
    $firstPath = $firstCar->image;
    $secondPath = $secondCar->image;

    DB::transaction(fn (): ?bool => $firstCar->delete());

    $this->assertModelMissing($firstCar);
    $this->assertDatabaseHas('cars', ['id' => $secondCar->id, 'image' => $secondPath]);
    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath)->assertExists($secondPath);
});

test('rolling back a replacement with a reused upload preserves the persisted image', function () {
    Storage::fake('public');
    $upload = UploadedFile::fake()->image('photo.jpg');
    $car = Car::factory()->create();
    DB::transaction(function () use ($car, $upload): void {
        $car->uploadedImage = $upload;
        $car->save();
    });
    $oldPath = $car->image;
    DB::beginTransaction();
    $car->uploadedImage = $upload;
    $car->save();
    $replacementPath = $car->image;

    DB::rollBack();

    $this->assertDatabaseHas('cars', ['id' => $car->id, 'image' => $oldPath]);
    expect($replacementPath)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath)->assertMissing($replacementPath);
});

test('deletion removes the persisted image instead of an unsaved image path', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('cars', 'public');
    $unrelatedPath = UploadedFile::fake()->image('unrelated.jpg')->store('cars', 'public');
    $car = Car::factory()->create(['image' => $oldPath]);
    $car->image = $unrelatedPath;

    DB::transaction(fn (): ?bool => $car->delete());

    $this->assertModelMissing($car);
    Storage::disk('public')->assertMissing($oldPath)->assertExists($unrelatedPath);
});

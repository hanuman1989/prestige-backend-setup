<?php

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Destination;
use App\Models\Package;
use App\Models\PackageImage;
use App\Models\PackageItinerary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/** Real commits are required for after-commit file cleanup. */
uses(DatabaseMigrations::class);

function packageObserverToken(): string
{
    return AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
}

/**
 * @return array<string, mixed>
 */
function packageImageStepPayload(string $kind, UploadedFile $file, ?int $id = null): array
{
    $item = ['image' => $file, 'sort_order' => 1];
    if ($id !== null) {
        $item['id'] = $id;
    }
    if ($kind === 'itineraries') {
        $item['title'] = 'Day 1';
        $item['description'] = 'Arrival';
    }

    return ['_method' => 'PATCH', $kind => [$item]];
}

test('failed image persistence removes new uploads without advancing progress', function (string $kind, string $table, int $step) {
    Storage::fake('public');
    $token = packageObserverToken();
    $package = Package::factory()->atStep($step)->create();
    DB::unprepared("CREATE TRIGGER fail_insert BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $this->withToken($token)->post('/api/admin/packages/'.$package->id.'/'.$kind,
        packageImageStepPayload($kind, UploadedFile::fake()->image('new.png')),
        ['Accept' => 'application/json'])
        ->assertInternalServerError()->assertJsonPath('message', 'Unable to save the package step. Please try again later.');

    $this->assertDatabaseCount($table, 0);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => $step]);
    expect(Storage::disk('public')->allFiles('packages'))->toBe([]);
    Exceptions::assertReported(QueryException::class);
})->with([
    'itinerary' => ['itineraries', 'package_itineraries', 3], 'gallery' => ['photos', 'package_images', 5],
]);

test('failed replacements retain old images and remove new files', function (string $kind, string $table) {
    Storage::fake('public');
    $token = packageObserverToken();
    $package = Package::factory()->atStep(6)->create();
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('packages/'.$kind, 'public');
    $item = $kind === 'photos'
        ? PackageImage::factory()->for($package)->create(['image' => $oldPath])
        : PackageItinerary::factory()->for($package)->create(['image' => $oldPath]);
    DB::unprepared("CREATE TRIGGER fail_update BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $this->withToken($token)->post('/api/admin/packages/'.$package->id.'/'.$kind,
        packageImageStepPayload($kind, UploadedFile::fake()->image('replacement.png'), $item->id),
        ['Accept' => 'application/json'])->assertInternalServerError();

    $this->assertDatabaseHas($table, ['id' => $item->id, 'image' => $oldPath]);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 6]);
    Storage::disk('public')->assertExists($oldPath);
    expect(Storage::disk('public')->allFiles('packages'))->toBe([$oldPath]);
    Exceptions::assertReported(QueryException::class);
})->with(['itinerary' => ['itineraries', 'package_itineraries'], 'gallery' => ['photos', 'package_images']]);

test('successful replacements and explicit removals clean up files after commit', function (string $kind) {
    Storage::fake('public');
    $token = packageObserverToken();
    $package = Package::factory()->atStep(6)->create();
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('packages/'.$kind, 'public');
    $deletedPath = UploadedFile::fake()->image('removed.jpg')->store('packages/'.$kind, 'public');
    $item = $kind === 'photos'
        ? PackageImage::factory()->for($package)->create(['image' => $oldPath])
        : PackageItinerary::factory()->for($package)->create(['image' => $oldPath]);
    $deleted = $kind === 'photos'
        ? PackageImage::factory()->for($package)->create(['image' => $deletedPath])
        : PackageItinerary::factory()->for($package)->create(['image' => $deletedPath]);
    $deletedField = $kind === 'photos' ? 'deleted_image_ids' : 'deleted_itinerary_ids';

    $response = $this->withToken($token)->post('/api/admin/packages/'.$package->id.'/'.$kind, [
        ...packageImageStepPayload($kind, UploadedFile::fake()->image('replacement.png'), $item->id),
        $deletedField => [$deleted->id],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.current_step', 6);

    $newPath = $response->json('data.'.($kind === 'photos' ? 'images' : 'itineraries').'.0.image');
    Storage::disk('public')->assertExists($newPath)->assertMissing([$oldPath, $deletedPath]);
    $this->assertModelMissing($deleted);
    $this->assertDatabaseHas($item->getTable(), ['id' => $item->id, 'image' => $newPath]);
})->with(['itineraries', 'photos']);

test('step persistence failure rolls back relationship sync and progress together', function () {
    $token = packageObserverToken();
    $package = Package::factory()->atStep(2)->create();
    $oldCategory = Category::factory()->create();
    $oldDestination = Destination::factory()->create();
    $newCategory = Category::factory()->create();
    $newDestination = Destination::factory()->create();
    $package->categories()->attach($oldCategory);
    $package->destinations()->attach($oldDestination);
    DB::unprepared("CREATE TRIGGER fail_progress BEFORE UPDATE ON packages BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/categories-destinations', [
        'category_ids' => [$newCategory->id], 'destination_ids' => [$newDestination->id],
    ])->assertInternalServerError();

    $this->assertDatabaseHas('category_package', ['package_id' => $package->id, 'category_id' => $oldCategory->id]);
    $this->assertDatabaseHas('destination_package', ['package_id' => $package->id, 'destination_id' => $oldDestination->id]);
    $this->assertDatabaseMissing('category_package', ['package_id' => $package->id, 'category_id' => $newCategory->id]);
    $this->assertDatabaseMissing('destination_package', ['package_id' => $package->id, 'destination_id' => $newDestination->id]);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 2]);
    Exceptions::assertReported(QueryException::class);
});

test('progress failure restores removed photos and discards uploads after child writes succeed', function () {
    Storage::fake('public');
    $token = packageObserverToken();
    $package = Package::factory()->atStep(5)->create();
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('packages/photos', 'public');
    $old = PackageImage::factory()->for($package)->create(['image' => $oldPath]);
    DB::unprepared("CREATE TRIGGER fail_progress BEFORE UPDATE ON packages BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $this->withToken($token)->post('/api/admin/packages/'.$package->id.'/photos', [
        ...packageImageStepPayload('photos', UploadedFile::fake()->image('new.png')), 'deleted_image_ids' => [$old->id],
    ], ['Accept' => 'application/json'])->assertInternalServerError();

    $this->assertModelExists($old);
    $this->assertDatabaseCount('package_images', 1);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 5]);
    Storage::disk('public')->assertExists($oldPath);
    expect(Storage::disk('public')->allFiles('packages'))->toBe([$oldPath]);
    Exceptions::assertReported(QueryException::class);
});

test('removing the last photo preserves its file when validation rolls back', function () {
    Storage::fake('public');
    $token = packageObserverToken();
    $package = Package::factory()->atStep(6)->create();
    $path = UploadedFile::fake()->image('old.jpg')->store('packages/photos', 'public');
    $photo = PackageImage::factory()->for($package)->create(['image' => $path]);

    $this->withToken($token)->patchJson('/api/admin/packages/'.$package->id.'/photos', [
        'deleted_image_ids' => [$photo->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('photos');

    $this->assertModelExists($photo);
    Storage::disk('public')->assertExists($path);
    $this->assertDatabaseHas('packages', ['id' => $package->id, 'current_step' => 6]);
});

test('deleting packages removes cascaded records and every file but retains selection records', function () {
    Storage::fake('public');
    $token = packageObserverToken();
    $package = Package::factory()->atStep(6)->create();
    $category = Category::factory()->create();
    $destination = Destination::factory()->create();
    $package->categories()->attach($category);
    $package->destinations()->attach($destination);
    $itineraryPath = UploadedFile::fake()->image('day.jpg')->store('packages/itineraries', 'public');
    $photoPath = UploadedFile::fake()->image('gallery.png')->store('packages/photos', 'public');
    $itinerary = PackageItinerary::factory()->for($package)->create(['image' => $itineraryPath]);
    $photo = PackageImage::factory()->for($package)->create(['image' => $photoPath]);
    PackageImage::factory()->for($package)->create(['image' => 'packages/photos/missing.jpg']);

    $this->withToken($token)->deleteJson('/api/admin/packages/'.$package->id)->assertOk()->assertExactJson([
        'status' => true, 'data' => null, 'pagination' => null, 'message' => 'Package deleted successfully.',
    ]);

    $this->assertModelMissing($package);
    $this->assertModelMissing($itinerary);
    $this->assertModelMissing($photo);
    $this->assertDatabaseCount('category_package', 0);
    $this->assertDatabaseCount('destination_package', 0);
    $this->assertModelExists($category);
    $this->assertModelExists($destination);
    Storage::disk('public')->assertMissing([$itineraryPath, $photoPath]);
});

test('failed package deletion retains records and files', function () {
    Storage::fake('public');
    $token = packageObserverToken();
    $package = Package::factory()->atStep(6)->create();
    $path = UploadedFile::fake()->image('old.jpg')->store('packages/photos', 'public');
    $photo = PackageImage::factory()->for($package)->create(['image' => $path]);
    DB::unprepared("CREATE TRIGGER fail_delete BEFORE DELETE ON packages BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $this->withToken($token)->deleteJson('/api/admin/packages/'.$package->id)->assertInternalServerError()
        ->assertJsonPath('message', 'Unable to delete the package. Please try again later.');

    $this->assertModelExists($package);
    $this->assertModelExists($photo);
    Storage::disk('public')->assertExists($path);
    Exceptions::assertReported(QueryException::class);
});

test('replacement files survive inner commits and old files are removed only after outer commit', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('packages/photos', 'public');
    $photo = PackageImage::factory()->create(['image' => $path]);
    DB::beginTransaction();

    DB::transaction(function () use ($photo): void {
        $photo->uploadedImage = UploadedFile::fake()->image('new.png');
        $photo->save();
    });
    $newPath = $photo->image;

    Storage::disk('public')->assertExists([$path, $newPath]);
    DB::commit();
    Storage::disk('public')->assertExists($newPath)->assertMissing($path);
    $this->assertDatabaseHas('package_images', ['id' => $photo->id, 'image' => $newPath]);
});

test('outer rollback removes uploads from committed savepoints and restores old paths', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('packages/itineraries', 'public');
    $itinerary = PackageItinerary::factory()->create(['image' => $path]);
    DB::beginTransaction();

    DB::transaction(function () use ($itinerary): void {
        $itinerary->uploadedImage = UploadedFile::fake()->image('new.png');
        $itinerary->save();
    });
    $newPath = $itinerary->image;
    DB::rollBack();

    Storage::disk('public')->assertExists($path)->assertMissing($newPath);
    $this->assertDatabaseHas('package_itineraries', ['id' => $itinerary->id, 'image' => $path]);
});

test('rolling back a later savepoint preserves earlier uploads until commit', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('packages/photos', 'public');
    $photo = PackageImage::factory()->create(['image' => $path]);
    DB::beginTransaction();
    DB::transaction(function () use ($photo): void {
        $photo->uploadedImage = UploadedFile::fake()->image('first.png');
        $photo->save();
    });
    $firstPath = $photo->image;

    DB::beginTransaction();
    $photo->uploadedImage = UploadedFile::fake()->image('second.png');
    $photo->save();
    $secondPath = $photo->image;
    DB::rollBack();

    Storage::disk('public')->assertExists([$path, $firstPath])->assertMissing($secondPath);
    DB::commit();
    Storage::disk('public')->assertExists($firstPath)->assertMissing($path);
    $this->assertDatabaseHas('package_images', ['id' => $photo->id, 'image' => $firstPath]);
});

test('package deletion cleanup is discarded if the outer transaction rolls back', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('packages/photos', 'public');
    $package = Package::factory()->create();
    $photo = PackageImage::factory()->for($package)->create(['image' => $path]);
    DB::beginTransaction();

    DB::transaction(fn (): ?bool => $package->delete());
    Storage::disk('public')->assertExists($path);
    DB::rollBack();

    $this->assertModelExists($package);
    $this->assertModelExists($photo);
    Storage::disk('public')->assertExists($path);
});

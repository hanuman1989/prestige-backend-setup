<?php

use App\Models\Car;
use App\Models\Destination;
use App\Models\Enquiry;
use App\Models\Package;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

uses(LazilyRefreshDatabase::class);

test('guests can submit enquiries for every supported source with optional fields omitted', function (string $source) {
    $response = $this->postJson('/api/enquiries', [
        'source' => $source, 'name' => 'Priya Sharma', 'mobile' => '+91 09876543210',
    ]);

    $response->assertCreated()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('message', 'Enquiry submitted successfully.')
        ->assertJsonPath('data.source', $source)->assertJsonPath('data.name', 'Priya Sharma')
        ->assertJsonPath('data.mobile', '+91 09876543210')
        ->assertJsonPath('data.destination_id', null)->assertJsonPath('data.package_id', null)
        ->assertJsonPath('data.car_id', null)->assertJsonPath('data.email', null)
        ->assertJsonPath('data.subject', null)->assertJsonPath('data.message', null)
        ->assertJsonPath('data.travel_date', null)->assertJsonPath('data.adults', null)->assertJsonPath('data.children', null);
    $this->assertDatabaseHas('enquiries', [
        'id' => $response->json('data.id'), 'source' => $source, 'name' => 'Priya Sharma', 'mobile' => '+91 09876543210',
        'destination_id' => null, 'package_id' => null, 'car_id' => null, 'email' => null,
        'subject' => null, 'message' => null, 'travel_date' => null, 'adults' => null, 'children' => null,
    ]);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('data')))->toBe([
        'id', 'source', 'destination_id', 'package_id', 'car_id', 'name', 'email', 'mobile',
        'subject', 'message', 'travel_date', 'adults', 'children', 'created_at', 'updated_at',
    ]);
})->with(['destination', 'package', 'car_rental', 'contact']);

test('enquiries save related records and cast form values in the response', function () {
    $destination = Destination::factory()->create();
    $package = Package::factory()->atStep(6)->create();
    $car = Car::factory()->create();
    $payload = [
        'source' => 'package', 'destination_id' => (string) $destination->id,
        'package_id' => (string) $package->id, 'car_id' => (string) $car->id,
        'name' => 'Priya Sharma', 'email' => 'priya@example.com', 'mobile' => '00919876543210',
        'subject' => 'Jaipur trip', 'message' => 'Please share the itinerary.',
        'travel_date' => '2027-02-15', 'adults' => '2', 'children' => '0',
    ];

    $response = $this->post('/api/enquiries', $payload);

    $response->assertCreated()->assertJsonPath('data.destination_id', $destination->id)
        ->assertJsonPath('data.package_id', $package->id)->assertJsonPath('data.car_id', $car->id)
        ->assertJsonPath('data.email', 'priya@example.com')->assertJsonPath('data.mobile', '00919876543210')
        ->assertJsonPath('data.subject', 'Jaipur trip')->assertJsonPath('data.message', 'Please share the itinerary.')
        ->assertJsonPath('data.travel_date', '2027-02-15')->assertJsonPath('data.adults', 2)->assertJsonPath('data.children', 0);
    $this->assertDatabaseHas('enquiries', Arr::except($payload, 'travel_date'));
    $enquiry = Enquiry::findOrFail($response->json('data.id'));
    expect($enquiry->travel_date->toDateString())->toBe('2027-02-15');
    expect($enquiry->destination->is($destination))->toBeTrue();
    expect($enquiry->package->is($package))->toBeTrue();
    expect($enquiry->car->is($car))->toBeTrue();
});

test('optional enquiry fields accept explicit nulls', function () {
    $response = $this->postJson('/api/enquiries', [
        'source' => 'contact', 'name' => 'Priya Sharma', 'mobile' => '09876543210',
        'destination_id' => null, 'package_id' => null, 'car_id' => null,
        'email' => null, 'subject' => null, 'message' => null, 'travel_date' => null, 'adults' => null, 'children' => null,
    ]);

    $response->assertCreated()->assertJsonPath('data.travel_date', null)->assertJsonPath('data.email', null);
    $this->assertDatabaseHas('enquiries', ['id' => $response->json('data.id'), 'travel_date' => null, 'adults' => null, 'children' => null]);
});

test('missing enquiry fields return the standard 422 validation envelope', function () {
    $response = $this->post('/api/enquiries', []);

    $response->assertUnprocessable()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Validation failed.',
        'errors' => [
            'source' => ['The source field is required.'],
            'name' => ['The name field is required.'],
            'mobile' => ['The mobile field is required.'],
        ],
    ]);
    $this->assertDatabaseCount('enquiries', 0);
});

test('invalid enquiry values return 422 without saving a record', function (array $changes, string $field, string $message) {
    $payload = array_replace(['source' => 'contact', 'name' => 'Priya Sharma', 'mobile' => '09876543210'], $changes);

    $response = $this->postJson('/api/enquiries', $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
    $this->assertDatabaseCount('enquiries', 0);
})->with([
    'unsupported source' => [['source' => 'website'], 'source', 'The selected source is invalid.'],
    'array source' => [['source' => ['contact']], 'source', 'The source field must be a string.'],
    'null source' => [['source' => null], 'source', 'The source field is required.'],
    'array name' => [['name' => ['Priya']], 'name', 'The name field must be a string.'],
    'long name' => [['name' => str_repeat('a', 256)], 'name', 'The name field must not be greater than 255 characters.'],
    'empty name' => [['name' => ''], 'name', 'The name field is required.'],
    'numeric mobile' => [['mobile' => 9876543210], 'mobile', 'The mobile field must be a string.'],
    'long mobile' => [['mobile' => str_repeat('1', 31)], 'mobile', 'The mobile field must not be greater than 30 characters.'],
    'empty mobile' => [['mobile' => ''], 'mobile', 'The mobile field is required.'],
    'invalid email' => [['email' => 'invalid'], 'email', 'The email field must be a valid email address.'],
    'array email' => [['email' => ['priya@example.com']], 'email', 'The email field must be a string.'],
    'long email' => [['email' => str_repeat('a', 256).'@example.com'], 'email', 'The email field must not be greater than 255 characters.'],
    'invalid destination ID' => [['destination_id' => 'jaipur'], 'destination_id', 'The destination id field must be an integer.'],
    'missing destination' => [['destination_id' => 999999], 'destination_id', 'The selected destination id is invalid.'],
    'invalid package ID' => [['package_id' => 'tour'], 'package_id', 'The package id field must be an integer.'],
    'missing package' => [['package_id' => 999999], 'package_id', 'The selected package id is invalid.'],
    'invalid car ID' => [['car_id' => 'suv'], 'car_id', 'The car id field must be an integer.'],
    'missing car' => [['car_id' => 999999], 'car_id', 'The selected car id is invalid.'],
    'array subject' => [['subject' => ['Trip']], 'subject', 'The subject field must be a string.'],
    'long subject' => [['subject' => str_repeat('a', 256)], 'subject', 'The subject field must not be greater than 255 characters.'],
    'array message' => [['message' => ['Trip']], 'message', 'The message field must be a string.'],
    'long message' => [['message' => str_repeat('a', 65536)], 'message', 'The message field must not be greater than 65535 characters.'],
    'invalid travel date' => [['travel_date' => '2027-02-30'], 'travel_date', 'The travel date field must match the format Y-m-d.'],
    'incorrect date format' => [['travel_date' => '15/02/2027'], 'travel_date', 'The travel date field must match the format Y-m-d.'],
    'zero adults' => [['adults' => 0], 'adults', 'The adults field must be at least 1.'],
    'fractional adults' => [['adults' => 1.5], 'adults', 'The adults field must be an integer.'],
    'too many adults' => [['adults' => 65536], 'adults', 'The adults field must not be greater than 65535.'],
    'negative children' => [['children' => -1], 'children', 'The children field must be at least 0.'],
    'fractional children' => [['children' => 0.5], 'children', 'The children field must be an integer.'],
    'too many children' => [['children' => 65536], 'children', 'The children field must not be greater than 65535.'],
]);

test('enquiries accept boundary values without truncating submitted data', function () {
    $payload = [
        'source' => 'contact', 'name' => str_repeat('a', 255), 'mobile' => str_repeat('1', 30),
        'subject' => str_repeat('b', 255), 'message' => str_repeat('c', 65535),
        'adults' => 65535, 'children' => 65535,
    ];

    $response = $this->postJson('/api/enquiries', $payload);

    $response->assertCreated()->assertJsonPath('data.name', $payload['name'])->assertJsonPath('data.message', $payload['message'])
        ->assertJsonPath('data.adults', 65535)->assertJsonPath('data.children', 65535);
    $this->assertDatabaseHas('enquiries', $payload);
});

test('client supplied IDs and timestamps do not overwrite server generated enquiry fields', function () {
    $this->travelTo(now()->startOfSecond());

    $response = $this->postJson('/api/enquiries', [
        'id' => 999999, 'created_at' => '2000-01-01', 'updated_at' => '2000-01-01',
        'source' => 'contact', 'name' => 'Priya Sharma', 'mobile' => '09876543210', 'status' => true,
    ]);

    $response->assertCreated()->assertJsonPath('data.created_at', now()->toISOString())
        ->assertJsonPath('data.updated_at', now()->toISOString())->assertJsonMissingPath('data.status');
    expect($response->json('data.id'))->not->toBe(999999);
    $this->assertDatabaseMissing('enquiries', ['id' => 999999]);
    $this->assertDatabaseHas('enquiries', ['id' => $response->json('data.id'), 'created_at' => now()->toDateTimeString()]);
});

test('deleting related catalogue records preserves enquiries and clears their reference', function (string $model, string $relationship, string $foreignKey) {
    $related = $model::factory()->create();
    $enquiry = Enquiry::factory()->for($related, $relationship)->create();

    $related->delete();

    $this->assertModelExists($enquiry);
    $this->assertDatabaseHas('enquiries', ['id' => $enquiry->id, $foreignKey => null, 'name' => $enquiry->name]);
})->with([
    'destination' => [Destination::class, 'destination', 'destination_id'],
    'package' => [Package::class, 'package', 'package_id'],
    'car' => [Car::class, 'car', 'car_id'],
]);

test('database failures return a safe 500 enquiry response without persistence', function () {
    DB::unprepared("CREATE TRIGGER fail_enquiry_insert BEFORE INSERT ON enquiries BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->postJson('/api/enquiries', ['source' => 'contact', 'name' => 'Priya Sharma', 'mobile' => '09876543210']);

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unable to submit the enquiry. Please try again later.',
    ]);
    $this->assertDatabaseCount('enquiries', 0);
    Exceptions::assertReported(QueryException::class);
});

test('enquiry submissions above the IP rate limit return 429 without saving another record', function () {
    $payload = ['source' => 'contact', 'name' => 'Priya Sharma', 'mobile' => '09876543210'];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api/enquiries', $payload)->assertCreated();
    }

    $response = $this->postJson('/api/enquiries', $payload);

    $response->assertTooManyRequests()->assertHeader('Retry-After')->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Too many requests. Please try again later.',
    ]);
    $this->assertDatabaseCount('enquiries', 10);
});

test('enquiries do not expose public listing or detail endpoints', function () {
    $enquiry = Enquiry::factory()->create();

    $this->getJson('/api/enquiries')->assertMethodNotAllowed();
    $this->getJson('/api/enquiries/'.$enquiry->id)->assertNotFound();
});

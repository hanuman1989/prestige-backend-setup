<?php

namespace App\Observers;

use App\Models\Car;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class CarObserver
{
    /** @var array<string, array{disk: string, connection: string, level: int}> */
    private array $pendingImages = [];

    /**
     * Use a fresh filename per save because UploadedFile caches its hash name.
     */
    public function saving(Car $car): void
    {
        if ($car->uploadedImage === null) {
            return;
        }

        $connection = $car->getConnection();

        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Car image uploads must be saved inside a database transaction.');
        }

        $disk = config('filesystems.car_images_disk');
        $filename = Str::random(40).'.'.$car->uploadedImage->extension();
        $path = 'cars/'.$filename;

        $this->pendingImages[$path] = [
            'disk' => $disk,
            'connection' => $connection->getName(),
            'level' => $connection->transactionLevel(),
        ];

        if ($car->uploadedImage->storeAs('cars', $filename, $disk) === false) {
            throw new RuntimeException('Unable to store the car image.');
        }

        $car->image = $path;
        $car->uploadedImage = null;
    }

    public function updated(Car $car): void
    {
        if ($car->wasChanged('image')) {
            $this->deleteAfterCommit($car, $car->getRawOriginal('image'));
        }
    }

    public function deleted(Car $car): void
    {
        $this->deleteAfterCommit($car, $car->getRawOriginal('image'));
    }

    /**
     * Promote uploads from committed savepoints to their enclosing transaction.
     */
    public function committed(TransactionCommitted $event): void
    {
        $level = $event->connection->transactionLevel();

        foreach ($this->pendingImages as $path => $image) {
            if ($image['connection'] !== $event->connectionName) {
                continue;
            }

            if ($level === 0) {
                unset($this->pendingImages[$path]);
            } else {
                $this->pendingImages[$path]['level'] = min($image['level'], $level);
            }
        }
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        foreach ($this->pendingImages as $path => $image) {
            if ($image['connection'] === $event->connectionName && $image['level'] > $event->connection->transactionLevel()) {
                $this->deleteImage($image['disk'], $path);
                unset($this->pendingImages[$path]);
            }
        }
    }

    private function deleteAfterCommit(Car $car, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        $disk = config('filesystems.car_images_disk');

        $car->getConnection()->afterCommit(fn () => $this->deleteImage($disk, $path));
    }

    /**
     * Report cleanup failures without undoing an already committed database result.
     */
    private function deleteImage(string $disk, string $path): void
    {
        try {
            if (! Storage::disk($disk)->delete($path)) {
                report(new RuntimeException('Unable to delete a car image.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

<?php

namespace App\Observers;

use App\Models\CarCategory;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class CarCategoryObserver
{
    /** @var array<string, array{disk: string, connection: string, level: int}> */
    private array $pendingImages = [];

    /**
     * Use a fresh filename per save because UploadedFile caches its hash name.
     */
    public function saving(CarCategory $carCategory): void
    {
        if ($carCategory->uploadedImage === null) {
            return;
        }

        $connection = $carCategory->getConnection();

        if ($connection->transactionLevel() === 0) {
            throw new LogicException('CarCategory image uploads must be saved inside a database transaction.');
        }

        $disk = config('filesystems.car_category_images_disk');
        $filename = Str::random(40).'.'.$carCategory->uploadedImage->extension();
        $path = 'car-categories/'.$filename;

        $this->pendingImages[$path] = [
            'disk' => $disk,
            'connection' => $connection->getName(),
            'level' => $connection->transactionLevel(),
        ];

        if ($carCategory->uploadedImage->storeAs('car-categories', $filename, $disk) === false) {
            throw new RuntimeException('Unable to store the car category image.');
        }

        $carCategory->image = $path;
        $carCategory->uploadedImage = null;
    }

    public function updated(CarCategory $carCategory): void
    {
        if ($carCategory->wasChanged('image')) {
            $this->deleteAfterCommit($carCategory, $carCategory->getRawOriginal('image'));
        }
    }

    public function deleted(CarCategory $carCategory): void
    {
        $this->deleteAfterCommit($carCategory, $carCategory->getRawOriginal('image'));
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

    private function deleteAfterCommit(CarCategory $carCategory, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        $disk = config('filesystems.car_category_images_disk');

        $carCategory->getConnection()->afterCommit(fn () => $this->deleteImage($disk, $path));
    }

    /**
     * Report cleanup failures without undoing an already committed database result.
     */
    private function deleteImage(string $disk, string $path): void
    {
        try {
            if (! Storage::disk($disk)->delete($path)) {
                report(new RuntimeException('Unable to delete a car category image.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

<?php

namespace App\Observers;

use App\Models\Package;
use App\Models\PackageImage;
use App\Models\PackageItinerary;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class PackageObserver
{
    /** @var array<string, array{disk: string, connection: string, level: int}> */
    private array $pendingImages = [];

    /**
     * Use a fresh filename per save because UploadedFile caches its hash name.
     */
    public function saving(Package|PackageImage|PackageItinerary $model): void
    {
        if ($model instanceof Package || $model->uploadedImage === null) {
            return;
        }

        $connection = $model->getConnection();

        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Package image uploads must be saved inside a database transaction.');
        }

        $disk = config('filesystems.package_images_disk');
        $directory = $model instanceof PackageItinerary ? 'packages/itineraries' : 'packages/photos';
        $filename = Str::random(40).'.'.$model->uploadedImage->extension();
        $path = $directory.'/'.$filename;

        $this->pendingImages[$path] = [
            'disk' => $disk,
            'connection' => $connection->getName(),
            'level' => $connection->transactionLevel(),
        ];

        if ($model->uploadedImage->storeAs($directory, $filename, $disk) === false) {
            throw new RuntimeException('Unable to store the package image.');
        }

        $model->image = $path;
        $model->uploadedImage = null;
    }

    public function updated(Package|PackageImage|PackageItinerary $model): void
    {
        if (! $model instanceof Package && $model->wasChanged('image')) {
            $this->deleteAfterCommit($model, $model->getRawOriginal('image'));
        }
    }

    public function deleted(Package|PackageImage|PackageItinerary $model): void
    {
        if (! $model instanceof Package) {
            $this->deleteAfterCommit($model, $model->getRawOriginal('image'));
        }
    }

    /**
     * Cascades bypass child events, so collect files before deleting the parent.
     */
    public function deleting(Package|PackageImage|PackageItinerary $model): void
    {
        if (! $model instanceof Package) {
            return;
        }

        foreach ($model->itineraries()->pluck('image')->merge($model->images()->pluck('image')) as $path) {
            if ($path !== null) {
                $disk = config('filesystems.package_images_disk');
                $model->getConnection()->afterCommit(fn () => $this->deleteImage($disk, $path));
            }
        }
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

    private function deleteAfterCommit(PackageImage|PackageItinerary $model, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        $disk = config('filesystems.package_images_disk');

        $model->getConnection()->afterCommit(fn () => $this->deleteImage($disk, $path));
    }

    /**
     * Report cleanup failures without undoing an already committed database result.
     */
    private function deleteImage(string $disk, string $path): void
    {
        try {
            if (! Storage::disk($disk)->delete($path)) {
                report(new RuntimeException('Unable to delete a package image.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

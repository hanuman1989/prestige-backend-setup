<?php

namespace App\Observers;

use App\Models\Destination;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class DestinationObserver
{
    /** @var array<string, array{disk: string, connection: string, level: int}> */
    private array $pendingImages = [];

    /**
     * Use a fresh filename per save because UploadedFile caches its hash name.
     */
    public function saving(Destination $destination): void
    {
        if ($destination->uploadedImage === null && $destination->uploadedBanner === null) {
            return;
        }

        $connection = $destination->getConnection();

        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Destination image uploads must be saved inside a database transaction.');
        }

        $disk = config('filesystems.destination_images_disk');

        foreach (['image' => 'uploadedImage', 'banner' => 'uploadedBanner'] as $attribute => $uploadProperty) {
            $upload = $destination->{$uploadProperty};

            if ($upload === null) {
                continue;
            }

            $directory = $attribute === 'banner' ? 'destinations/banners' : 'destinations';
            $filename = Str::random(40).'.'.$upload->extension();
            $path = $directory.'/'.$filename;

            $this->pendingImages[$path] = [
                'disk' => $disk,
                'connection' => $connection->getName(),
                'level' => $connection->transactionLevel(),
            ];

            if ($upload->storeAs($directory, $filename, $disk) === false) {
                throw new RuntimeException('Unable to store the destination '.$attribute.'.');
            }

            $destination->{$attribute} = $path;
            $destination->{$uploadProperty} = null;
        }
    }

    public function updated(Destination $destination): void
    {
        foreach (['image', 'banner'] as $attribute) {
            if ($destination->wasChanged($attribute)) {
                $this->deleteAfterCommit($destination, $destination->getRawOriginal($attribute));
            }
        }
    }

    public function deleted(Destination $destination): void
    {
        $this->deleteAfterCommit($destination, $destination->getRawOriginal('image'));
        $this->deleteAfterCommit($destination, $destination->getRawOriginal('banner'));
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

    private function deleteAfterCommit(Destination $destination, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        $disk = config('filesystems.destination_images_disk');

        $destination->getConnection()->afterCommit(fn () => $this->deleteImage($disk, $path));
    }

    /**
     * Report cleanup failures without undoing an already committed database result.
     */
    private function deleteImage(string $disk, string $path): void
    {
        try {
            if (! Storage::disk($disk)->delete($path)) {
                report(new RuntimeException('Unable to delete a destination image.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

<?php

namespace App\Support\Catalog;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use RuntimeException;
use Throwable;

class CatalogImageStorage
{
    private const SCOPES = ['categories', 'packages'];

    public function __construct(
        private readonly ManagedCatalogPath $managedPaths,
        private readonly FilesystemManager $filesystems,
    ) {}

    public function store(UploadedFile $file, string $scope): string
    {
        Validator::make(
            ['image' => $file, 'scope' => $scope],
            [
                'image' => [
                    'required',
                    File::image()
                        ->types(['jpg', 'jpeg', 'png', 'webp'])
                        ->max(5 * 1024)
                        ->dimensions(
                            Rule::dimensions()
                                ->minWidth(600)
                                ->minHeight(400)
                                ->maxWidth(6000)
                                ->maxHeight(6000),
                        ),
                ],
                'scope' => ['required', 'string', Rule::in(self::SCOPES)],
            ],
        )->validate();

        $extension = match ($file->guessExtension()) {
            'jpg', 'jpeg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => throw new RuntimeException('Validated catalog image has an unsupported media type.'),
        };
        $directory = 'catalog/'.$scope.'/'.Str::uuid();
        $filename = Str::uuid().'.'.$extension;
        $storedPath = $this->filesystems->disk('public')->putFileAs($directory, $file, $filename);

        if (! is_string($storedPath)) {
            throw new RuntimeException('Catalog image could not be stored.');
        }

        return $storedPath;
    }

    public function deleteManaged(iterable $paths): void
    {
        $disk = null;

        foreach ($paths as $path) {
            if (! is_string($path) || ! $this->managedPaths->isManaged($path)) {
                continue;
            }

            try {
                $disk ??= $this->filesystems->disk('public');

                if (! $disk->delete($path)) {
                    Log::warning('Catalog image deletion failed.', ['path' => $path]);
                }
            } catch (Throwable $exception) {
                Log::warning('Catalog image deletion failed.', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
            }
        }
    }
}

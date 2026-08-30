<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Cloudinary uploads, extracted from the controllers so item photos and GCash
 * payment proofs share one validated path.
 *
 * File type and size are checked here rather than trusted from the client, and
 * the same limits apply wherever an upload is accepted.
 */
class PhotoUploader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    /** Payment proofs may also be a PDF receipt exported from GCash. */
    public const ALLOWED_PROOF_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    /**
     * Validate a set of uploaded images.
     *
     * @param  array<int, UploadedFile|null>  $files
     * @return array<string, string>|null  an error payload, or null when valid
     */
    public function validateImages(array $files, array $allowedMimeTypes = self::ALLOWED_MIME_TYPES): ?array
    {
        if ($files === []) {
            return [
                'message' => 'No file uploaded',
                'error' => 'At least one file is required',
            ];
        }

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return [
                    'message' => 'Invalid file uploaded',
                    'error' => 'One or more files are invalid',
                ];
            }

            if (!in_array($file->getMimeType(), $allowedMimeTypes, true)) {
                $labels = implode(', ', array_map(
                    fn ($type) => strtoupper(explode('/', $type)[1]),
                    $allowedMimeTypes
                ));

                return [
                    'message' => 'Invalid file type',
                    'error' => "Only {$labels} files are allowed",
                ];
            }

            if ($file->getSize() > self::MAX_BYTES) {
                return [
                    'message' => 'File too large',
                    'error' => 'Maximum file size is 5MB',
                ];
            }
        }

        return null;
    }

    /**
     * Upload one file and return its secure URL, or null on failure.
     */
    public function upload(UploadedFile $file, string $folder): ?string
    {
        try {
            $result = $this->cloudinary()->uploadApi()->upload($file->getRealPath(), [
                'folder' => $folder,
                'resource_type' => $file->getMimeType() === 'application/pdf' ? 'raw' : 'image',
            ]);

            return $result['secure_url'] ?? null;
        } catch (\Exception $e) {
            Log::error('Cloudinary upload failed', [
                'folder' => $folder,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Upload item photos and persist the resulting rows.
     *
     * A single failed photo does not abort the rest, matching the previous
     * behaviour of the item creation endpoint.
     *
     * @param  array<int, UploadedFile>  $files
     * @return list<string>
     */
    public function uploadMany(array $files, string $folder, int $itemId): array
    {
        $urls = [];

        foreach ($files as $file) {
            $url = $this->upload($file, $folder);

            if ($url === null) {
                continue;
            }

            \App\Models\ItemPhoto::create([
                'item_id' => $itemId,
                'photo_url' => $url,
            ]);

            $urls[] = $url;

            Log::info('Photo uploaded successfully', ['item_id' => $itemId, 'photo_url' => $url]);
        }

        return $urls;
    }

    private function cloudinary(): Cloudinary
    {
        return new Cloudinary([
            'cloud' => [
                'cloud_name' => config('services.cloudinary.cloud_name', env('CLOUDINARY_CLOUD_NAME')),
                'api_key' => config('services.cloudinary.key', env('CLOUDINARY_KEY')),
                'api_secret' => config('services.cloudinary.secret', env('CLOUDINARY_SECRET')),
            ],
        ]);
    }
}

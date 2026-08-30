<?php

namespace Tests\Support;

use App\Services\PhotoUploader;
use Illuminate\Http\UploadedFile;

/**
 * Stands in for Cloudinary so the tests never touch the network.
 *
 * Validation is inherited unchanged - the file type and size rules are part of
 * what the tests are checking - and only the upload itself is faked.
 */
class FakePhotoUploader extends PhotoUploader
{
    /** @var list<string> */
    public array $uploaded = [];

    public function upload(UploadedFile $file, string $folder): ?string
    {
        $url = "https://fake-cdn.test/{$folder}/" . $file->hashName();
        $this->uploaded[] = $url;

        return $url;
    }
}

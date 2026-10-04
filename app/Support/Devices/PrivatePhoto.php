<?php

namespace App\Support\Devices;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PrivatePhoto
{
    public function store(UploadedFile $file): array
    {
        $key = 'device-photos/'.Str::uuid().'.png';
        try {
            $source = file_get_contents($file->getPathname());
            if (preg_match('/<(?:\?php|\?=|script\b|svg\b|html\b)/i', $source)) {
                throw new \RuntimeException('Active content is not accepted');
            }
            $info = @getimagesize($file->getPathname());
            if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] * $info[1] > 16000000) {
                throw new \RuntimeException('Invalid image');
            }
            $image = @imagecreatefromstring($source);
            if (! $image) {
                throw new \RuntimeException('Invalid image');
            }
            imagesavealpha($image, true);
            ob_start();
            try {
                if (! imagepng($image)) {
                    throw new \RuntimeException('Encoding failed');
                }
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (strlen($bytes) > 5242880 || ! Storage::disk('device_photos')->put($key, $bytes, ['visibility' => 'private'])) {
                throw new \RuntimeException('Storage failed');
            }

            return ['storage_key' => $key, 'mime' => 'image/png', 'size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes)];
        } catch (Throwable) {
            Storage::disk('device_photos')->delete($key);
            throw ValidationException::withMessages(['photo' => 'The photo could not be saved. Use a JPEG/PNG up to 5 MiB and 16 megapixels, or register without a photo.']);
        }
    }
}

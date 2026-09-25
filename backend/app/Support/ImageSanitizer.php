<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ré-encode une image envoyée : retire les métadonnées (EXIF, position GPS du téléphone)
 * et tout contenu caché, et refuse les images démesurées (bombes de décompression).
 */
final class ImageSanitizer
{
    private const MAX_PIXELS = 40_000_000;

    public static function store(UploadedFile $file, string $dir): string
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['proof' => 'Image illisible ou trop grande.']);
        }
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['proof' => 'Image illisible. Envoie une capture JPG ou PNG.']);
        }

        $png = $info[2] === IMAGETYPE_PNG;
        ob_start();
        $png ? imagepng($image, null, 6) : imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = trim($dir, '/').'/'.Str::random(40).($png ? '.png' : '.jpg');
        Storage::put($path, $bytes);

        return $path;
    }
}

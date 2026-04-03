<?php

declare(strict_types=1);

namespace App\Application\Cms;

final class ImageOptimizationService
{
    /**
     * @return array{path:string,mimeType:?string,convertedToWebp:bool,usedWidth:int,usedHeight:int}
     */
    public function optimizeToWebp(string $inputPath, int $targetMaxWidth, int $quality = 82): array
    {
        if ($targetMaxWidth < 1) {
            $targetMaxWidth = 1200;
        }

        if (!is_file($inputPath)) {
            throw new \RuntimeException('invalid_input_path');
        }

        $mime = null;
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($inputPath);
        } catch (\Throwable) {
            $mime = null;
        }

        $srcInfo = @getimagesize($inputPath);
        $srcW = is_array($srcInfo) ? (int) ($srcInfo[0] ?? 0) : 0;
        $srcH = is_array($srcInfo) ? (int) ($srcInfo[1] ?? 0) : 0;
        if ($srcW < 1 || $srcH < 1) {
            // Can't compute dimensions -> just keep original.
            return [
                'path' => $inputPath,
                'mimeType' => $mime,
                'convertedToWebp' => false,
                'usedWidth' => $srcW,
                'usedHeight' => $srcH,
            ];
        }

        $ratio = $srcW / max(1, $srcH);
        $dstW = $srcW;
        $dstH = $srcH;
        if ($srcW > $targetMaxWidth) {
            $dstW = $targetMaxWidth;
            $dstH = (int) round($dstW / max(0.0001, $ratio));
        }

        // Fallback if GD isn't available or webp output isn't supported.
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            return [
                'path' => $inputPath,
                'mimeType' => $mime,
                'convertedToWebp' => false,
                'usedWidth' => $dstW,
                'usedHeight' => $dstH,
            ];
        }

        $src = $this->loadImageGd($inputPath, $mime);
        if ($src === null) {
            return [
                'path' => $inputPath,
                'mimeType' => $mime,
                'convertedToWebp' => false,
                'usedWidth' => $dstW,
                'usedHeight' => $dstH,
            ];
        }

        $dst = imagecreatetruecolor($dstW, $dstH);

        if ($mime === 'image/png' || $mime === 'image/gif') {
            // Preserve transparency for PNG/GIF inputs when possible.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        $okResize = imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        if ($okResize === false) {
            imagedestroy($dst);
            imagedestroy($src);
            return [
                'path' => $inputPath,
                'mimeType' => $mime,
                'convertedToWebp' => false,
                'usedWidth' => $dstW,
                'usedHeight' => $dstH,
            ];
        }

        $outPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'img_opt_' . bin2hex(random_bytes(8)) . '.webp';
        $okWrite = imagewebp($dst, $outPath, $quality);

        imagedestroy($dst);
        imagedestroy($src);

        if ($okWrite === false || !is_file($outPath)) {
            @unlink($outPath);
            return [
                'path' => $inputPath,
                'mimeType' => $mime,
                'convertedToWebp' => false,
                'usedWidth' => $dstW,
                'usedHeight' => $dstH,
            ];
        }

        return [
            'path' => $outPath,
            'mimeType' => 'image/webp',
            'convertedToWebp' => true,
            'usedWidth' => $dstW,
            'usedHeight' => $dstH,
        ];
    }

    /**
     * @return resource|\GdImage|null
     */
    private function loadImageGd(string $inputPath, ?string $mime)
    {
        // Use mime if known, otherwise rely on extension. Keep this small: we only need common bitmap sources.
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($inputPath),
            'image/png' => @imagecreatefrompng($inputPath),
            'image/webp' => @imagecreatefromwebp($inputPath),
            'image/gif' => @imagecreatefromgif($inputPath),
            default => $this->loadByExtensionFallback($inputPath),
        };
    }

    /**
     * @return resource|\GdImage|null
     */
    private function loadByExtensionFallback(string $inputPath)
    {
        $ext = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($inputPath),
            'png' => @imagecreatefrompng($inputPath),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($inputPath) : null,
            'gif' => @imagecreatefromgif($inputPath),
            default => null,
        };
    }
}


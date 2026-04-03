<?php

declare(strict_types=1);

namespace App\Application\Cms;

final class UnsplashSourceDownloader
{
    /**
     * @return array{path:string,mimeType:?string}
     */
    public function download(string $query, int $width, int $height, int $timeoutSeconds = 30): array
    {
        $query = trim($query);
        if ($query === '') {
            $query = 'art,workshop,crafts,materials';
        }

        // Unsplash Source endpoints are sometimes unstable depending on region.
        // Try multiple URL patterns before failing.
        $query = $this->normalizeUnsplashQuery($query);
        $urls = [
            sprintf('https://source.unsplash.com/%dx%d/?%s', $width, $height, rawurlencode($query)),
            sprintf('https://source.unsplash.com/random/%dx%d?%s', $width, $height, rawurlencode($query)),
            sprintf('https://source.unsplash.com/featured/%dx%d?%s', $width, $height, rawurlencode($query)),
        ];

        $tmpDir = sys_get_temp_dir();
        $tmpPath = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'unsplash_' . bin2hex(random_bytes(8)) . '.img';
        $lastError = 'unknown';
        $downloadOk = false;

        foreach ($urls as $url) {
            $fp = @fopen($tmpPath, 'wb');
            if ($fp === false) {
                throw new \RuntimeException('failed_to_create_tmp_download_file');
            }

            $ch = curl_init($url);
            if ($ch === false) {
                @fclose($fp);
                @unlink($tmpPath);
                throw new \RuntimeException('curl_init_failed');
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FAILONERROR => true,
                CURLOPT_USERAGENT => 'Hypocommerce-CMS/placeholder-replacer',
                // Some Windows/PHP setups don't have an up-to-date CA bundle.
                // Keep this configurable, defaulting to permissive for CLI tooling.
                CURLOPT_SSL_VERIFYPEER => $this->sslVerifyPeer(),
                CURLOPT_SSL_VERIFYHOST => $this->sslVerifyPeer() ? 2 : 0,
            ]);

            $ok = curl_exec($ch);
            $curlErr = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            @fclose($fp);

            if ($ok !== false && $status >= 200 && $status < 300 && is_file($tmpPath) && (filesize($tmpPath) ?: 0) > 0) {
                $downloadOk = true;
                break;
            }

            $lastError = $curlErr !== '' ? $curlErr : ('http_status=' . $status);
            @unlink($tmpPath);
            usleep(250000);
        }

        if (!$downloadOk) {
            throw new \RuntimeException('unsplash_download_failed: ' . $lastError);
        }

        $mime = null;
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        } catch (\Throwable) {
            $mime = null;
        }

        return ['path' => $tmpPath, 'mimeType' => is_string($mime) ? $mime : null];
    }

    private function normalizeUnsplashQuery(string $query): string
    {
        // Keep only meaningful characters for an URL query. Commas are required delimiters for keywords.
        $q = strtolower($query);
        $q = preg_replace('/\s+/', ' ', $q) ?: $q;
        $q = str_replace(' ', ',', $q);
        $q = preg_replace('/[^a-z0-9,_-]/', '', $q) ?: $q;
        return trim($q, ',');
    }

    private function sslVerifyPeer(): bool
    {
        $raw = strtolower(trim((string) ($_ENV['UNSPLASH_SSL_VERIFY'] ?? '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}


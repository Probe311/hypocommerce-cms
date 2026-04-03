<?php

declare(strict_types=1);

namespace App\Application\Cms;

final class OpenverseImageDownloader
{
    /**
     * @return array{path:string,mimeType:?string,sourceUrl:string}
     */
    public function download(string $query, int $timeoutSeconds = 30): array
    {
        $query = trim($query);
        if ($query === '') {
            $query = 'craft workshop texture';
        }
        $queries = [
            $query,
            preg_replace('/[^a-z0-9 ]+/i', ' ', strtolower($query)) ?: $query,
            'craft workshop texture',
            'artisan studio',
            'creative desk materials',
        ];

        $candidateUrl = null;
        foreach ($queries as $q) {
            $q = trim((string) $q);
            if ($q === '') {
                continue;
            }
            $api = 'https://api.openverse.org/v1/images/?q=' . rawurlencode($q) . '&page_size=10&license_type=commercial';
            $json = $this->httpGetString($api, $timeoutSeconds);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
                continue;
            }
            foreach ($decoded['results'] as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $url = isset($result['url']) ? trim((string) $result['url']) : '';
                if ($url === '' || !str_starts_with(strtolower($url), 'http')) {
                    continue;
                }
                $candidateUrl = $url;
                break 2;
            }
        }

        if ($candidateUrl === null) {
            throw new \RuntimeException('openverse_no_image_result');
        }

        $tmpPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'openverse_' . bin2hex(random_bytes(8)) . '.img';
        $this->httpDownloadFile($candidateUrl, $tmpPath, $timeoutSeconds);

        $mime = null;
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        } catch (\Throwable) {
            $mime = null;
        }

        return [
            'path' => $tmpPath,
            'mimeType' => is_string($mime) ? $mime : null,
            'sourceUrl' => $candidateUrl,
        ];
    }

    private function httpGetString(string $url, int $timeoutSeconds): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init_failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'Hypocommerce-CMS/openverse-fetcher',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new \RuntimeException('http_get_failed: ' . ($err !== '' ? $err : 'status=' . $status));
        }
        return $body;
    }

    private function httpDownloadFile(string $url, string $targetPath, int $timeoutSeconds): void
    {
        $fp = @fopen($targetPath, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('failed_to_create_tmp_download_file');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            @fclose($fp);
            @unlink($targetPath);
            throw new \RuntimeException('curl_init_failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'Hypocommerce-CMS/openverse-fetcher',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @fclose($fp);
        if ($ok === false || $status < 200 || $status >= 300 || !is_file($targetPath) || (filesize($targetPath) ?: 0) === 0) {
            @unlink($targetPath);
            throw new \RuntimeException('http_download_failed: ' . ($err !== '' ? $err : 'status=' . $status));
        }
    }
}


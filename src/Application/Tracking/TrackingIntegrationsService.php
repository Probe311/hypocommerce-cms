<?php

declare(strict_types=1);

namespace App\Application\Tracking;

use App\Application\Validation\ValidationException;

/**
 * Configuration des intégrations de tracking (IDs publics uniquement, pas de secrets serveur).
 *
 * @phpstan-type ToolShape array{enabled:bool,consentCategory:string}&array<string,mixed>
 */
final class TrackingIntegrationsService
{
    public const SETTING_KEY = 'tracking_integrations';

    private const MAX_ID_LEN = 200;

    /** @var array<string, ToolShape> */
    private static function defaultTools(): array
    {
        return [
            'gtm' => ['enabled' => false, 'containerId' => '', 'consentCategory' => 'analytics'],
            'ga4' => ['enabled' => false, 'measurementId' => '', 'consentCategory' => 'analytics'],
            'clarity' => ['enabled' => false, 'projectId' => '', 'consentCategory' => 'analytics'],
            'hotjar' => ['enabled' => false, 'siteId' => '', 'consentCategory' => 'analytics'],
            'plausible' => ['enabled' => false, 'domain' => '', 'apiHost' => '', 'consentCategory' => 'analytics'],
            'meta' => ['enabled' => false, 'pixelId' => '', 'consentCategory' => 'marketing'],
            'tiktok' => ['enabled' => false, 'pixelId' => '', 'consentCategory' => 'marketing'],
            'linkedin' => ['enabled' => false, 'partnerId' => '', 'consentCategory' => 'marketing'],
            'googleAds' => ['enabled' => false, 'conversionId' => '', 'sendTo' => '', 'consentCategory' => 'marketing'],
        ];
    }

    /**
     * @return array{version:int, tools: array<string, ToolShape>}
     */
    public static function defaults(): array
    {
        return [
            'version' => 1,
            'tools' => self::defaultTools(),
        ];
    }

    /**
     * @param array<string,mixed> $stored
     *
     * @return array{version:int, tools: array<string, ToolShape>, updatedAt?: string}
     */
    public static function mergeWithDefaults(array $stored): array
    {
        $base = self::defaults();
        $version = isset($stored['version']) ? (int) $stored['version'] : $base['version'];
        if ($version < 1) {
            $version = 1;
        }
        $out = [
            'version' => $version,
            'tools' => self::defaultTools(),
        ];
        if (isset($stored['updatedAt']) && is_string($stored['updatedAt'])) {
            $out['updatedAt'] = $stored['updatedAt'];
        }
        if (!isset($stored['tools']) || !is_array($stored['tools'])) {
            return $out;
        }
        foreach ($out['tools'] as $key => $def) {
            if (!isset($stored['tools'][$key]) || !is_array($stored['tools'][$key])) {
                continue;
            }
            $row = $stored['tools'][$key];
            $merged = $def;
            $merged['enabled'] = (bool) ($row['enabled'] ?? false);
            $cat = isset($row['consentCategory']) ? (string) $row['consentCategory'] : $def['consentCategory'];
            $merged['consentCategory'] = self::normalizeCategory($cat, (string) $def['consentCategory']);
            foreach ($def as $k => $v) {
                if ($k === 'enabled' || $k === 'consentCategory') {
                    continue;
                }
                if (isset($row[$k]) && is_string($row[$k])) {
                    $merged[$k] = self::clipString($row[$k]);
                }
            }
            /** @var ToolShape $merged */
            $out['tools'][$key] = $merged;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $input Document racine ou clé "integrations" déjà extraite
     *
     * @return array{version:int, tools: array<string, ToolShape>, updatedAt:string}
     */
    public static function validateAndMerge(array $input): array
    {
        $merged = self::mergeWithDefaults($input);
        $merged['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);

        foreach ($merged['tools'] as $toolKey => $tool) {
            if (!$tool['enabled']) {
                continue;
            }
            self::assertToolIds($toolKey, $tool);
        }

        return $merged;
    }

    /**
     * @param array{version:int, tools: array<string, ToolShape>, updatedAt?: string} $merged
     *
     * @return array{version:int, updatedAt?: string, tools: array<string, array<string, mixed>>}
     */
    public static function toPublicBundle(array $merged): array
    {
        $publicTools = [];
        foreach ($merged['tools'] as $key => $tool) {
            if (!$tool['enabled']) {
                continue;
            }
            $entry = [
                'consentCategory' => $tool['consentCategory'],
            ];
            switch ($key) {
                case 'gtm':
                    if ($tool['containerId'] !== '') {
                        $entry['containerId'] = $tool['containerId'];
                        $publicTools['gtm'] = $entry;
                    }
                    break;
                case 'ga4':
                    if ($tool['measurementId'] !== '') {
                        $entry['measurementId'] = $tool['measurementId'];
                        $publicTools['ga4'] = $entry;
                    }
                    break;
                case 'clarity':
                    if ($tool['projectId'] !== '') {
                        $entry['projectId'] = $tool['projectId'];
                        $publicTools['clarity'] = $entry;
                    }
                    break;
                case 'hotjar':
                    if ($tool['siteId'] !== '') {
                        $entry['siteId'] = $tool['siteId'];
                        $publicTools['hotjar'] = $entry;
                    }
                    break;
                case 'plausible':
                    if ($tool['domain'] !== '') {
                        $entry['domain'] = $tool['domain'];
                        if (isset($tool['apiHost']) && $tool['apiHost'] !== '') {
                            $entry['apiHost'] = $tool['apiHost'];
                        }
                        $publicTools['plausible'] = $entry;
                    }
                    break;
                case 'meta':
                    if ($tool['pixelId'] !== '') {
                        $entry['pixelId'] = $tool['pixelId'];
                        $publicTools['meta'] = $entry;
                    }
                    break;
                case 'tiktok':
                    if ($tool['pixelId'] !== '') {
                        $entry['pixelId'] = $tool['pixelId'];
                        $publicTools['tiktok'] = $entry;
                    }
                    break;
                case 'linkedin':
                    if ($tool['partnerId'] !== '') {
                        $entry['partnerId'] = $tool['partnerId'];
                        $publicTools['linkedin'] = $entry;
                    }
                    break;
                case 'googleAds':
                    if ($tool['conversionId'] !== '') {
                        $entry['conversionId'] = $tool['conversionId'];
                        if (isset($tool['sendTo']) && $tool['sendTo'] !== '') {
                            $entry['sendTo'] = $tool['sendTo'];
                        }
                        $publicTools['googleAds'] = $entry;
                    }
                    break;
            }
        }

        $out = [
            'version' => $merged['version'],
            'tools' => $publicTools,
        ];
        if (isset($merged['updatedAt'])) {
            $out['updatedAt'] = $merged['updatedAt'];
        }

        return $out;
    }

    private static function normalizeCategory(string $cat, string $fallback): string
    {
        $c = strtolower(trim($cat));

        return $c === 'marketing' ? 'marketing' : ($c === 'analytics' ? 'analytics' : $fallback);
    }

    private static function clipString(string $s): string
    {
        $t = trim($s);

        return strlen($t) > self::MAX_ID_LEN ? substr($t, 0, self::MAX_ID_LEN) : $t;
    }

    /**
     * @param ToolShape $tool
     */
    private static function assertToolIds(string $toolKey, array $tool): void
    {
        switch ($toolKey) {
            case 'gtm':
                $id = (string) ($tool['containerId'] ?? '');
                if ($id === '' || !preg_match('/^GTM-[A-Z0-9]+$/i', $id)) {
                    throw new ValidationException('invalid_tracking_gtm_id');
                }
                break;
            case 'ga4':
                $id = (string) ($tool['measurementId'] ?? '');
                if ($id === '' || !preg_match('/^G-[A-Z0-9]+$/i', $id)) {
                    throw new ValidationException('invalid_tracking_ga4_id');
                }
                break;
            case 'clarity':
                $id = (string) ($tool['projectId'] ?? '');
                if ($id === '' || !preg_match('/^[a-z0-9]{3,40}$/i', $id)) {
                    throw new ValidationException('invalid_tracking_clarity_id');
                }
                break;
            case 'hotjar':
                $id = (string) ($tool['siteId'] ?? '');
                if ($id === '' || !preg_match('/^\d{3,12}$/', $id)) {
                    throw new ValidationException('invalid_tracking_hotjar_id');
                }
                break;
            case 'plausible':
                $d = (string) ($tool['domain'] ?? '');
                if ($d === '' || !preg_match('/^[a-z0-9][-a-z0-9.]*[a-z0-9]$/i', $d)) {
                    throw new ValidationException('invalid_tracking_plausible_domain');
                }
                $h = (string) ($tool['apiHost'] ?? '');
                if ($h !== '' && !preg_match('/^[a-z0-9][-a-z0-9.]*[a-z0-9]$/i', $h)) {
                    throw new ValidationException('invalid_tracking_plausible_host');
                }
                break;
            case 'meta':
                $id = (string) ($tool['pixelId'] ?? '');
                if ($id === '' || !preg_match('/^\d{5,20}$/', $id)) {
                    throw new ValidationException('invalid_tracking_meta_pixel_id');
                }
                break;
            case 'tiktok':
                $id = (string) ($tool['pixelId'] ?? '');
                if ($id === '' || !preg_match('/^[A-Z0-9]{3,40}$/i', $id)) {
                    throw new ValidationException('invalid_tracking_tiktok_pixel_id');
                }
                break;
            case 'linkedin':
                $id = (string) ($tool['partnerId'] ?? '');
                if ($id === '' || !preg_match('/^\d{3,12}$/', $id)) {
                    throw new ValidationException('invalid_tracking_linkedin_partner_id');
                }
                break;
            case 'googleAds':
                $cid = (string) ($tool['conversionId'] ?? '');
                if ($cid === '' || !preg_match('/^AW-[0-9]+$/i', $cid)) {
                    throw new ValidationException('invalid_tracking_google_ads_conversion_id');
                }
                $st = (string) ($tool['sendTo'] ?? '');
                if ($st !== '' && strlen($st) > self::MAX_ID_LEN) {
                    throw new ValidationException('invalid_tracking_google_ads_send_to');
                }
                break;
        }
    }
}

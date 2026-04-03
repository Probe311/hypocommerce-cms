<?php

declare(strict_types=1);

namespace App\Tests\Application\Tracking;

use App\Application\Tracking\TrackingIntegrationsService;
use App\Application\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class TrackingIntegrationsServiceTest extends TestCase
{
    public function testDefaultsHasAllTools(): void
    {
        $d = TrackingIntegrationsService::defaults();
        self::assertSame(1, $d['version']);
        self::assertArrayHasKey('gtm', $d['tools']);
        self::assertFalse($d['tools']['gtm']['enabled']);
    }

    public function testValidateAcceptsEnabledGtm(): void
    {
        $out = TrackingIntegrationsService::validateAndMerge([
            'tools' => [
                'gtm' => ['enabled' => true, 'containerId' => 'GTM-ABC123', 'consentCategory' => 'analytics'],
            ],
        ]);
        self::assertTrue($out['tools']['gtm']['enabled']);
        self::assertSame('GTM-ABC123', $out['tools']['gtm']['containerId']);
        self::assertArrayHasKey('updatedAt', $out);
    }

    public function testValidateRejectsBadGtmWhenEnabled(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('invalid_tracking_gtm_id');
        TrackingIntegrationsService::validateAndMerge([
            'tools' => [
                'gtm' => ['enabled' => true, 'containerId' => 'bad', 'consentCategory' => 'analytics'],
            ],
        ]);
    }

    public function testPublicBundleOmitsDisabled(): void
    {
        $merged = TrackingIntegrationsService::mergeWithDefaults([]);
        $pub = TrackingIntegrationsService::toPublicBundle($merged);
        self::assertSame([], $pub['tools']);
    }

    public function testPublicBundleIncludesEnabledGa4(): void
    {
        $merged = TrackingIntegrationsService::validateAndMerge([
            'tools' => [
                'ga4' => ['enabled' => true, 'measurementId' => 'G-TEST12345', 'consentCategory' => 'analytics'],
            ],
        ]);
        $pub = TrackingIntegrationsService::toPublicBundle($merged);
        self::assertArrayHasKey('ga4', $pub['tools']);
        self::assertSame('G-TEST12345', $pub['tools']['ga4']['measurementId']);
        self::assertSame('analytics', $pub['tools']['ga4']['consentCategory']);
    }
}

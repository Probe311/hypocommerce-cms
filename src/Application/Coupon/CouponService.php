<?php

declare(strict_types=1);

namespace App\Application\Coupon;

use App\Infrastructure\Persistence\PdoCouponRepository;
use RuntimeException;

final class CouponService
{
    public function __construct(private readonly PdoCouponRepository $couponRepository = new PdoCouponRepository())
    {
    }

    /**
     * @param array<int,array{productId:string,total:float}> $cartItems
     * @return array{valid:bool,code:string,discount:float,message:string,couponId:?int}
     */
    public function validate(
        string $couponCode,
        float $subTotal,
        array $cartItems,
        ?string $customerRef
    ): array {
        $coupon = $this->couponRepository->findByCode($couponCode);
        if ($coupon === null) {
            return ['valid' => false, 'code' => strtoupper($couponCode), 'discount' => 0.0, 'message' => 'coupon_not_found', 'couponId' => null];
        }

        $couponId = (int) $coupon['id'];
        $now = new \DateTimeImmutable();
        if ($coupon['starts_at'] !== null && new \DateTimeImmutable((string) $coupon['starts_at']) > $now) {
            return ['valid' => false, 'code' => (string) $coupon['code'], 'discount' => 0.0, 'message' => 'coupon_not_started', 'couponId' => $couponId];
        }
        if ($coupon['ends_at'] !== null && new \DateTimeImmutable((string) $coupon['ends_at']) < $now) {
            return ['valid' => false, 'code' => (string) $coupon['code'], 'discount' => 0.0, 'message' => 'coupon_expired', 'couponId' => $couponId];
        }
        if ($coupon['min_order_total'] !== null && $subTotal < (float) $coupon['min_order_total']) {
            return ['valid' => false, 'code' => (string) $coupon['code'], 'discount' => 0.0, 'message' => 'min_order_total_not_reached', 'couponId' => $couponId];
        }
        if ($coupon['max_uses'] !== null && $this->couponRepository->usageCount($couponId) >= (int) $coupon['max_uses']) {
            return ['valid' => false, 'code' => (string) $coupon['code'], 'discount' => 0.0, 'message' => 'max_uses_reached', 'couponId' => $couponId];
        }
        if ($customerRef !== null && $customerRef !== '' && $coupon['max_uses_per_user'] !== null) {
            $usedByCustomer = $this->couponRepository->usageCountForCustomerRef($couponId, $customerRef);
            if ($usedByCustomer >= (int) $coupon['max_uses_per_user']) {
                return ['valid' => false, 'code' => (string) $coupon['code'], 'discount' => 0.0, 'message' => 'max_uses_per_user_reached', 'couponId' => $couponId];
            }
        }

        $eligibleTotal = $this->eligibleTotal($coupon, $cartItems, $subTotal);
        if ($eligibleTotal <= 0) {
            return ['valid' => false, 'code' => (string) $coupon['code'], 'discount' => 0.0, 'message' => 'coupon_not_applicable', 'couponId' => $couponId];
        }

        $type = (string) $coupon['type'];
        $value = (float) $coupon['value'];
        $discount = $type === 'percent'
            ? round($eligibleTotal * ($value / 100.0), 2)
            : min(round($value, 2), round($eligibleTotal, 2));
        if ($discount <= 0) {
            return ['valid' => false, 'code' => (string) $coupon['code'], 'discount' => 0.0, 'message' => 'coupon_not_applicable', 'couponId' => $couponId];
        }

        return ['valid' => true, 'code' => (string) $coupon['code'], 'discount' => $discount, 'message' => 'ok', 'couponId' => $couponId];
    }

    public function recordRedemption(int $couponId, string $orderId, ?string $customerRef, float $amount): void
    {
        $this->couponRepository->recordRedemption($couponId, $orderId, $customerRef, $amount);
    }

    /**
     * @param array<string,mixed> $coupon
     * @param array<int,array{productId:string,total:float}> $cartItems
     */
    private function eligibleTotal(array $coupon, array $cartItems, float $subTotal): float
    {
        $appliesToRaw = $coupon['applies_to'] ?? null;
        if (!is_string($appliesToRaw) || trim($appliesToRaw) === '') {
            return $subTotal;
        }
        $appliesTo = json_decode($appliesToRaw, true);
        if (!is_array($appliesTo)) {
            return $subTotal;
        }

        // Global
        if (isset($appliesTo['scope']) && $appliesTo['scope'] === 'global') {
            return $subTotal;
        }

        // Product-targeted
        if (isset($appliesTo['productIds']) && is_array($appliesTo['productIds'])) {
            $productIds = array_map('strval', $appliesTo['productIds']);
            $total = 0.0;
            foreach ($cartItems as $item) {
                if (in_array($item['productId'], $productIds, true)) {
                    $total += (float) $item['total'];
                }
            }
            return $total;
        }

        // Category-targeted
        if (isset($appliesTo['categorySlugs']) && is_array($appliesTo['categorySlugs'])) {
            $categorySlugs = array_map(static fn ($v): string => strtolower((string) $v), $appliesTo['categorySlugs']);
            $productIds = array_values(array_unique(array_map(static fn (array $item): string => $item['productId'], $cartItems)));
            $categoryByProduct = $this->couponRepository->categorySlugByProductIds($productIds);
            $total = 0.0;
            foreach ($cartItems as $item) {
                $categorySlug = strtolower((string) ($categoryByProduct[$item['productId']] ?? ''));
                if ($categorySlug !== '' && in_array($categorySlug, $categorySlugs, true)) {
                    $total += (float) $item['total'];
                }
            }
            return $total;
        }

        return $subTotal;
    }
}

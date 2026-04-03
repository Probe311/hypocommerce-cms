<?php

declare(strict_types=1);

namespace App\Infrastructure\Graphql;

use App\Domain\Product\Product;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;

final class Types
{
    private static ?ScalarType $string = null;
    private static ?ScalarType $int = null;
    private static ?ScalarType $float = null;
    private static ?ObjectType $product = null;
    private static ?ObjectType $cartItem = null;
    private static ?ObjectType $cart = null;
    private static ?ObjectType $simpleOrder = null;
    private static ?ObjectType $paymentSession = null;
    private static ?ObjectType $shippingMethod = null;
    private static ?ObjectType $customer = null;
    private static ?ObjectType $customerAddress = null;
    private static ?ObjectType $customerAuthPayload = null;
    private static ?ObjectType $category = null;
    private static ?ObjectType $eeatRecommendation = null;
    private static ?ObjectType $eeatScore = null;
    private static ?ObjectType $cmsArticle = null;
    private static ?ObjectType $cmsCategory = null;
    private static ?ObjectType $cmsNavItem = null;
    private static ?ObjectType $eeatOverviewSummary = null;
    private static ?ObjectType $eeatOverviewEntityType = null;
    private static ?ObjectType $eeatOverviewRule = null;
    private static ?ObjectType $eeatOverviewSeverity = null;
    private static ?ObjectType $eeatOverview = null;
    private static ?ObjectType $eeatOpportunity = null;
    private static ?ObjectType $eeatQuickWin = null;
    private static ?ObjectType $eeatRecommendationStatus = null;
    private static ?ObjectType $eeatScoreEvolution = null;
    private static ?ObjectType $eeatProgress = null;
    private static ?ObjectType $eeatRunTrend = null;
    private static ?ObjectType $eeatOwnerBucket = null;
    private static ?ObjectType $eeatSlaSummary = null;
    private static ?ObjectType $eeatSlaOwner = null;
    private static ?ObjectType $eeatSla = null;
    private static ?ObjectType $eeatAutoPrioritizeResult = null;
    private static ?ObjectType $eeatDigest = null;

    public static function string(): ScalarType
    {
        if (self::$string === null) {
            self::$string = Type::string();
        }

        return self::$string;
    }

    public static function int(): ScalarType
    {
        if (self::$int === null) {
            self::$int = Type::int();
        }

        return self::$int;
    }

    public static function float(): ScalarType
    {
        if (self::$float === null) {
            self::$float = Type::float();
        }

        return self::$float;
    }

    public static function product(): ObjectType
    {
        if (self::$product === null) {
            self::$product = new ObjectType([
                'name' => 'Product',
                'fields' => [
                    'id' => [
                        'type' => Type::nonNull(Type::id()),
                        'resolve' => static fn (Product $product): string => $product->id()->toString(),
                    ],
                    'sku' => ['type' => Type::nonNull(self::string())],
                    'name' => ['type' => Type::nonNull(self::string())],
                    'slug' => ['type' => Type::nonNull(self::string())],
                    'description' => ['type' => self::string()],
                    'price' => ['type' => Type::nonNull(Type::float())],
                    'salePrice' => ['type' => Type::float()],
                    'status' => ['type' => Type::nonNull(self::string())],
                    'type' => ['type' => Type::nonNull(self::string())],
                ],
            ]);
        }

        return self::$product;
    }

    public static function listOf($type)
    {
        return Type::listOf($type);
    }

    public static function cartItem(): ObjectType
    {
        if (self::$cartItem === null) {
            self::$cartItem = new ObjectType([
                'name' => 'CartItem',
                'fields' => [
                    'id' => ['type' => Type::nonNull(self::int())],
                    'productId' => ['type' => Type::nonNull(self::string())],
                    'variantId' => ['type' => self::string()],
                    'quantity' => ['type' => Type::nonNull(self::int())],
                    'unitPrice' => ['type' => Type::nonNull(self::float())],
                    'total' => ['type' => Type::nonNull(self::float())],
                ],
            ]);
        }

        return self::$cartItem;
    }

    public static function cart(): ObjectType
    {
        if (self::$cart === null) {
            self::$cart = new ObjectType([
                'name' => 'Cart',
                'fields' => [
                    'id' => ['type' => Type::nonNull(self::string())],
                    'currency' => ['type' => Type::nonNull(self::string())],
                    'items' => ['type' => Type::nonNull(self::listOf(self::cartItem()))],
                    'subTotal' => ['type' => Type::nonNull(self::float())],
                    'taxTotal' => ['type' => Type::nonNull(self::float())],
                    'shippingTotal' => ['type' => Type::nonNull(self::float())],
                    'discountTotal' => ['type' => Type::nonNull(self::float())],
                    'total' => ['type' => Type::nonNull(self::float())],
                ],
            ]);
        }

        return self::$cart;
    }

    public static function simpleOrder(): ObjectType
    {
        if (self::$simpleOrder === null) {
            self::$simpleOrder = new ObjectType([
                'name' => 'SimpleOrder',
                'fields' => [
                    'id' => ['type' => Type::nonNull(self::string())],
                    'number' => ['type' => Type::nonNull(self::string())],
                    'status' => ['type' => Type::nonNull(self::string())],
                ],
            ]);
        }

        return self::$simpleOrder;
    }

    public static function paymentSession(): ObjectType
    {
        if (self::$paymentSession === null) {
            self::$paymentSession = new ObjectType([
                'name' => 'PaymentSession',
                'fields' => [
                    'id' => ['type' => Type::nonNull(self::string())],
                    'provider' => ['type' => Type::nonNull(self::string())],
                    'url' => ['type' => Type::nonNull(self::string())],
                    'status' => ['type' => Type::nonNull(self::string())],
                ],
            ]);
        }

        return self::$paymentSession;
    }

    public static function shippingMethod(): ObjectType
    {
        if (self::$shippingMethod === null) {
            self::$shippingMethod = new ObjectType([
                'name' => 'ShippingMethod',
                'fields' => [
                    'id' => ['type' => Type::nonNull(self::string())],
                    'label' => ['type' => Type::nonNull(self::string())],
                    'carrier' => ['type' => Type::nonNull(self::string())],
                    'price' => ['type' => Type::nonNull(self::float())],
                    'etaMinDays' => ['type' => Type::nonNull(self::int())],
                    'etaMaxDays' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }

        return self::$shippingMethod;
    }

    public static function customer(): ObjectType
    {
        if (self::$customer === null) {
            self::$customer = new ObjectType([
                'name' => 'Customer',
                'fields' => [
                    'id' => ['type' => Type::nonNull(self::string())],
                    'email' => ['type' => Type::nonNull(self::string())],
                    'first_name' => ['type' => self::string()],
                    'last_name' => ['type' => self::string()],
                    'default_billing_id' => ['type' => self::int()],
                    'default_shipping_id' => ['type' => self::int()],
                ],
            ]);
        }

        return self::$customer;
    }

    public static function customerAddress(): ObjectType
    {
        if (self::$customerAddress === null) {
            self::$customerAddress = new ObjectType([
                'name' => 'CustomerAddress',
                'fields' => [
                    'id' => ['type' => Type::nonNull(self::int())],
                    'customer_id' => ['type' => Type::nonNull(self::string())],
                    'label' => ['type' => self::string()],
                    'type' => ['type' => Type::nonNull(self::string())],
                    'line1' => ['type' => Type::nonNull(self::string())],
                    'line2' => ['type' => self::string()],
                    'city' => ['type' => Type::nonNull(self::string())],
                    'postcode' => ['type' => Type::nonNull(self::string())],
                    'state' => ['type' => self::string()],
                    'country' => ['type' => Type::nonNull(self::string())],
                    'phone' => ['type' => self::string()],
                ],
            ]);
        }

        return self::$customerAddress;
    }

    public static function customerAuthPayload(): ObjectType
    {
        if (self::$customerAuthPayload === null) {
            self::$customerAuthPayload = new ObjectType([
                'name' => 'CustomerAuthPayload',
                'fields' => [
                    'customerId' => ['type' => Type::nonNull(self::string())],
                    'token' => ['type' => Type::nonNull(self::string())],
                ],
            ]);
        }

        return self::$customerAuthPayload;
    }

    public static function category(): ObjectType
    {
        if (self::$category === null) {
            self::$category = new ObjectType([
                'name' => 'Category',
                'fields' => [
                    'slug' => ['type' => Type::nonNull(self::string())],
                    'name' => ['type' => Type::nonNull(self::string())],
                    'description' => ['type' => self::string()],
                ],
            ]);
        }

        return self::$category;
    }

    public static function eeatRecommendation(): ObjectType
    {
        if (self::$eeatRecommendation === null) {
            self::$eeatRecommendation = new ObjectType([
                'name' => 'EeatRecommendation',
                'fields' => [
                    'rule_code' => ['type' => Type::nonNull(self::string())],
                    'severity' => ['type' => Type::nonNull(self::string())],
                    'impact' => ['type' => Type::nonNull(self::string())],
                    'effort' => ['type' => Type::nonNull(self::string())],
                    'message' => ['type' => Type::nonNull(self::string())],
                    'fix_suggestion' => ['type' => Type::nonNull(self::string())],
                    'status' => ['type' => Type::nonNull(self::string())],
                    'owner' => ['type' => self::string()],
                    'due_date' => ['type' => self::string()],
                    'note' => ['type' => self::string()],
                    'last_status_change_at' => ['type' => self::string()],
                ],
            ]);
        }

        return self::$eeatRecommendation;
    }

    public static function eeatScore(): ObjectType
    {
        if (self::$eeatScore === null) {
            self::$eeatScore = new ObjectType([
                'name' => 'EeatScore',
                'fields' => [
                    'entity_type' => ['type' => Type::nonNull(self::string())],
                    'entity_id' => ['type' => Type::nonNull(self::string())],
                    'entity_slug' => ['type' => self::string()],
                    'locale' => ['type' => Type::nonNull(self::string())],
                    'score_global' => ['type' => Type::nonNull(self::float())],
                    'score_experience' => ['type' => Type::nonNull(self::float())],
                    'score_expertise' => ['type' => Type::nonNull(self::float())],
                    'score_authoritativeness' => ['type' => Type::nonNull(self::float())],
                    'score_trust' => ['type' => Type::nonNull(self::float())],
                    'grade' => ['type' => Type::nonNull(self::string())],
                    'blockers_count' => ['type' => Type::nonNull(self::int())],
                    'computed_at' => ['type' => Type::nonNull(self::string())],
                    'recommendations' => ['type' => Type::nonNull(self::listOf(self::eeatRecommendation()))],
                ],
            ]);
        }

        return self::$eeatScore;
    }

    public static function cmsArticle(): ObjectType
    {
        if (self::$cmsArticle === null) {
            self::$cmsArticle = new ObjectType([
                'name' => 'CmsArticle',
                'fields' => [
                    'id' => ['type' => self::int()],
                    'slug' => ['type' => Type::nonNull(self::string())],
                    'title' => ['type' => Type::nonNull(self::string())],
                    'excerpt' => ['type' => self::string()],
                    'body' => ['type' => Type::nonNull(self::string())],
                    'category_slug' => ['type' => Type::nonNull(self::string())],
                    'category_name' => ['type' => Type::nonNull(self::string())],
                    'featured_media_url' => ['type' => self::string()],
                    'published_at' => ['type' => self::string()],
                ],
            ]);
        }
        return self::$cmsArticle;
    }

    public static function cmsCategory(): ObjectType
    {
        if (self::$cmsCategory === null) {
            self::$cmsCategory = new ObjectType([
                'name' => 'CmsCategory',
                'fields' => [
                    'id' => ['type' => self::int()],
                    'parent_id' => ['type' => self::int()],
                    'slug' => ['type' => Type::nonNull(self::string())],
                    'name' => ['type' => Type::nonNull(self::string())],
                    'description' => ['type' => self::string()],
                    'meta_title' => ['type' => self::string()],
                    'meta_description' => ['type' => self::string()],
                ],
            ]);
        }
        return self::$cmsCategory;
    }

    public static function cmsNavItem(): ObjectType
    {
        if (self::$cmsNavItem === null) {
            self::$cmsNavItem = new ObjectType([
                'name' => 'CmsNavItem',
                'fields' => [
                    'id' => ['type' => self::int()],
                    'parent_id' => ['type' => self::int()],
                    'label' => ['type' => Type::nonNull(self::string())],
                    'target_type' => ['type' => Type::nonNull(self::string())],
                    'target_ref' => ['type' => Type::nonNull(self::string())],
                    'icon' => ['type' => self::string()],
                    'order_index' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$cmsNavItem;
    }

    public static function eeatOverviewSummary(): ObjectType
    {
        if (self::$eeatOverviewSummary === null) {
            self::$eeatOverviewSummary = new ObjectType([
                'name' => 'EeatOverviewSummary',
                'fields' => [
                    'total' => ['type' => Type::nonNull(self::int())],
                    'averageScore' => ['type' => Type::nonNull(self::float())],
                    'totalBlockers' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatOverviewSummary;
    }

    public static function eeatOverviewEntityType(): ObjectType
    {
        if (self::$eeatOverviewEntityType === null) {
            self::$eeatOverviewEntityType = new ObjectType([
                'name' => 'EeatOverviewEntityType',
                'fields' => [
                    'entity_type' => ['type' => Type::nonNull(self::string())],
                    'total' => ['type' => Type::nonNull(self::int())],
                    'avg_score' => ['type' => Type::nonNull(self::float())],
                ],
            ]);
        }
        return self::$eeatOverviewEntityType;
    }

    public static function eeatOverviewRule(): ObjectType
    {
        if (self::$eeatOverviewRule === null) {
            self::$eeatOverviewRule = new ObjectType([
                'name' => 'EeatOverviewRule',
                'fields' => [
                    'rule_code' => ['type' => Type::nonNull(self::string())],
                    'severity' => ['type' => Type::nonNull(self::string())],
                    'total' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatOverviewRule;
    }

    public static function eeatOverviewSeverity(): ObjectType
    {
        if (self::$eeatOverviewSeverity === null) {
            self::$eeatOverviewSeverity = new ObjectType([
                'name' => 'EeatOverviewSeverity',
                'fields' => [
                    'severity' => ['type' => Type::nonNull(self::string())],
                    'total' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatOverviewSeverity;
    }

    public static function eeatOverview(): ObjectType
    {
        if (self::$eeatOverview === null) {
            self::$eeatOverview = new ObjectType([
                'name' => 'EeatOverview',
                'fields' => [
                    'summary' => ['type' => Type::nonNull(self::eeatOverviewSummary())],
                    'byEntityType' => ['type' => Type::nonNull(self::listOf(self::eeatOverviewEntityType()))],
                    'topRules' => ['type' => Type::nonNull(self::listOf(self::eeatOverviewRule()))],
                    'severityBreakdown' => ['type' => Type::nonNull(self::listOf(self::eeatOverviewSeverity()))],
                ],
            ]);
        }
        return self::$eeatOverview;
    }

    public static function eeatOpportunity(): ObjectType
    {
        if (self::$eeatOpportunity === null) {
            self::$eeatOpportunity = new ObjectType([
                'name' => 'EeatOpportunity',
                'fields' => [
                    'entity_type' => ['type' => Type::nonNull(self::string())],
                    'entity_id' => ['type' => Type::nonNull(self::string())],
                    'entity_slug' => ['type' => self::string()],
                    'score_global' => ['type' => Type::nonNull(self::float())],
                    'grade' => ['type' => Type::nonNull(self::string())],
                    'blockers_count' => ['type' => Type::nonNull(self::int())],
                    'recommendation_count' => ['type' => Type::nonNull(self::int())],
                    'priority_score' => ['type' => Type::nonNull(self::float())],
                    'computed_at' => ['type' => Type::nonNull(self::string())],
                ],
            ]);
        }
        return self::$eeatOpportunity;
    }

    public static function eeatQuickWin(): ObjectType
    {
        if (self::$eeatQuickWin === null) {
            self::$eeatQuickWin = new ObjectType([
                'name' => 'EeatQuickWin',
                'fields' => [
                    'entity_type' => ['type' => Type::nonNull(self::string())],
                    'entity_id' => ['type' => Type::nonNull(self::string())],
                    'entity_slug' => ['type' => self::string()],
                    'score_global' => ['type' => Type::nonNull(self::float())],
                    'grade' => ['type' => Type::nonNull(self::string())],
                    'blockers_count' => ['type' => Type::nonNull(self::int())],
                    'recommendation_count' => ['type' => Type::nonNull(self::int())],
                    'high_impact_count' => ['type' => Type::nonNull(self::int())],
                    'low_effort_count' => ['type' => Type::nonNull(self::int())],
                    'computed_at' => ['type' => Type::nonNull(self::string())],
                ],
            ]);
        }
        return self::$eeatQuickWin;
    }

    public static function eeatRecommendationStatus(): ObjectType
    {
        if (self::$eeatRecommendationStatus === null) {
            self::$eeatRecommendationStatus = new ObjectType([
                'name' => 'EeatRecommendationStatus',
                'fields' => [
                    'open' => ['type' => Type::nonNull(self::int())],
                    'in_progress' => ['type' => Type::nonNull(self::int())],
                    'done' => ['type' => Type::nonNull(self::int())],
                    'dismissed' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatRecommendationStatus;
    }

    public static function eeatScoreEvolution(): ObjectType
    {
        if (self::$eeatScoreEvolution === null) {
            self::$eeatScoreEvolution = new ObjectType([
                'name' => 'EeatScoreEvolution',
                'fields' => [
                    'improved' => ['type' => Type::nonNull(self::int())],
                    'degraded' => ['type' => Type::nonNull(self::int())],
                    'stable' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatScoreEvolution;
    }

    public static function eeatProgress(): ObjectType
    {
        if (self::$eeatProgress === null) {
            self::$eeatProgress = new ObjectType([
                'name' => 'EeatProgress',
                'fields' => [
                    'recommendationStatus' => ['type' => Type::nonNull(self::eeatRecommendationStatus())],
                    'scoreEvolution' => ['type' => Type::nonNull(self::eeatScoreEvolution())],
                ],
            ]);
        }
        return self::$eeatProgress;
    }

    public static function eeatRunTrend(): ObjectType
    {
        if (self::$eeatRunTrend === null) {
            self::$eeatRunTrend = new ObjectType([
                'name' => 'EeatRunTrend',
                'fields' => [
                    'id' => ['type' => Type::nonNull(self::int())],
                    'run_type' => ['type' => Type::nonNull(self::string())],
                    'status' => ['type' => Type::nonNull(self::string())],
                    'started_at' => ['type' => Type::nonNull(self::string())],
                    'ended_at' => ['type' => self::string()],
                ],
            ]);
        }
        return self::$eeatRunTrend;
    }

    public static function eeatOwnerBucket(): ObjectType
    {
        if (self::$eeatOwnerBucket === null) {
            self::$eeatOwnerBucket = new ObjectType([
                'name' => 'EeatOwnerBucket',
                'fields' => [
                    'owner' => ['type' => Type::nonNull(self::string())],
                    'status' => ['type' => Type::nonNull(self::string())],
                    'total' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatOwnerBucket;
    }

    public static function eeatSlaSummary(): ObjectType
    {
        if (self::$eeatSlaSummary === null) {
            self::$eeatSlaSummary = new ObjectType([
                'name' => 'EeatSlaSummary',
                'fields' => [
                    'totalRecommendations' => ['type' => Type::nonNull(self::int())],
                    'overdueTotal' => ['type' => Type::nonNull(self::int())],
                    'overdueCritical' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatSlaSummary;
    }

    public static function eeatSlaOwner(): ObjectType
    {
        if (self::$eeatSlaOwner === null) {
            self::$eeatSlaOwner = new ObjectType([
                'name' => 'EeatSlaOwner',
                'fields' => [
                    'owner' => ['type' => Type::nonNull(self::string())],
                    'total' => ['type' => Type::nonNull(self::int())],
                    'overdue_total' => ['type' => Type::nonNull(self::int())],
                    'overdue_critical' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatSlaOwner;
    }

    public static function eeatSla(): ObjectType
    {
        if (self::$eeatSla === null) {
            self::$eeatSla = new ObjectType([
                'name' => 'EeatSla',
                'fields' => [
                    'summary' => ['type' => Type::nonNull(self::eeatSlaSummary())],
                    'byOwner' => ['type' => Type::nonNull(self::listOf(self::eeatSlaOwner()))],
                ],
            ]);
        }
        return self::$eeatSla;
    }

    public static function eeatAutoPrioritizeResult(): ObjectType
    {
        if (self::$eeatAutoPrioritizeResult === null) {
            self::$eeatAutoPrioritizeResult = new ObjectType([
                'name' => 'EeatAutoPrioritizeResult',
                'fields' => [
                    'dryRun' => ['type' => Type::nonNull(self::string())],
                    'updated' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatAutoPrioritizeResult;
    }

    public static function eeatDigest(): ObjectType
    {
        if (self::$eeatDigest === null) {
            self::$eeatDigest = new ObjectType([
                'name' => 'EeatDigest',
                'fields' => [
                    'dueSoonDays' => ['type' => Type::nonNull(self::int())],
                    'totalRecommendations' => ['type' => Type::nonNull(self::int())],
                    'activeRecommendations' => ['type' => Type::nonNull(self::int())],
                    'overdueRecommendations' => ['type' => Type::nonNull(self::int())],
                    'dueSoonRecommendations' => ['type' => Type::nonNull(self::int())],
                ],
            ]);
        }
        return self::$eeatDigest;
    }
}

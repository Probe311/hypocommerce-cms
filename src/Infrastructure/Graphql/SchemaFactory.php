<?php

declare(strict_types=1);

namespace App\Infrastructure\Graphql;

use App\Application\Admin\AdminAuthService;
use App\Application\Cart\CartService;
use App\Application\Customer\CustomerAuthService;
use App\Application\Customer\CustomerProfileService;
use App\Application\Payment\PaymentOrchestrator;
use App\Application\Shipping\ShippingService;
use App\Application\Validation\InputValidator;
use App\Infrastructure\Persistence\PdoIdempotencyRepository;
use App\Infrastructure\Persistence\PdoAdminMutationRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoCatalogApiRepository;
use App\Infrastructure\Persistence\PdoCouponRepository;
use App\Infrastructure\Persistence\PdoNewsletterRepository;
use App\Infrastructure\Persistence\PdoEeatRepository;
use App\Infrastructure\Persistence\PdoCmsV2Repository;
use App\Infrastructure\Persistence\PdoProductRepository;
use App\Infrastructure\Security\RateLimiter;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use Ramsey\Uuid\Uuid;

final class SchemaFactory
{
    public static function createSchema(): Schema
    {
        $productRepository = new PdoProductRepository();
        $catalogApiRepository = new PdoCatalogApiRepository();
        $orderRepository = new PdoOrderRepository();
        $adminMutationRepository = new PdoAdminMutationRepository();
        $couponRepository = new PdoCouponRepository();
        $newsletterRepository = new PdoNewsletterRepository();
        $idempotencyRepository = new PdoIdempotencyRepository();
        $eeatRepository = new PdoEeatRepository();
        $cmsV2Repository = new PdoCmsV2Repository();
        $paymentOrchestrator = new PaymentOrchestrator();
        $shippingService = new ShippingService();
        $customerAuthService = new CustomerAuthService();
        $adminAuthService = new AdminAuthService();
        $customerProfileService = new CustomerProfileService();
        $cartService = new CartService();
        $validator = new InputValidator();
        $assertAdmin = static function (array $args, string $requiredRole = 'admin') use ($validator, $adminAuthService): array {
            $token = $validator->requireNonEmptyString($args, 'adminToken', 'invalid_admin_token');
            $identity = $adminAuthService->verifyBearer($token);
            $weights = ['manager' => 1, 'admin' => 2, 'super_admin' => 3];
            $role = (string) ($identity['role'] ?? '');
            if (($weights[$role] ?? 0) < ($weights[$requiredRole] ?? PHP_INT_MAX)) {
                throw new \RuntimeException('forbidden');
            }
            return $identity;
        };

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'health' => [
                    'type' => Types::string(),
                    'resolve' => static fn (): string => 'ok',
                ],
                'products' => [
                    'type' => Types::listOf(Types::product()),
                    'args' => [
                        'search' => Types::string(),
                        'limit' => Types::int(),
                        'offset' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($productRepository): array {
                        $search = $args['search'] ?? null;
                        $limit = isset($args['limit']) ? (int) $args['limit'] : 20;
                        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;

                        return $productRepository->search($search, $limit, $offset);
                    },
                ],
                'categories' => [
                    'type' => Types::listOf(Types::category()),
                    'resolve' => static function () use ($catalogApiRepository): array {
                        return $catalogApiRepository->listCategories();
                    },
                ],
                'product' => [
                    'type' => Types::product(),
                    'args' => [
                        'id' => Types::string(),
                        'slug' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($productRepository) {
                        if (isset($args['id'])) {
                            return $productRepository->findById(Uuid::fromString($args['id']));
                        }
                        if (isset($args['slug'])) {
                            return $productRepository->findBySlug($args['slug']);
                        }
                        return null;
                    },
                ],
                'cart' => [
                    'type' => Types::cart(),
                    'args' => [
                        'sessionId' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $validator) {
                        $sessionId = $validator->requireNonEmptyString($args, 'sessionId', 'invalid_session_id');
                        return $cartService->getOrCreateCart($sessionId);
                    },
                ],
                'ordersByCustomer' => [
                    'type' => Types::listOf(Types::simpleOrder()),
                    'args' => [
                        'customerId' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $validator) {
                        $customerId = $validator->requireUuidString($args, 'customerId', 'invalid_customer_id');
                        return $cartService->orderHistory($customerId);
                    },
                ],
                'customerOrderHistory' => [
                    'type' => Types::listOf(Types::simpleOrder()),
                    'args' => [
                        'customerToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $customerAuthService, $validator): array {
                        $token = $validator->requireNonEmptyString($args, 'customerToken', 'invalid_customer_token');
                        $customerId = $customerAuthService->verifyCustomerToken($token);
                        return $cartService->orderHistory($customerId);
                    },
                ],
                'shippingMethods' => [
                    'type' => Types::listOf(Types::shippingMethod()),
                    'args' => [
                        'sessionId' => Types::string(),
                        'country' => Types::string(),
                        'postcode' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $shippingService, $validator): array {
                        $sessionId = $validator->requireNonEmptyString($args, 'sessionId', 'invalid_session_id');
                        $country = strtoupper($validator->requireNonEmptyString($args, 'country', 'invalid_shipping_country'));
                        $cart = $cartService->getOrCreateCart($sessionId);
                        return $shippingService->availableMethods($country, (float) $cart['subTotal']);
                    },
                ],
                'validateCoupon' => [
                    'type' => Types::string(),
                    'args' => [
                        'sessionId' => Types::string(),
                        'couponCode' => Types::string(),
                        'customerToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $customerAuthService, $validator): string {
                        $sessionId = $validator->requireNonEmptyString($args, 'sessionId', 'invalid_session_id');
                        $couponCode = $validator->requireNonEmptyString($args, 'couponCode', 'invalid_coupon_code');
                        $customerToken = $validator->optionalTrimmedString($args, 'customerToken');
                        $customerRef = null;
                        if ($customerToken !== null && $customerToken !== '') {
                            $customerRef = $customerAuthService->verifyCustomerToken($customerToken);
                        }
                        $cart = $cartService->cartWithCoupon($sessionId, $couponCode, $customerRef);
                        $coupon = $cart['coupon'] ?? null;
                        if (!is_array($coupon)) {
                            return 'invalid';
                        }
                        return ((bool) ($coupon['valid'] ?? false)) ? 'valid' : (string) ($coupon['message'] ?? 'invalid');
                    },
                ],
                'customerProfile' => [
                    'type' => Types::customer(),
                    'args' => [
                        'customerToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($customerAuthService, $customerProfileService, $validator) {
                        $token = $validator->requireNonEmptyString($args, 'customerToken', 'invalid_customer_token');
                        $customerId = $customerAuthService->verifyCustomerToken($token);
                        return $customerProfileService->profile($customerId);
                    },
                ],
                'customerAddresses' => [
                    'type' => Types::listOf(Types::customerAddress()),
                    'args' => [
                        'customerToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($customerAuthService, $customerProfileService, $validator): array {
                        $token = $validator->requireNonEmptyString($args, 'customerToken', 'invalid_customer_token');
                        $customerId = $customerAuthService->verifyCustomerToken($token);
                        return $customerProfileService->addresses($customerId);
                    },
                ],
                'eeatScores' => [
                    'type' => Types::listOf(Types::eeatScore()),
                    'args' => [
                        'adminToken' => Types::string(),
                        'entityType' => Types::string(),
                        'limit' => Types::int(),
                        'offset' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
                        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
                        $entityType = isset($args['entityType']) ? trim((string) $args['entityType']) : null;
                        return $eeatRepository->listScores($limit, $offset, $entityType !== '' ? $entityType : null);
                    },
                ],
                'eeatScore' => [
                    'type' => Types::eeatScore(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'entityType' => Types::string(),
                        'entityId' => Types::string(),
                        'locale' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $eeatRepository) {
                        $assertAdmin($args, 'admin');
                        $entityType = $validator->requireNonEmptyString($args, 'entityType', 'invalid_entity_type');
                        $entityId = $validator->requireNonEmptyString($args, 'entityId', 'invalid_entity_id');
                        $locale = $validator->optionalTrimmedString($args, 'locale') ?? 'fr';
                        return $eeatRepository->getScore($entityType, $entityId, $locale);
                    },
                ],
                'eeatOverview' => [
                    'type' => Types::eeatOverview(),
                    'args' => [
                        'adminToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        return $eeatRepository->overviewStats();
                    },
                ],
                'eeatOpportunities' => [
                    'type' => Types::listOf(Types::eeatOpportunity()),
                    'args' => [
                        'adminToken' => Types::string(),
                        'entityType' => Types::string(),
                        'limit' => Types::int(),
                        'offset' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
                        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
                        $entityType = isset($args['entityType']) ? trim((string) $args['entityType']) : null;
                        return $eeatRepository->listOpportunities($limit, $offset, $entityType !== '' ? $entityType : null);
                    },
                ],
                'eeatQuickWins' => [
                    'type' => Types::listOf(Types::eeatQuickWin()),
                    'args' => [
                        'adminToken' => Types::string(),
                        'entityType' => Types::string(),
                        'limit' => Types::int(),
                        'offset' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
                        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
                        $entityType = isset($args['entityType']) ? trim((string) $args['entityType']) : null;
                        return $eeatRepository->listQuickWins($limit, $offset, $entityType !== '' ? $entityType : null);
                    },
                ],
                'eeatProgress' => [
                    'type' => Types::eeatProgress(),
                    'args' => [
                        'adminToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        return $eeatRepository->progressStats();
                    },
                ],
                'eeatRunTrends' => [
                    'type' => Types::listOf(Types::eeatRunTrend()),
                    'args' => [
                        'adminToken' => Types::string(),
                        'limit' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $limit = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 20;
                        return $eeatRepository->runTrends($limit);
                    },
                ],
                'eeatRecommendationOwners' => [
                    'type' => Types::listOf(Types::eeatOwnerBucket()),
                    'args' => [
                        'adminToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        return $eeatRepository->listRecommendationOwners();
                    },
                ],
                'eeatOverdueRecommendations' => [
                    'type' => Types::listOf(Types::eeatRecommendation()),
                    'args' => [
                        'adminToken' => Types::string(),
                        'owner' => Types::string(),
                        'limit' => Types::int(),
                        'offset' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $owner = $validator->optionalTrimmedString($args, 'owner');
                        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
                        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
                        return $eeatRepository->listOverdueRecommendations($limit, $offset, $owner !== '' ? $owner : null);
                    },
                ],
                'eeatSla' => [
                    'type' => Types::eeatSla(),
                    'args' => [
                        'adminToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        return $eeatRepository->slaStats();
                    },
                ],
                'eeatCriticalOverdue' => [
                    'type' => Types::listOf(Types::eeatRecommendation()),
                    'args' => [
                        'adminToken' => Types::string(),
                        'limit' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
                        return $eeatRepository->listCriticalOverdue($limit);
                    },
                ],
                'eeatDueSoonRecommendations' => [
                    'type' => Types::listOf(Types::eeatRecommendation()),
                    'args' => [
                        'adminToken' => Types::string(),
                        'owner' => Types::string(),
                        'days' => Types::int(),
                        'limit' => Types::int(),
                        'offset' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $owner = $validator->optionalTrimmedString($args, 'owner');
                        $days = isset($args['days']) ? max(1, min(30, (int) $args['days'])) : 3;
                        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
                        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
                        return $eeatRepository->listDueSoonRecommendations($days, $limit, $offset, $owner !== '' ? $owner : null);
                    },
                ],
                'eeatDigest' => [
                    'type' => Types::eeatDigest(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'days' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $days = isset($args['days']) ? max(1, min(30, (int) $args['days'])) : 3;
                        return $eeatRepository->digestStats($days);
                    },
                ],
                'cmsArticles' => [
                    'type' => Types::listOf(Types::cmsArticle()),
                    'args' => [
                        'categorySlug' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cmsV2Repository): array {
                        $categorySlug = isset($args['categorySlug']) ? trim((string) $args['categorySlug']) : null;
                        return $cmsV2Repository->listPublishedArticles($categorySlug !== '' ? $categorySlug : null);
                    },
                ],
                'cmsArticleCategories' => [
                    'type' => Types::listOf(Types::cmsCategory()),
                    'resolve' => static function () use ($cmsV2Repository): array {
                        return $cmsV2Repository->listPublishedCategories();
                    },
                ],
                'cmsNavigation' => [
                    'type' => Types::listOf(Types::cmsNavItem()),
                    'args' => [
                        'location' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cmsV2Repository, $validator): array {
                        $location = strtolower($validator->optionalTrimmedString($args, 'location') ?? 'header');
                        if (!in_array($location, ['header', 'footer', 'secondary'], true)) {
                            $location = 'header';
                        }
                        return $cmsV2Repository->listActiveMenu($location);
                    },
                ],
            ],
        ]);

        $mutationType = new ObjectType([
            'name' => 'Mutation',
            'fields' => [
                'addToCart' => [
                    'type' => Types::cart(),
                    'args' => [
                        'sessionId' => Types::string(),
                        'productId' => Types::string(),
                        'variantId' => Types::string(),
                        'quantity' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $validator) {
                        $sessionId = $validator->requireNonEmptyString($args, 'sessionId', 'invalid_session_id');
                        $productId = $validator->requireUuidString($args, 'productId', 'invalid_product_id');
                        $variantId = $validator->optionalUuidString($args, 'variantId', 'invalid_variant_id');
                        $quantity = isset($args['quantity']) ? $validator->requireIntGreaterThanZero($args, 'quantity', 'invalid_quantity') : 1;

                        return $cartService->addToCart(
                            $sessionId,
                            $productId,
                            $variantId,
                            $quantity
                        );
                    },
                ],
                'updateCartItem' => [
                    'type' => Types::cart(),
                    'args' => [
                        'sessionId' => Types::string(),
                        'cartItemId' => Types::int(),
                        'quantity' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $validator) {
                        $sessionId = $validator->requireNonEmptyString($args, 'sessionId', 'invalid_session_id');
                        $cartItemId = $validator->requireIntGreaterThanZero($args, 'cartItemId', 'invalid_cart_item_id');
                        $quantity = $validator->requireIntGreaterThanZero($args, 'quantity', 'invalid_quantity');

                        return $cartService->updateCartItem(
                            $cartItemId,
                            $quantity,
                            $sessionId
                        );
                    },
                ],
                'removeCartItem' => [
                    'type' => Types::cart(),
                    'args' => [
                        'sessionId' => Types::string(),
                        'cartItemId' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $validator) {
                        $sessionId = $validator->requireNonEmptyString($args, 'sessionId', 'invalid_session_id');
                        $cartItemId = $validator->requireIntGreaterThanZero($args, 'cartItemId', 'invalid_cart_item_id');

                        return $cartService->removeCartItem(
                            $cartItemId,
                            $sessionId
                        );
                    },
                ],
                'checkout' => [
                    'type' => Types::simpleOrder(),
                    'args' => [
                        'sessionId' => Types::string(),
                        'customerId' => Types::string(),
                        'idempotencyKey' => Types::string(),
                        'contactEmail' => Types::string(),
                        'contactPhone' => Types::string(),
                        'contactFirstName' => Types::string(),
                        'contactLastName' => Types::string(),
                        'shippingLine1' => Types::string(),
                        'shippingLine2' => Types::string(),
                        'shippingCity' => Types::string(),
                        'shippingPostcode' => Types::string(),
                        'shippingState' => Types::string(),
                        'shippingCountry' => Types::string(),
                        'billingLine1' => Types::string(),
                        'billingLine2' => Types::string(),
                        'billingCity' => Types::string(),
                        'billingPostcode' => Types::string(),
                        'billingState' => Types::string(),
                        'billingCountry' => Types::string(),
                        'paymentMethod' => Types::string(),
                        'couponCode' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $orderRepository, $idempotencyRepository, $validator) {
                        $sessionId = $validator->requireNonEmptyString($args, 'sessionId', 'invalid_session_id');
                        $customerId = $validator->optionalUuidString($args, 'customerId', 'invalid_customer_id');
                        $idempotencyKey = $validator->optionalTrimmedString($args, 'idempotencyKey');
                        $contactEmail = $validator->requireEmail($args, 'contactEmail', 'invalid_contact_email');
                        $contactPhone = $validator->requireNonEmptyString($args, 'contactPhone', 'invalid_contact_phone');
                        $contactFirstName = $validator->requireNonEmptyString($args, 'contactFirstName', 'invalid_contact_first_name');
                        $contactLastName = $validator->requireNonEmptyString($args, 'contactLastName', 'invalid_contact_last_name');
                        $paymentMethod = strtolower($validator->requireNonEmptyString($args, 'paymentMethod', 'invalid_payment_method'));
                        $couponCode = $validator->optionalTrimmedString($args, 'couponCode');
                        $customerRef = $customerId ?? $contactEmail;
                        if (!in_array($paymentMethod, ['stripe', 'paypal'], true)) {
                            throw new \RuntimeException('invalid_payment_method');
                        }

                        $shippingAddress = [
                            'line1' => $validator->requireNonEmptyString($args, 'shippingLine1', 'invalid_shipping_line1'),
                            'line2' => $validator->optionalTrimmedString($args, 'shippingLine2'),
                            'city' => $validator->requireNonEmptyString($args, 'shippingCity', 'invalid_shipping_city'),
                            'postcode' => $validator->requireNonEmptyString($args, 'shippingPostcode', 'invalid_shipping_postcode'),
                            'state' => $validator->optionalTrimmedString($args, 'shippingState'),
                            'country' => strtoupper($validator->requireNonEmptyString($args, 'shippingCountry', 'invalid_shipping_country')),
                        ];
                        $billingAddress = [
                            'line1' => $validator->requireNonEmptyString($args, 'billingLine1', 'invalid_billing_line1'),
                            'line2' => $validator->optionalTrimmedString($args, 'billingLine2'),
                            'city' => $validator->requireNonEmptyString($args, 'billingCity', 'invalid_billing_city'),
                            'postcode' => $validator->requireNonEmptyString($args, 'billingPostcode', 'invalid_billing_postcode'),
                            'state' => $validator->optionalTrimmedString($args, 'billingState'),
                            'country' => strtoupper($validator->requireNonEmptyString($args, 'billingCountry', 'invalid_billing_country')),
                        ];
                        $contact = [
                            'email' => $contactEmail,
                            'phone' => $contactPhone,
                            'firstName' => $contactFirstName,
                            'lastName' => $contactLastName,
                        ];

                        if ($idempotencyKey !== null && $idempotencyKey !== '') {
                            $existingOrderId = $idempotencyRepository->findResourceId('checkout', $idempotencyKey);
                            if ($existingOrderId !== null) {
                                $existingOrder = $orderRepository->findById(Uuid::fromString($existingOrderId));
                                if ($existingOrder !== null) {
                                    return [
                                        'id' => $existingOrder->id()->toString(),
                                        'number' => $existingOrder->number(),
                                        'status' => $existingOrder->status(),
                                    ];
                                }
                            }
                        }

                        $order = $cartService->checkoutFromSessionDetailed(
                            $sessionId,
                            $customerId,
                            $shippingAddress,
                            $billingAddress,
                            $contact,
                            $paymentMethod,
                            $couponCode,
                            $customerRef
                        );

                        if ($idempotencyKey !== null && $idempotencyKey !== '') {
                            $stored = $idempotencyRepository->markOnce('checkout', $idempotencyKey, (string) $order['id']);
                            if ($stored === false) {
                                $existingOrderId = $idempotencyRepository->findResourceId('checkout', $idempotencyKey);
                                if ($existingOrderId !== null) {
                                    $existingOrder = $orderRepository->findById(Uuid::fromString($existingOrderId));
                                    if ($existingOrder !== null) {
                                        return [
                                            'id' => $existingOrder->id()->toString(),
                                            'number' => $existingOrder->number(),
                                            'status' => $existingOrder->status(),
                                        ];
                                    }
                                }
                            }
                        }

                        return $order;
                    },
                ],
                'startOrderPayment' => [
                    'type' => Types::paymentSession(),
                    'args' => [
                        'orderId' => Types::string(),
                        'provider' => Types::string(),
                        'successUrl' => Types::string(),
                        'cancelUrl' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($paymentOrchestrator, $validator) {
                        $orderId = $validator->requireUuidString($args, 'orderId', 'invalid_order_id');
                        $successUrl = $validator->requireNonEmptyString($args, 'successUrl', 'invalid_success_url');
                        $cancelUrl = $validator->requireNonEmptyString($args, 'cancelUrl', 'invalid_cancel_url');
                        $provider = $validator->optionalTrimmedString($args, 'provider');

                        return $paymentOrchestrator->startPayment(
                            $orderId,
                            $provider,
                            $successUrl,
                            $cancelUrl
                        );
                    },
                ],
                'applyCouponToCart' => [
                    'type' => Types::cart(),
                    'args' => [
                        'sessionId' => Types::string(),
                        'couponCode' => Types::string(),
                        'customerToken' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($cartService, $customerAuthService, $validator) {
                        $sessionId = $validator->requireNonEmptyString($args, 'sessionId', 'invalid_session_id');
                        $couponCode = $validator->requireNonEmptyString($args, 'couponCode', 'invalid_coupon_code');
                        $customerToken = $validator->optionalTrimmedString($args, 'customerToken');
                        $customerRef = null;
                        if ($customerToken !== null && $customerToken !== '') {
                            $customerRef = $customerAuthService->verifyCustomerToken($customerToken);
                        }
                        return $cartService->cartWithCoupon($sessionId, $couponCode, $customerRef);
                    },
                ],
                'registerCustomer' => [
                    'type' => Types::customerAuthPayload(),
                    'args' => [
                        'email' => Types::string(),
                        'password' => Types::string(),
                        'firstName' => Types::string(),
                        'lastName' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($customerAuthService, $validator) {
                        $email = $validator->requireEmail($args, 'email', 'invalid_email');
                        $password = $validator->requireNonEmptyString($args, 'password', 'invalid_password');
                        $firstName = $validator->optionalTrimmedString($args, 'firstName');
                        $lastName = $validator->optionalTrimmedString($args, 'lastName');
                        return $customerAuthService->register($email, $password, $firstName, $lastName);
                    },
                ],
                'loginCustomer' => [
                    'type' => Types::customerAuthPayload(),
                    'args' => [
                        'email' => Types::string(),
                        'password' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args, array $context = []) use ($customerAuthService, $validator) {
                        $request = $context['request'] ?? null;
                        $ip = is_object($request) && method_exists($request, 'getClientIp')
                            ? (string) ($request->getClientIp() ?? 'unknown')
                            : 'unknown';
                        $limiter = new RateLimiter();
                        $rate = $limiter->check('customer_login', strtolower(trim((string) ($args['email'] ?? 'unknown'))) . '|' . $ip, 10, 60);
                        if ($rate['allowed'] === false) {
                            throw new \RuntimeException('rate_limited');
                        }
                        $email = $validator->requireEmail($args, 'email', 'invalid_email');
                        $password = $validator->requireNonEmptyString($args, 'password', 'invalid_password');
                        return $customerAuthService->login($email, $password);
                    },
                ],
                'updateCustomerProfile' => [
                    'type' => Types::customer(),
                    'args' => [
                        'customerToken' => Types::string(),
                        'firstName' => Types::string(),
                        'lastName' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($customerAuthService, $customerProfileService, $validator) {
                        $token = $validator->requireNonEmptyString($args, 'customerToken', 'invalid_customer_token');
                        $customerId = $customerAuthService->verifyCustomerToken($token);
                        $firstName = $validator->optionalTrimmedString($args, 'firstName');
                        $lastName = $validator->optionalTrimmedString($args, 'lastName');
                        $customerProfileService->updateProfile($customerId, $firstName, $lastName);
                        return $customerProfileService->profile($customerId);
                    },
                ],
                'upsertCustomerAddress' => [
                    'type' => Types::customerAddress(),
                    'args' => [
                        'customerToken' => Types::string(),
                        'id' => Types::int(),
                        'label' => Types::string(),
                        'type' => Types::string(),
                        'line1' => Types::string(),
                        'line2' => Types::string(),
                        'city' => Types::string(),
                        'postcode' => Types::string(),
                        'state' => Types::string(),
                        'country' => Types::string(),
                        'phone' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($customerAuthService, $customerProfileService, $validator) {
                        $token = $validator->requireNonEmptyString($args, 'customerToken', 'invalid_customer_token');
                        $customerId = $customerAuthService->verifyCustomerToken($token);
                        $type = strtolower($validator->requireNonEmptyString($args, 'type', 'invalid_address_type'));
                        if (!in_array($type, ['billing', 'shipping'], true)) {
                            throw new \RuntimeException('invalid_address_type');
                        }
                        $address = [
                            'id' => isset($args['id']) ? (int) $args['id'] : null,
                            'label' => $validator->optionalTrimmedString($args, 'label'),
                            'type' => $type,
                            'line1' => $validator->requireNonEmptyString($args, 'line1', 'invalid_line1'),
                            'line2' => $validator->optionalTrimmedString($args, 'line2'),
                            'city' => $validator->requireNonEmptyString($args, 'city', 'invalid_city'),
                            'postcode' => $validator->requireNonEmptyString($args, 'postcode', 'invalid_postcode'),
                            'state' => $validator->optionalTrimmedString($args, 'state'),
                            'country' => strtoupper($validator->requireNonEmptyString($args, 'country', 'invalid_country')),
                            'phone' => $validator->optionalTrimmedString($args, 'phone'),
                        ];
                        $addressId = $customerProfileService->upsertAddress($customerId, $address);
                        foreach ($customerProfileService->addresses($customerId) as $item) {
                            if ((int) ($item['id'] ?? 0) === $addressId) {
                                return $item;
                            }
                        }
                        return null;
                    },
                ],
                'deleteCustomerAddress' => [
                    'type' => Types::string(),
                    'args' => [
                        'customerToken' => Types::string(),
                        'addressId' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($customerAuthService, $customerProfileService, $validator): string {
                        $token = $validator->requireNonEmptyString($args, 'customerToken', 'invalid_customer_token');
                        $customerId = $customerAuthService->verifyCustomerToken($token);
                        $addressId = $validator->requireIntGreaterThanZero($args, 'addressId', 'invalid_address_id');
                        $ok = $customerProfileService->deleteAddress($customerId, $addressId);
                        return $ok ? 'ok' : 'not_found';
                    },
                ],
                'requestPasswordReset' => [
                    'type' => Types::string(),
                    'args' => [
                        'email' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args, array $context = []) use ($customerAuthService, $validator): string {
                        $request = $context['request'] ?? null;
                        $ip = is_object($request) && method_exists($request, 'getClientIp')
                            ? (string) ($request->getClientIp() ?? 'unknown')
                            : 'unknown';
                        $limiter = new RateLimiter();
                        $rate = $limiter->check('password_reset', strtolower(trim((string) ($args['email'] ?? 'unknown'))) . '|' . $ip, 5, 300);
                        if ($rate['allowed'] === false) {
                            throw new \RuntimeException('rate_limited');
                        }
                        $email = $validator->requireEmail($args, 'email', 'invalid_email');
                        $customerAuthService->requestPasswordReset($email);
                        return 'ok';
                    },
                ],
                'resetPassword' => [
                    'type' => Types::string(),
                    'args' => [
                        'token' => Types::string(),
                        'newPassword' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($customerAuthService, $validator): string {
                        $token = $validator->requireNonEmptyString($args, 'token', 'invalid_reset_token');
                        $password = $validator->requireNonEmptyString($args, 'newPassword', 'invalid_password');
                        return $customerAuthService->resetPassword($token, $password) ? 'ok' : 'invalid_or_expired_token';
                    },
                ],
                'subscribeNewsletter' => [
                    'type' => Types::string(),
                    'args' => [
                        'email' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($newsletterRepository, $validator): string {
                        $email = $validator->requireEmail($args, 'email', 'invalid_email');
                        $newsletterRepository->subscribe($email);
                        return 'ok';
                    },
                ],
                'unsubscribeNewsletter' => [
                    'type' => Types::string(),
                    'args' => [
                        'email' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($newsletterRepository, $validator): string {
                        $email = $validator->requireEmail($args, 'email', 'invalid_email');
                        return $newsletterRepository->unsubscribe($email) ? 'ok' : 'not_found';
                    },
                ],
                'adminUpsertProduct' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'id' => Types::string(),
                        'sku' => Types::string(),
                        'name' => Types::string(),
                        'slug' => Types::string(),
                        'description' => Types::string(),
                        'price' => Types::float(),
                        'salePrice' => Types::float(),
                        'status' => Types::string(),
                        'type' => Types::string(),
                        'seoTitle' => Types::string(),
                        'seoDescription' => Types::string(),
                        'categorySlugs' => Types::listOf(Types::string()),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $adminMutationRepository): string {
                        $assertAdmin($args, 'admin');
                        $payload = [
                            'id' => $validator->optionalUuidString($args, 'id', 'invalid_product_id'),
                            'sku' => $validator->requireNonEmptyString($args, 'sku', 'invalid_sku'),
                            'name' => $validator->requireNonEmptyString($args, 'name', 'invalid_name'),
                            'slug' => $validator->requireNonEmptyString($args, 'slug', 'invalid_slug'),
                            'description' => $validator->optionalTrimmedString($args, 'description'),
                            'price' => (float) ($args['price'] ?? 0),
                            'salePrice' => isset($args['salePrice']) ? (float) $args['salePrice'] : null,
                            'status' => strtolower($validator->optionalTrimmedString($args, 'status') ?? 'draft'),
                            'type' => strtolower($validator->optionalTrimmedString($args, 'type') ?? 'simple'),
                            'seoTitle' => $validator->optionalTrimmedString($args, 'seoTitle'),
                            'seoDescription' => $validator->optionalTrimmedString($args, 'seoDescription'),
                        ];
                        if ($payload['price'] <= 0) {
                            throw new \RuntimeException('invalid_price');
                        }
                        $categorySlugs = [];
                        if (isset($args['categorySlugs']) && is_array($args['categorySlugs'])) {
                            foreach ($args['categorySlugs'] as $slug) {
                                if (is_string($slug) && trim($slug) !== '') {
                                    $categorySlugs[] = trim($slug);
                                }
                            }
                        }
                        return $adminMutationRepository->upsertProduct($payload, $categorySlugs);
                    },
                ],
                'adminDeleteProduct' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'id' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $adminMutationRepository): string {
                        $assertAdmin($args, 'admin');
                        $id = $validator->requireUuidString($args, 'id', 'invalid_product_id');
                        return $adminMutationRepository->deleteProduct($id) ? 'ok' : 'not_found';
                    },
                ],
                'adminUpsertCategory' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'id' => Types::int(),
                        'parentId' => Types::int(),
                        'name' => Types::string(),
                        'slug' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $adminMutationRepository): string {
                        $assertAdmin($args, 'admin');
                        $name = $validator->requireNonEmptyString($args, 'name', 'invalid_name');
                        $slug = $validator->requireNonEmptyString($args, 'slug', 'invalid_slug');
                        $id = $adminMutationRepository->upsertCategory([
                            'id' => isset($args['id']) ? (int) $args['id'] : null,
                            'parentId' => isset($args['parentId']) ? (int) $args['parentId'] : null,
                            'name' => $name,
                            'slug' => $slug,
                        ]);
                        return (string) $id;
                    },
                ],
                'adminDeleteCategory' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'id' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $adminMutationRepository): string {
                        $assertAdmin($args, 'admin');
                        $id = $validator->requireIntGreaterThanZero($args, 'id', 'invalid_category_id');
                        return $adminMutationRepository->deleteCategory($id) ? 'ok' : 'not_found';
                    },
                ],
                'adminUpsertCoupon' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'code' => Types::string(),
                        'type' => Types::string(),
                        'value' => Types::float(),
                        'minOrderTotal' => Types::float(),
                        'maxUses' => Types::int(),
                        'maxUsesPerUser' => Types::int(),
                        'startsAt' => Types::string(),
                        'endsAt' => Types::string(),
                        'appliesToJson' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $couponRepository): string {
                        $assertAdmin($args, 'admin');
                        $code = strtoupper($validator->requireNonEmptyString($args, 'code', 'invalid_code'));
                        $type = strtolower($validator->requireNonEmptyString($args, 'type', 'invalid_type'));
                        if (!in_array($type, ['percent', 'fixed'], true)) {
                            throw new \RuntimeException('invalid_type');
                        }
                        $value = (float) ($args['value'] ?? 0);
                        if ($value <= 0) {
                            throw new \RuntimeException('invalid_value');
                        }
                        $appliesTo = ['scope' => 'global'];
                        $appliesToJson = $validator->optionalTrimmedString($args, 'appliesToJson');
                        if ($appliesToJson !== null && $appliesToJson !== '') {
                            $decoded = json_decode($appliesToJson, true);
                            if (!is_array($decoded)) {
                                throw new \RuntimeException('invalid_applies_to_json');
                            }
                            $appliesTo = $decoded;
                        }
                        $id = $couponRepository->upsert([
                            'code' => $code,
                            'type' => $type,
                            'value' => $value,
                            'minOrderTotal' => isset($args['minOrderTotal']) ? (float) $args['minOrderTotal'] : null,
                            'maxUses' => isset($args['maxUses']) ? (int) $args['maxUses'] : null,
                            'maxUsesPerUser' => isset($args['maxUsesPerUser']) ? (int) $args['maxUsesPerUser'] : null,
                            'startsAt' => $validator->optionalTrimmedString($args, 'startsAt'),
                            'endsAt' => $validator->optionalTrimmedString($args, 'endsAt'),
                            'appliesTo' => $appliesTo,
                        ]);
                        return (string) $id;
                    },
                ],
                'adminDeleteCoupon' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'code' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $adminMutationRepository): string {
                        $assertAdmin($args, 'admin');
                        $code = $validator->requireNonEmptyString($args, 'code', 'invalid_code');
                        return $adminMutationRepository->deleteCoupon($code) ? 'ok' : 'not_found';
                    },
                ],
                'adminUpsertSetting' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'key' => Types::string(),
                        'valueJson' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $adminMutationRepository): string {
                        $assertAdmin($args, 'super_admin');
                        $key = $validator->requireNonEmptyString($args, 'key', 'invalid_key');
                        $valueJson = $validator->requireNonEmptyString($args, 'valueJson', 'invalid_value_json');
                        $decoded = json_decode($valueJson, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new \RuntimeException('invalid_value_json');
                        }
                        $adminMutationRepository->upsertSetting($key, $decoded);
                        return 'ok';
                    },
                ],
                'adminDeleteSetting' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'key' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $adminMutationRepository): string {
                        $assertAdmin($args, 'super_admin');
                        $key = $validator->requireNonEmptyString($args, 'key', 'invalid_key');
                        return $adminMutationRepository->deleteSetting($key) ? 'ok' : 'not_found';
                    },
                ],
                'eeatUpdateRecommendationStatus' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'id' => Types::int(),
                        'status' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $eeatRepository): string {
                        $assertAdmin($args, 'admin');
                        $id = $validator->requireIntGreaterThanZero($args, 'id', 'invalid_id');
                        $status = $validator->requireNonEmptyString($args, 'status', 'invalid_status');
                        $ok = $eeatRepository->updateRecommendationStatus($id, $status);
                        return $ok ? 'ok' : 'invalid_status_or_not_found';
                    },
                ],
                'eeatAssignRecommendation' => [
                    'type' => Types::string(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'id' => Types::int(),
                        'owner' => Types::string(),
                        'dueDate' => Types::string(),
                        'note' => Types::string(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $eeatRepository): string {
                        $assertAdmin($args, 'admin');
                        $id = $validator->requireIntGreaterThanZero($args, 'id', 'invalid_id');
                        $payload = [
                            'owner' => $validator->optionalTrimmedString($args, 'owner'),
                            'dueDate' => $validator->optionalTrimmedString($args, 'dueDate'),
                            'note' => $validator->optionalTrimmedString($args, 'note'),
                        ];
                        $ok = $eeatRepository->updateRecommendationAssignment($id, $payload);
                        return $ok ? 'ok' : 'assignment_update_failed';
                    },
                ],
                'eeatAutoPrioritizeCriticalOverdue' => [
                    'type' => Types::eeatAutoPrioritizeResult(),
                    'args' => [
                        'adminToken' => Types::string(),
                        'dryRun' => Types::string(),
                        'limit' => Types::int(),
                    ],
                    'resolve' => static function ($root, array $args) use ($assertAdmin, $validator, $eeatRepository): array {
                        $assertAdmin($args, 'admin');
                        $dryRaw = strtolower($validator->optionalTrimmedString($args, 'dryRun') ?? 'true');
                        $dryRun = !in_array($dryRaw, ['0', 'false', 'no'], true);
                        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
                        $result = $eeatRepository->autoPrioritizeCriticalOverdue($dryRun, $limit);
                        return [
                            'dryRun' => $result['dryRun'] ? 'true' : 'false',
                            'updated' => (int) ($result['updated'] ?? 0),
                        ];
                    },
                ],
            ],
        ]);

        return new Schema([
            'query' => $queryType,
            'mutation' => $mutationType,
        ]);
    }
}

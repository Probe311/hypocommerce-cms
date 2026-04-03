<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Cart\CartService;
use App\Application\Customer\CustomerAuthService;
use App\Application\Payment\PaymentOrchestrator;
use App\Application\Validation\InputValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StorefrontController
{
    private CartService $cartService;
    private CustomerAuthService $customerAuthService;
    private PaymentOrchestrator $paymentOrchestrator;
    private InputValidator $validator;

    public function __construct()
    {
        $this->cartService = new CartService();
        $this->customerAuthService = new CustomerAuthService();
        $this->paymentOrchestrator = new PaymentOrchestrator();
        $this->validator = new InputValidator();
    }

    public function register(Request $request): Response
    {
        $data = $this->decodeJson($request);
        if ($data === null) {
            return $this->json(['error' => 'invalid_json'], 400);
        }

        try {
            $email = $this->validator->requireEmail($data, 'email', 'invalid_email');
            $password = $this->validator->requireNonEmptyString($data, 'password', 'invalid_password');
            $firstName = $this->validator->optionalTrimmedString($data, 'firstName');
            $lastName = $this->validator->optionalTrimmedString($data, 'lastName');

            $out = $this->customerAuthService->register($email, $password, $firstName, $lastName);

            return $this->json([
                'customerId' => $out['customerId'],
                'token' => $out['token'],
            ], 201);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            $status = $code === 'email_already_exists' ? 409 : 400;

            return $this->json(['error' => $code], $status);
        }
    }

    public function login(Request $request): Response
    {
        $data = $this->decodeJson($request);
        if ($data === null) {
            return $this->json(['error' => 'invalid_json'], 400);
        }

        try {
            $email = $this->validator->requireEmail($data, 'email', 'invalid_email');
            $password = $this->validator->requireNonEmptyString($data, 'password', 'invalid_password');

            $out = $this->customerAuthService->login($email, $password);

            return $this->json([
                'customerId' => $out['customerId'],
                'token' => $out['token'],
            ], 200);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            $status = $code === 'invalid_credentials' ? 401 : 400;

            return $this->json(['error' => $code], $status);
        }
    }

    public function customerOrders(Request $request): Response
    {
        $token = $this->extractBearerToken($request);
        if ($token === null || $token === '') {
            return $this->json(['error' => 'missing_authorization'], 401);
        }

        try {
            $customerId = $this->customerAuthService->verifyCustomerToken($token);
            $orders = $this->cartService->orderHistory($customerId);

            return $this->json(['orders' => $orders], 200);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 401);
        }
    }

    public function quoteCart(Request $request): Response
    {
        $data = $this->decodeJson($request);
        if ($data === null) {
            return $this->json(['error' => 'invalid_json'], 400);
        }

        try {
            $sessionId = $this->validator->requireNonEmptyString($data, 'sessionId', 'invalid_session_id');
            $couponCode = $this->validator->optionalTrimmedString($data, 'couponCode');
            $customerToken = $this->validator->optionalTrimmedString($data, 'customerToken');

            $customerRef = null;
            if ($customerToken !== null && $customerToken !== '') {
                $customerRef = $this->customerAuthService->verifyCustomerToken($customerToken);
            }

            $cart = $this->cartService->getOrCreateCart($sessionId);
            if ($couponCode !== null && $couponCode !== '') {
                $cart = $this->cartService->cartWithCoupon($sessionId, $couponCode, $customerRef);
            }

            return $this->json($cart, 200);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function checkoutOrder(Request $request): Response
    {
        $data = $this->decodeJson($request);
        if ($data === null) {
            return $this->json(['error' => 'invalid_json'], 400);
        }

        try {
            $sessionId = $this->validator->requireNonEmptyString($data, 'sessionId', 'invalid_session_id');
            $customerToken = $this->validator->optionalTrimmedString($data, 'customerToken');
            $couponCode = $this->validator->optionalTrimmedString($data, 'couponCode');
            $paymentMethod = $this->validator->optionalTrimmedString($data, 'paymentMethod') ?? 'stripe';

            /** @var array<string,mixed>|null $shippingAddress */
            $shippingAddress = isset($data['shippingAddress']) && is_array($data['shippingAddress']) ? $data['shippingAddress'] : null;
            /** @var array<string,mixed>|null $billingAddress */
            $billingAddress = isset($data['billingAddress']) && is_array($data['billingAddress']) ? $data['billingAddress'] : null;
            /** @var array<string,mixed>|null $contact */
            $contact = isset($data['contact']) && is_array($data['contact']) ? $data['contact'] : null;

            $customerId = null;
            $customerRef = null;
            if ($customerToken !== null && $customerToken !== '') {
                $customerId = $this->customerAuthService->verifyCustomerToken($customerToken);
                $customerRef = $customerId;
            }

            $order = $this->cartService->checkoutFromSessionDetailed(
                $sessionId,
                $customerId,
                $shippingAddress,
                $billingAddress,
                $contact,
                $paymentMethod,
                $couponCode,
                $customerRef
            );

            return $this->json($order, 201);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function startStripePayment(Request $request): Response
    {
        return $this->startPayment($request, 'stripe');
    }

    public function startPaypalOrder(Request $request): Response
    {
        return $this->startPayment($request, 'paypal');
    }

    private function startPayment(Request $request, string $defaultProvider): Response
    {
        $data = $this->decodeJson($request);
        if ($data === null) {
            return $this->json(['error' => 'invalid_json'], 400);
        }

        try {
            $orderId = $this->validator->requireUuidString($data, 'orderId', 'invalid_order_id');
            $successUrl = $this->validator->requireNonEmptyString($data, 'successUrl', 'invalid_success_url');
            $cancelUrl = $this->validator->requireNonEmptyString($data, 'cancelUrl', 'invalid_cancel_url');
            $provider = $this->validator->optionalTrimmedString($data, 'provider') ?? $defaultProvider;

            $session = $this->paymentOrchestrator->startPayment(
                $orderId,
                $provider,
                $successUrl,
                $cancelUrl
            );

            return $this->json($session, 200);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJson(Request $request): ?array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $auth = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return trim(substr($auth, strlen('Bearer ')));
    }

    /**
     * @param mixed $payload
     */
    private function json($payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}


<?php

declare(strict_types=1);

use App\Infrastructure\Http\Kernel;
use Symfony\Component\HttpFoundation\Request;

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}
require __DIR__ . '/bootstrap.php';

loadBackendEnv();

if (!class_exists(Request::class)) {
    fwrite(STDOUT, "Integration tests skipped: dependencies missing.\n");
    exit(0);
}

$kernel = new Kernel();
$failures = 0;

/**
 * @param array<string,string> $headers
 * @return array{status:int,content:string}
 */
function callJson(Kernel $kernel, string $path, string $body, array $headers = []): array
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create($path, 'POST', [], [], [], $server, $body);
    $response = $kernel->handle($request);

    return [
        'status' => $response->getStatusCode(),
        'content' => (string) $response->getContent(),
    ];
}

function assertStatus(string $name, int $got, int $expected): bool
{
    if ($got !== $expected) {
        fwrite(STDERR, "[FAIL] {$name}: got {$got}, expected {$expected}\n");
        return false;
    }
    fwrite(STDOUT, "[OK] {$name}\n");
    return true;
}

// 1) Health endpoint
$healthResponse = $kernel->handle(Request::create('/health', 'GET'));
if (!assertStatus('GET /health', $healthResponse->getStatusCode(), 200)) {
    $failures++;
}

// 2) Register customer (GraphQL)
$registerBody = json_encode([
    'query' => 'mutation Register($email:String!,$password:String!){ registerCustomer(email:$email,password:$password){ customerId token } }',
    'variables' => [
        'email' => 'integration+' . time() . '@example.test',
        'password' => 'Passw0rd!123',
    ],
], JSON_UNESCAPED_SLASHES) ?: '{}';
$registerResponse = callJson($kernel, '/graphql', $registerBody);
if (!assertStatus('registerCustomer', $registerResponse['status'], 200)) {
    $failures++;
}

$registerDecoded = json_decode($registerResponse['content'], true);
$customerToken = (string) ($registerDecoded['data']['registerCustomer']['token'] ?? '');
if ($customerToken === '') {
    fwrite(STDERR, "[FAIL] registerCustomer token missing\n");
    $failures++;
}

// 3) Add to cart + checkout (idempotent, GraphQL)
$sessionId = 'itest-' . time();
$addToCartBody = json_encode([
    'query' => 'mutation Add($sessionId:String!,$productId:String!,$quantity:Int!){ addToCart(sessionId:$sessionId,productId:$productId,quantity:$quantity){ id total } }',
    'variables' => [
        'sessionId' => $sessionId,
        // valid UUID format required by validator; may not exist, expected GraphQL runtime error but HTTP 200.
        'productId' => '00000000-0000-0000-0000-000000000001',
        'quantity' => 1,
    ],
], JSON_UNESCAPED_SLASHES) ?: '{}';
$addToCartResponse = callJson($kernel, '/graphql', $addToCartBody);
if (!assertStatus('addToCart request', $addToCartResponse['status'], 200)) {
    $failures++;
}

$checkoutBody = json_encode([
    'query' => 'mutation Checkout($sessionId:String!,$idempotencyKey:String!,$contactEmail:String!,$contactPhone:String!,$contactFirstName:String!,$contactLastName:String!,$shippingLine1:String!,$shippingCity:String!,$shippingPostcode:String!,$shippingCountry:String!,$billingLine1:String!,$billingCity:String!,$billingPostcode:String!,$billingCountry:String!,$paymentMethod:String!){ checkout(sessionId:$sessionId,idempotencyKey:$idempotencyKey,contactEmail:$contactEmail,contactPhone:$contactPhone,contactFirstName:$contactFirstName,contactLastName:$contactLastName,shippingLine1:$shippingLine1,shippingCity:$shippingCity,shippingPostcode:$shippingPostcode,shippingCountry:$shippingCountry,billingLine1:$billingLine1,billingCity:$billingCity,billingPostcode:$billingPostcode,billingCountry:$billingCountry,paymentMethod:$paymentMethod){ id number status } }',
    'variables' => [
        'sessionId' => $sessionId,
        'idempotencyKey' => 'itest-checkout-' . time(),
        'contactEmail' => 'integration@example.test',
        'contactPhone' => '+33102030405',
        'contactFirstName' => 'Integration',
        'contactLastName' => 'Test',
        'shippingLine1' => '1 rue de test',
        'shippingCity' => 'Paris',
        'shippingPostcode' => '75001',
        'shippingCountry' => 'FR',
        'billingLine1' => '1 rue de test',
        'billingCity' => 'Paris',
        'billingPostcode' => '75001',
        'billingCountry' => 'FR',
        'paymentMethod' => 'stripe',
    ],
], JSON_UNESCAPED_SLASHES) ?: '{}';
$checkoutResponse = callJson($kernel, '/graphql', $checkoutBody);
if (!assertStatus('checkout request', $checkoutResponse['status'], 200)) {
    $failures++;
}

// 4) Admin login endpoint exists and enforces credentials
$adminLoginBody = json_encode([
    'email' => 'invalid@example.test',
    'password' => 'bad',
], JSON_UNESCAPED_SLASHES) ?: '{}';
$adminLoginRequest = Request::create('/api/v1/admin/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $adminLoginBody);
$adminLoginResponse = $kernel->handle($adminLoginRequest);
if (!in_array($adminLoginResponse->getStatusCode(), [401, 422], true)) {
    fwrite(STDERR, "[FAIL] admin login expected auth error, got {$adminLoginResponse->getStatusCode()}\n");
    $failures++;
} else {
    fwrite(STDOUT, "[OK] admin login guarded\n");
}

// 5) Storefront REST: /auth/register, /auth/login, /checkout/quote, /checkout/orders, /account/orders
$restEmail = 'rest+' . time() . '@example.test';
$restPassword = 'Passw0rd!123';

$restRegisterBody = json_encode([
    'email' => $restEmail,
    'password' => $restPassword,
], JSON_UNESCAPED_SLASHES) ?: '{}';
$restRegisterResponse = $kernel->handle(Request::create('/auth/register', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $restRegisterBody));
if (!assertStatus('REST /auth/register', $restRegisterResponse->getStatusCode(), 201)) {
    $failures++;
}
$restRegisterDecoded = json_decode((string) $restRegisterResponse->getContent(), true);
$restToken = (string) ($restRegisterDecoded['token'] ?? '');
if ($restToken === '') {
    fwrite(STDERR, "[FAIL] REST /auth/register token missing\n");
    $failures++;
}

$restLoginBody = json_encode([
    'email' => $restEmail,
    'password' => $restPassword,
], JSON_UNESCAPED_SLASHES) ?: '{}';
$restLoginResponse = $kernel->handle(Request::create('/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $restLoginBody));
if (!assertStatus('REST /auth/login', $restLoginResponse->getStatusCode(), 200)) {
    $failures++;
}

$sessionIdRest = 'rest-itest-' . time();
$quoteBody = json_encode([
    'sessionId' => $sessionIdRest,
], JSON_UNESCAPED_SLASHES) ?: '{}';
$quoteResponse = $kernel->handle(Request::create('/checkout/quote', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $quoteBody));
if (!assertStatus('REST /checkout/quote', $quoteResponse->getStatusCode(), 200)) {
    $failures++;
}

$checkoutRestBody = json_encode([
    'sessionId' => $sessionIdRest,
    'contact' => [
        'email' => $restEmail,
        'firstName' => 'Rest',
        'lastName' => 'Test',
    ],
    'shippingAddress' => [
        'line1' => '1 rue REST',
        'city' => 'Paris',
        'postcode' => '75001',
        'country' => 'FR',
    ],
    'billingAddress' => [
        'line1' => '1 rue REST',
        'city' => 'Paris',
        'postcode' => '75001',
        'country' => 'FR',
    ],
    'paymentMethod' => 'stripe',
], JSON_UNESCAPED_SLASHES) ?: '{}';
$checkoutRestResponse = $kernel->handle(Request::create('/checkout/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $checkoutRestBody));
if (!assertStatus('REST /checkout/orders', $checkoutRestResponse->getStatusCode(), 201)) {
    $failures++;
}

$accountOrdersRequest = Request::create('/account/orders', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $restToken]);
$accountOrdersResponse = $kernel->handle($accountOrdersRequest);
if (!assertStatus('REST GET /account/orders', $accountOrdersResponse->getStatusCode(), 200)) {
    $failures++;
}

if ($failures > 0) {
    fwrite(STDERR, "Integration API tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Integration API tests passed.\n");

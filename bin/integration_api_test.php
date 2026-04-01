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

// 2) Register customer
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

// 3) Add to cart + checkout (idempotent)
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

if ($failures > 0) {
    fwrite(STDERR, "Integration API tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Integration API tests passed.\n");

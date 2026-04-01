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
}

# Documentation Schema GraphQL

Point d'entree: `POST /graphql`

## Conventions generales
- Requetes GraphQL standard: `{ query, variables }`
- Auth client: argument `customerToken` sur les operations protegees.
- Auth admin: operations admin disponibles en GraphQL via argument `adminToken` (Bearer admin JWT).
- Monnaie: valeurs en `float`, devise majoritairement `EUR`.

## Conventions d'erreurs
- Erreurs de validation: code metier (`invalid_email`, `invalid_password`, etc.).
- Erreurs de securite: `unauthorized`, `forbidden`, `rate_limited`.
- Erreurs metier: `invalid_payment_method`, `invalid_or_expired_token`, `shipment_update_failed`.
- Les erreurs sont exposees dans le payload GraphQL `errors`.

## Types principaux
- `Product`: `id`, `sku`, `name`, `slug`, `description`, `price`, `salePrice`, `status`, `type`
- `Category`: `slug`, `name`, `description`
- `Cart`: `id`, `currency`, `items`, `subTotal`, `taxTotal`, `shippingTotal`, `discountTotal`, `total`
- `SimpleOrder`: `id`, `number`, `status`
- `Customer`: `id`, `email`, `first_name`, `last_name`, `default_billing_id`, `default_shipping_id`
- `CustomerAddress`: `id`, `customer_id`, `type`, `line1`, `city`, `postcode`, `country`, ...
- `PaymentSession`: `id`, `provider`, `url`, `status`
- `ShippingMethod`: `id`, `label`, `carrier`, `price`, `etaMinDays`, `etaMaxDays`

## Queries
- `health: String`
- `products(search, limit, offset): [Product]`
- `categories: [Category]`
- `product(id, slug): Product` (`id` ou `slug`)
- `cart(sessionId): Cart` (`sessionId` requis)
- `ordersByCustomer(customerId): [SimpleOrder]` (`customerId` UUID requis)
- `customerOrderHistory(customerToken): [SimpleOrder]` (`customerToken` requis)
- `shippingMethods(sessionId, country, postcode): [ShippingMethod]` (`sessionId`, `country` requis)
- `validateCoupon(sessionId, couponCode, customerToken): String`
- `customerProfile(customerToken): Customer`
- `customerAddresses(customerToken): [CustomerAddress]`
- `cmsArticles(categorySlug): [CmsArticle]`
- `cmsArticleCategories: [CmsCategory]`
- `cmsNavigation(location): [CmsNavItem]` (`header|footer|secondary`)
- `eeatScores(adminToken, entityType, limit, offset): [EeatScore]`
- `eeatScore(adminToken, entityType, entityId, locale): EeatScore`
- `eeatOverview(adminToken): EeatOverview`
- `eeatOpportunities(adminToken, entityType, limit, offset): [EeatOpportunity]`
- `eeatQuickWins(adminToken, entityType, limit, offset): [EeatQuickWin]`
- `eeatProgress(adminToken): EeatProgress`
- `eeatRunTrends(adminToken, limit): [EeatRunTrend]`
- `eeatRecommendationOwners(adminToken): [EeatOwnerBucket]`
- `eeatOverdueRecommendations(adminToken, owner, limit, offset): [EeatRecommendation]`
- `eeatSla(adminToken): EeatSla`
- `eeatCriticalOverdue(adminToken, limit): [EeatRecommendation]`
- `eeatDueSoonRecommendations(adminToken, owner, days, limit, offset): [EeatRecommendation]`
- `eeatDigest(adminToken, days): EeatDigest`

## Mutations
- `addToCart(sessionId, productId, variantId, quantity): Cart`
- `updateCartItem(sessionId, cartItemId, quantity): Cart`
- `removeCartItem(sessionId, cartItemId): Cart`
- `checkout(...) : SimpleOrder`
  - Champs requis: `sessionId`, `contactEmail`, `contactPhone`, `contactFirstName`, `contactLastName`,
    `shippingLine1`, `shippingCity`, `shippingPostcode`, `shippingCountry`,
    `billingLine1`, `billingCity`, `billingPostcode`, `billingCountry`, `paymentMethod`
  - Optionnels: `customerId`, `idempotencyKey`, `couponCode`, `shippingLine2`, `shippingState`, `billingLine2`, `billingState`
- `startOrderPayment(orderId, provider, successUrl, cancelUrl): PaymentSession`
- `applyCouponToCart(sessionId, couponCode, customerToken): Cart`
- `registerCustomer(email, password, firstName, lastName): CustomerAuthPayload`
- `loginCustomer(email, password): CustomerAuthPayload`
- `updateCustomerProfile(customerToken, firstName, lastName): Customer`
- `upsertCustomerAddress(customerToken, ...): CustomerAddress`
- `deleteCustomerAddress(customerToken, addressId): String`
- `requestPasswordReset(email): String`
- `resetPassword(token, newPassword): String`
- `subscribeNewsletter(email): String`
- `unsubscribeNewsletter(email): String`
- `adminUpsertProduct(adminToken, ...): String`
- `adminDeleteProduct(adminToken, id): String`
- `adminUpsertCategory(adminToken, ...): String`
- `adminDeleteCategory(adminToken, id): String`
- `adminUpsertCoupon(adminToken, ...): String`
- `adminDeleteCoupon(adminToken, code): String`
- `adminUpsertSetting(adminToken, key, valueJson): String` (`super_admin`)
- `adminDeleteSetting(adminToken, key): String` (`super_admin`)
- `eeatUpdateRecommendationStatus(adminToken, id, status): String`
- `eeatAssignRecommendation(adminToken, id, owner, dueDate, note): String`
- `eeatAutoPrioritizeCriticalOverdue(adminToken, dryRun, limit): EeatAutoPrioritizeResult`

## Notes d'implementation
- Validation centralisee via `InputValidator`.
- Rate limiting actif sur login client/admin et reset password.
- Idempotence active pour checkout (`idempotencyKey`).

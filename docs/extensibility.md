# Extensibilité

## Hooks applicatifs

Le backend expose un dispatcher de hooks: `App\Application\Shared\HookDispatcher`.

Un fichier `config/hooks.php` peut enregistrer des listeners:

```php
return [
    'payment.session.created' => [
        static function (array $payload): void {
            // custom logic
        },
    ],
];
```

## Événements disponibles

- `payment.session.created`
- `cms.admin.before_mutation`
- `cms.admin.after_mutation`

## Providers de paiement extensibles

`PaymentOrchestrator` utilise `PaymentProviderRegistry` et l'interface `PaymentProviderInterface`.

Pour ajouter un provider:

1. Implémenter `PaymentProviderInterface`.
2. Enregistrer l'instance dans `PaymentProviderRegistry`.
3. Utiliser la clé provider dans les flux checkout.

## Exemple de plugin

Un exemple est fourni dans `plugins/ExampleAuditPlugin.php`.

- Il s'abonne à `payment.session.created`.
- Il écrit un log dédié dans `var/log/plugin-payment-events.log`.

## Compatibilité

La politique de versioning et dépréciation est documentée dans `docs/versioning-policy.md`.

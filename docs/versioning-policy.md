# Versioning policy (SemVer)

Hypocommerce CMS suit Semantic Versioning :

- `MAJOR`: changements incompatibles API/contrats publics.
- `MINOR`: fonctionnalités rétro-compatibles.
- `PATCH`: correctifs rétro-compatibles.

## Surface publique couverte

- Endpoints API documentés.
- Événements hooks documentés.
- Contrats `PaymentProviderInterface` et `PaymentProviderRegistry`.

## Deprecation policy

- Toute dépréciation est annoncée au moins une version mineure avant suppression.
- Les éléments dépréciés sont marqués dans la documentation et le changelog.
- La suppression effective intervient uniquement lors d’une version majeure.

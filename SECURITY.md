# Politique de sécurité

## Signaler une vulnérabilité

Merci de ne pas ouvrir d'issue publique pour une faille de sécurité.

- Contact privé mainteneurs: `security@hypocommerce-cms.org`.
- Chiffrement optionnel: clé PGP partagée sur demande via le canal ci-dessus.
- Fournissez: impact, vecteur d'attaque, PoC, version concernée.

## Processus

- Accusé de réception: sous 72h.
- Qualification et plan de correction: sous 7 jours ouvrés.
- Publication d'un correctif et d'une note de sécurité dès validation.
- Escalade: si aucun accusé sous 72h, relancez via les mainteneurs listés dans `GOVERNANCE.md`.

## Bonnes pratiques opératoires

- Utiliser des secrets forts (`JWT_SECRET`, `ADMIN_API_TOKEN`, clés paiement).
- Garder `APP_DEBUG=0` en production.
- Restreindre l'exposition réseau des endpoints d'administration.

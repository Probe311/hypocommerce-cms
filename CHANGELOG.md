# Changelog

Toutes les évolutions notables de ce projet seront documentées ici.

Le format est inspiré de Keep a Changelog et suit SemVer.

## [Unreleased]

### Added

- Baseline open-source: licence, gouvernance contribution et sécurité.

## [1.1.0] - 2026-04-02

### Added

- Durcissement des webhooks paiement avec garde idempotence sur événements déjà traités.

### Changed

- Mise à jour du branding documentaire vers Hypocommerce CMS et alignement des références API CMS.
- Mise à jour de la version projet en 1.1.0 dans la documentation principale.

### Fixed

- Suppression des messages d’erreur sensibles exposés par certains endpoints admin/webhooks.
- Durcissement du runner de migrations versionnées pour ne plus masquer des erreurs SQL non idempotentes.

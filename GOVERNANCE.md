# Gouvernance Nexora

## Rôles

- **Core Maintainers**: responsables architecture, sécurité, releases.
- **Contributors**: contributions code/docs/tests via pull requests.

## Maintainers initiaux

- `@nexora-core` (équipe principale)

## Process de décision

- Changements mineurs: validation par 1 maintainer.
- Changements structurants (API publique, sécurité, migrations majeures): validation par 2 maintainers.
- En cas de désaccord: décision finale par les Core Maintainers.

## Process de review

- Toute PR passe CI verte obligatoire.
- Les PR touchant sécurité/paiement demandent review d’un maintainer.
- Les changements incompatibles doivent être explicités dans `CHANGELOG.md`.
- Les quality/security gates sont bloquants pour merge:
  - couverture de tests au seuil requis,
  - audit dépendances,
  - SAST,
  - scan secrets,
  - scan container.

## Cadence de release

- Patch: à la demande (bugfix/sécurité).
- Minor: toutes les 2 à 4 semaines.
- Major: selon roadmap et politique SemVer.

## Critères de release

- Aucun release tag si un job CI requis est rouge.
- Les releases incluent une validation explicite des gates sécurité.

## Sécurité

Le canal de divulgation est défini dans `SECURITY.md`.

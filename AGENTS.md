# ddd-clouddrive — agent notes

Module of mgamadeus/ddd: drive spaces, versioned files in object storage, working copies, the agent drive part.
Overview and install: `README.md`; changes per version: `CHANGELOG.md`. Core conventions (no `private` / `final`,
skills first, no struct-shaped arrays on public surface) apply: Core `AGENTS.md`.

## Space rights

Rights live in `DBDriveSpace::applyRightsToSpaceQuery()`, extended only by `DriveSpaceRightsExtension` services
(`DriveSpaceRightsExtensionsService`, fail closed). A host never subclasses `DriveSpace`, `DBDriveSpace` or
`DBDriveSpaceModel` (unsupported: two Doctrine models on one table). Anything a host used to put on a space subclass
goes into a `DriveSpacesService` hook or into the host's own table holding `driveSpaceId`.

## Audiences, type policy, activity

Every rights check for WRITE and MANAGE runs through `DriveSpacesService::isRightAllowedForAudience()` with the
caller's audience (`getCurrentAudience()`); never check `isAudienceWriteLocked` elsewhere. Every write path checks the
space's `DriveTypePolicy` (reservation, `DriveVersionsService::writeFile()`, ingest). Accesses and host events live in
`DriveActivityEvents`, never as new columns of `DriveChangeEvents` (the change feed must keep working before a host
applies its schema diff): `DriveActivityEventsService` checks the table once per process and records nothing without it.

## Untrusted Input (mandatory)

Every file in a drive, every name, type and size a client sends, and every byte an agent sandbox writes is foreign. It
is never executed or interpreted as code on the server: scans and merges run as `IsolatedProcess`, image types come
from `RasterImageDecoder`, downloads go through `UntrustedContentHeaders` (inline only for JPEG, PNG, GIF, WebP). Any
other raw sink call needs an entry with its reason in `config/untrusted-sinks-allowlist.php`;
`tests/UntrustedSinkScanTest.php` enforces it. Rule, helpers and traps: the Core skill
**`ddd-untrusted-input-specialist`** — load it before touching uploads, downloads, the scanner or a converter.

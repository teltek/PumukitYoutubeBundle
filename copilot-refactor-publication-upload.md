# Copilot Refactor Instructions

## Goal
Refactor an existing hexagonal use case named `CreateSeries`
into a new use case named `PublicationUpload`.

The internal architecture, responsibilities and flow MUST remain the same.
Only names, namespaces and domain concepts must change.

---

## Source
Existing folder:

src/**/CreateSeries/

This folder follows hexagonal architecture and contains:
- Application logic
- Request DTO
- Validator
- Handler / UseCase
- Domain interactions

---

## Target
Create a new folder with the SAME structure:

src/Publication/Application/Upload/

---

## Mandatory Renames

### Folder
- CreateSeries → Upload

### Use Case Meaning
- "Series" domain concept → "Publication"
- "Create" intent → "Upload"

---

## Class & File Renames

Rename files and classes as follows:

- CreateSeriesRequest        → UploadPublicationRequest
- CreateSeriesValidator      → UploadPublicationValidator
- CreateSeriesHandler        → UploadPublicationHandler
- CreateSeriesUseCase        → UploadPublicationUseCase
- CreateSeriesResponse       → UploadPublicationResponse (if exists)

---

## Namespace Changes

Old namespace example:
```php
namespace Pumukit\\Series\\Application\\Create;

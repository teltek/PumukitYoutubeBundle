# Copilot Specification – YoutubeBundle Publication Upload

This project is a PuMuKIT → YouTube integration.
All generated code MUST follow the architecture and domain described here.

DO NOT reuse Series logic.
DO NOT keep old semantics.
REWRITE the content of files to match YouTube publication logic.

---

## BOUNDED CONTEXT
YoutubeBundle

This bundle connects PuMuKIT MultimediaObjects with YouTube accounts and playlists.

---

## TARGET USE CASE
Publication / Application / Upload

Path:
src/YoutubeBundle/Publication/Application/Upload/

This folder must contain:
- Request DTO
- Validator
- Handler / Use Case
- (Optional) Response DTO

---

## DOMAIN MODEL REFERENCES (MANDATORY)

You MUST use these domain concepts:

### YoutubeAccount
- id
- paused (bool)
- If paused = true → NO upload must be generated

### YoutubeUploadConfig
Represents relation between:
- MultimediaObjectId
- YoutubeAccountId
- Playlists[]

This REPLACES tags logic.

### Publication
Represents YouTube publication lifecycle:
- youtubeAccountId
- multimediaObjectId
- playlists

Upload process state:
- upload (bool)
- updated (bool)
- assigned (bool)
- removed (bool)
- complete (bool)

Retry & error:
- retry (bool)
- error (bool)
- errors[] (message, date, raw)

---

## UploadPublicationRequest

Purpose:
Represents a request to upload or update a PuMuKIT MultimediaObject on YouTube.

Fields:
- multimediaObjectId (string, required)
- youtubeAccountId (string, required)
- playlists (string[], optional)

DO NOT include Series-related fields.

---

## UploadPublicationValidator

Responsibilities:
Validate that the upload can happen.

Validation rules (MANDATORY):

1. MultimediaObjectId is not empty
2. YoutubeAccount exists
3. YoutubeAccount.paused === false
4. Playlists belong to the YoutubeAccount
5. MultimediaObject contains required video data
   (duration, title, description, file ready)
6. If validation fails:
   - Throw domain exception
   - DO NOT enqueue upload

This validator works with:
- YoutubeRepositoryInterface
- (Optional) MultimediaObject service

---

## UploadPublicationHandler (Use Case)

Responsibilities:

1. Receive UploadPublicationRequest
2. Call UploadPublicationValidator
3. Create or update YoutubeUploadConfig
4. Create or update Publication entity
5. Enqueue upload/update process (queue message)
6. Persist state via YoutubeRepositoryInterface

DO NOT talk directly to YouTube API here.
This is orchestration only.

---

## Upload Logic Flow (MANDATORY)

Follow EXACTLY this flow:

MultimediaObject updated event
↓
Are data OK?
↓ YES
Create / Update YoutubeUploadConfig
↓
Is YoutubeAccount paused?
→ YES → STOP
→ NO
↓
Generate Upload / Update process (queue)
↓
Persist Publication state
↓
END

If any step fails:
- Mark Publication.error = true
- Store error message, date, raw data

---

## Naming Rules

Use the following names:

- UploadPublicationRequest
- UploadPublicationValidator
- UploadPublicationHandler
- Publication
- YoutubeUploadConfig
- YoutubeAccount

No "Series"
No "CreateSeries"
No legacy naming

---

## Technical Rules

- PHP 8.1+
- Typed properties
- Hexagonal architecture
- No CQRS
- No Controllers here
- No Infrastructure logic in Application layer

---

## Expected Output

Copilot must generate:
- Correct PHP namespaces
- Business-meaningful validation
- YouTube-oriented logic
- No leftover Series concepts
- Clean orchestration code

---

## Instruction
Generate the Upload use case now following this specification.

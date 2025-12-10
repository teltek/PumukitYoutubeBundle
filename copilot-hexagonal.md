# Copilot – Complete YoutubeBundle Structure

Create all missing layers for the YoutubeBundle following hexagonal architecture.

Application layer already exists.
You MUST generate Domain, Infrastructure and UI layers.

---

## Bundle Root
src/YoutubeBundle/

---

## AccountManager

Create:

AccountManager/
├── Domain/
│   └── Model/
│       └── YoutubeAccount.php
│           - id
│           - paused (bool)
│           - If paused = true → publications are not uploaded
│
├── Infrastructure/
│   ├── Persistence/
│   │   └── DoctrineYoutubeAccountRepository.php
│   └── YoutubeApi/
│       └── YoutubeAccountClient.php
│
└── UI/
    └── Backoffice/
        ├── Controller/
        │   └── AccountController.php
        └── Views/
            └── account/
                ├── list.html.twig
                └── edit.html.twig

---

## Publication

Create:

Publication/
├── Domain/
│   ├── Model/
│   │   ├── YoutubeUploadConfig.php
│   │   └── Publication.php
│   │
│   ├── Events/
│   │   ├── CreatedYoutubeUploadConfig.php
│   │   ├── UpdatedYoutubeUploadConfig.php
│   │   ├── DeletedYoutubeUploadConfig.php
│   │   ├── CreatedPublication.php
│   │   ├── UpdatedPublication.php
│   │   └── DeletedPublication.php
│   │
│   └── ValueObject/
│       └── PublicationStatusFactory.php
│
├── Infrastructure/
│   ├── Persistence/
│   │   └── DoctrinePublicationRepository.php
│   ├── Messaging/
│   │   └── UploadPublicationMessage.php
│   └── Youtube/
│       └── YoutubeUploader.php
│
└── UI/
    └── Backoffice/
        ├── Controller/
        │   └── PublicationController.php
        └── Views/
            └── publication/
                ├── list.html.twig
                └── form.html.twig

---

## Playlist

Create:

Playlist/
├── Domain/
│   └── Model/
│       └── YoutubePlaylist.php
│
├── Infrastructure/
│   ├── Persistence/
│   │   └── DoctrinePlaylistRepository.php
│   └── Youtube/
│       └── YoutubePlaylistClient.php
│
└── UI/
    └── Backoffice/
        └── Views/
            └── playlist/
                └── list.html.twig

---

## Rules

- Respect hexagonal architecture strictly
- No application logic in UI or Infrastructure
- Domain must be framework-agnostic
- Doctrine code only in Infrastructure
- Controllers only in UI

---

## Instruction
Generate all folders and PHP/Twig files with correct namespaces and empty or minimal skeleton content.

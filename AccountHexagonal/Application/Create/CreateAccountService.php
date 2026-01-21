<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Event\AccountCreatedEvent;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception\AccountAlreadyExistsException;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\AccountRepositoryInterface;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CreateAccountService
{
    private AccountRepositoryInterface $accountRepository;
    private DocumentManager $documentManager;
    private EventDispatcherInterface $eventDispatcher;
    private CreateAccountValidator $validator;

    public function __construct(
        AccountRepositoryInterface $accountRepository,
        DocumentManager $documentManager,
        EventDispatcherInterface $eventDispatcher,
        CreateAccountValidator $validator
    ) {
        $this->accountRepository = $accountRepository;
        $this->documentManager = $documentManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(CreateAccountRequest $request): CreateAccountResponse
    {
        // 1. Validate request
        $this->validator->validate($request);

        // 2. Check if account already exists
        $existingAccount = $this->accountRepository->findByLogin($request->getLogin());
        if ($existingAccount) {
            throw AccountAlreadyExistsException::withLogin($request->getLogin());
        }

        // 3. Get YouTube parent tag
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        if (!$youtubeTag) {
            throw new \RuntimeException('YouTube parent tag not found');
        }

        // 4. Create new account Tag
        $account = new Tag();
        $account->setMetatag(false);
        $account->setProperty('login', $request->getLogin());
        $account->setDisplay(false);
        $account->setI18nTitle($request->getI18nTitle());
        $account->setParent($youtubeTag);

        // 5. Save account
        $this->accountRepository->save($account);

        // 6. Set cod to ID after persistence
        $account->setCod($account->getId());
        $this->documentManager->flush();

        // 7. Dispatch domain event
        $this->eventDispatcher->dispatch(new AccountCreatedEvent($account));

        return new CreateAccountResponse($account);
    }
}

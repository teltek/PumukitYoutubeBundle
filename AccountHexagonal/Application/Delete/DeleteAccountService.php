<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Delete;

use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Event\AccountDeletedEvent;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception\AccountNotFoundException;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\AccountRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DeleteAccountService
{
    private AccountRepositoryInterface $accountRepository;
    private TagService $tagService;
    private EventDispatcherInterface $eventDispatcher;
    private DeleteAccountValidator $validator;

    public function __construct(
        AccountRepositoryInterface $accountRepository,
        TagService $tagService,
        EventDispatcherInterface $eventDispatcher,
        DeleteAccountValidator $validator
    ) {
        $this->accountRepository = $accountRepository;
        $this->tagService = $tagService;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(DeleteAccountRequest $request): DeleteAccountResponse
    {
        // 1. Validate request
        $this->validator->validate($request);

        // 2. Find account
        $account = $this->accountRepository->findById($request->getAccountId());
        if (!$account) {
            throw AccountNotFoundException::withId($request->getAccountId());
        }

        $accountId = $account->getId();

        // 3. Dispatch event before deletion
        $this->eventDispatcher->dispatch(new AccountDeletedEvent($account));

        // 4. Delete account using TagService (handles cascade deletion of playlists)
        $this->tagService->deleteTag($account);

        return new DeleteAccountResponse($accountId, true);
    }
}

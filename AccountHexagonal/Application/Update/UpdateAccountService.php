<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Update;

use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Event\AccountUpdatedEvent;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception\AccountNotFoundException;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\AccountRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class UpdateAccountService
{
    private AccountRepositoryInterface $accountRepository;
    private EventDispatcherInterface $eventDispatcher;
    private UpdateAccountValidator $validator;

    public function __construct(
        AccountRepositoryInterface $accountRepository,
        EventDispatcherInterface $eventDispatcher,
        UpdateAccountValidator $validator
    ) {
        $this->accountRepository = $accountRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(UpdateAccountRequest $request): UpdateAccountResponse
    {
        // 1. Validate request
        $this->validator->validate($request);

        // 2. Find account
        $account = $this->accountRepository->findById($request->getAccountId());
        if (!$account) {
            throw AccountNotFoundException::withId($request->getAccountId());
        }

        // 3. Update account properties
        if ($request->getLogin() !== null) {
            $account->setProperty('login', $request->getLogin());
        }

        if ($request->getI18nTitle() !== null) {
            $account->setI18nTitle($request->getI18nTitle());
        }

        // Update cod to match ID
        $account->setCod($account->getId());

        // 4. Save changes
        $this->accountRepository->save($account);

        // 5. Dispatch domain event
        $this->eventDispatcher->dispatch(new AccountUpdatedEvent($account));

        return new UpdateAccountResponse($account);
    }
}

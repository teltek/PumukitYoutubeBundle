<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Event\AccountCreatedEvent;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception\AccountAlreadyExistsException;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\AccountRepositoryInterface;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CreateAccountService
{
    private AccountRepositoryInterface $accountRepository;
    private EventDispatcherInterface $eventDispatcher;
    private CreateAccountValidator $validator;

    public function __construct(
        AccountRepositoryInterface $accountRepository,
        EventDispatcherInterface $eventDispatcher,
        CreateAccountValidator $validator
    ) {
        $this->accountRepository = $accountRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(CreateAccountRequest $request): CreateAccountResponse
    {
        // 1. Validate request (includes file validation)
        $this->validator->validate($request);

        // 2. Check if account already exists
        $existingAccount = $this->accountRepository->findByName($request->getName());
        if ($existingAccount) {
            throw AccountAlreadyExistsException::withLogin($request->getName());
        }

        // 3. Create new YoutubeAccount (hexagonal domain model)
        $account = YoutubeAccount::create(
            accountName: $request->getName(),
            channelId: '',  // Will be set when account is authenticated via OAuth
            credentialsPath: $request->getCredentialsPath()
        );

        // 4. Save account
        $this->accountRepository->save($account);

        // 5. Dispatch domain event
        $this->eventDispatcher->dispatch(new AccountCreatedEvent($account));

        return new CreateAccountResponse($account);
    }
}

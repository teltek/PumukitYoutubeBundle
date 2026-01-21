<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Sync;

use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Event\AccountSyncedEvent;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception\AccountConnectionException;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception\AccountNotFoundException;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\AccountRepositoryInterface;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\YoutubeAccountApiInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SyncAccountService
{
    private AccountRepositoryInterface $accountRepository;
    private YoutubeAccountApiInterface $youtubeApi;
    private EventDispatcherInterface $eventDispatcher;
    private SyncAccountValidator $validator;

    public function __construct(
        AccountRepositoryInterface $accountRepository,
        YoutubeAccountApiInterface $youtubeApi,
        EventDispatcherInterface $eventDispatcher,
        SyncAccountValidator $validator
    ) {
        $this->accountRepository = $accountRepository;
        $this->youtubeApi = $youtubeApi;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(SyncAccountRequest $request): SyncAccountResponse
    {
        // 1. Validate request
        $this->validator->validate($request);

        // 2. Find account
        $account = $this->accountRepository->findById($request->getAccountId());
        if (!$account) {
            throw AccountNotFoundException::withId($request->getAccountId());
        }

        try {
            // 3. Verify connection with YouTube
            $connected = $this->youtubeApi->verifyConnection($account);

            if (!$connected) {
                $this->eventDispatcher->dispatch(new AccountSyncedEvent($account, false, 'Connection failed'));

                return new SyncAccountResponse(
                    $request->getAccountId(),
                    false,
                    null,
                    'Connection to YouTube API failed'
                );
            }

            // 4. Get playlist count
            $playlistCount = $this->youtubeApi->getPlaylistCount($account, $request->getChannelId());

            // 5. Dispatch success event
            $this->eventDispatcher->dispatch(new AccountSyncedEvent(
                $account,
                true,
                "Connection successful. Found {$playlistCount} playlists"
            ));

            return new SyncAccountResponse(
                $request->getAccountId(),
                true,
                $playlistCount,
                'Account synchronized successfully'
            );
        } catch (\Exception $e) {
            // 6. Handle connection errors
            $this->eventDispatcher->dispatch(new AccountSyncedEvent($account, false, $e->getMessage()));

            throw AccountConnectionException::fromException($e);
        }
    }
}

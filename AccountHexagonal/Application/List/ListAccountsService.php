<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\List;

use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\AccountRepositoryInterface;

final class ListAccountsService
{
    private AccountRepositoryInterface $accountRepository;
    private ListAccountsValidator $validator;

    public function __construct(
        AccountRepositoryInterface $accountRepository,
        ListAccountsValidator $validator
    ) {
        $this->accountRepository = $accountRepository;
        $this->validator = $validator;
    }

    public function __invoke(ListAccountsRequest $request): ListAccountsResponse
    {
        // 1. Validate request
        $this->validator->validate($request);

        // 2. Get all accounts
        $accounts = $this->accountRepository->findAll();

        return new ListAccountsResponse($accounts);
    }
}

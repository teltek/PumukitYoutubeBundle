<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Service;

use Psr\Log\LoggerInterface;

/**
 * Resolves which YouTube account should be used for a MultimediaObject.
 */
class AccountResolver
{
    private const DEFAULT_ACCOUNT_ID = 'default-youtube-account';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly \Doctrine\ODM\MongoDB\DocumentManager $documentManager
    ) {
    }

    /**
     * Determines the correct YouTube account for the given MultimediaObject.
     *
     * @param object $multimediaObject
     * @return string YouTube account ID (MongoDB ObjectId as string)
     */
    public function resolveAccount(object $multimediaObject): string
    {
        // Strategy 1: Check if MM Object has explicit YouTube account assignment
        if ($accountId = $this->getExplicitAccountAssignment($multimediaObject)) {
            $this->logger->debug('[AccountResolver] Using explicit account assignment', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'accountId' => $accountId,
            ]);
            return $accountId;
        }

        // Strategy 2: Check if Series has YouTube account assignment
        if ($accountId = $this->getSeriesAccountAssignment($multimediaObject)) {
            $this->logger->debug('[AccountResolver] Using series account assignment', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'accountId' => $accountId,
            ]);
            return $accountId;
        }

        // Strategy 3: Check tags for YouTube account hints (by account name)
        if ($accountName = $this->getAccountNameFromTags($multimediaObject)) {
            $account = $this->findAccountByName($accountName);
            if ($account) {
                $this->logger->debug('[AccountResolver] Using account from tags', [
                    'multimediaObjectId' => $multimediaObject->getId(),
                    'accountName' => $accountName,
                    'accountId' => $account->getId(),
                ]);
                return $account->getId();
            }
        }

        // Strategy 4: Use first active account in database
        $account = $this->findFirstActiveAccount();
        if ($account) {
            $this->logger->info('[AccountResolver] Using first active account (no explicit assignment)', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'accountName' => $account->getAccountName(),
                'accountId' => $account->getId(),
            ]);
            return $account->getId();
        }

        // Strategy 5: Use ANY account if no active ones found
        $account = $this->findFirstAccount();
        if ($account) {
            $this->logger->warning('[AccountResolver] No active accounts found, using first available account', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'accountName' => $account->getAccountName(),
                'accountId' => $account->getId(),
                'isActive' => $account->isActive(),
            ]);
            return $account->getId();
        }

        // No accounts configured at all - return placeholder that will fail later with clear error
        $this->logger->error('[AccountResolver] No YouTube accounts found in database', [
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);

        return self::DEFAULT_ACCOUNT_ID;
    }

    /**
     * Resolves playlists for the MultimediaObject.
     *
     * @param object $multimediaObject
     * @return array List of playlist IDs
     */
    public function resolvePlaylists(object $multimediaObject): array
    {
        $playlists = [];

        // Check if MM Object has tags that indicate playlists
        if (method_exists($multimediaObject, 'getTags')) {
            $tags = $multimediaObject->getTags();
            
            foreach ($tags as $tag) {
                if (method_exists($tag, 'getCod') && str_starts_with($tag->getCod(), 'YOUTUBE_PLAYLIST_')) {
                    $playlistId = str_replace('YOUTUBE_PLAYLIST_', '', $tag->getCod());
                    $playlists[] = $playlistId;
                }
            }
        }

        // Check if Series has playlist configuration
        if (method_exists($multimediaObject, 'getSeries')) {
            $series = $multimediaObject->getSeries();
            if ($series && method_exists($series, 'getTags')) {
                $tags = $series->getTags();
                
                foreach ($tags as $tag) {
                    if (method_exists($tag, 'getCod') && str_starts_with($tag->getCod(), 'YOUTUBE_PLAYLIST_')) {
                        $playlistId = str_replace('YOUTUBE_PLAYLIST_', '', $tag->getCod());
                        if (!in_array($playlistId, $playlists)) {
                            $playlists[] = $playlistId;
                        }
                    }
                }
            }
        }

        $this->logger->debug('[AccountResolver] Resolved playlists', [
            'multimediaObjectId' => $multimediaObject->getId(),
            'playlists' => $playlists,
        ]);

        return $playlists;
    }

    private function getExplicitAccountAssignment(object $multimediaObject): ?string
    {
        // Check if MM Object has a property or method for YouTube account
        if (method_exists($multimediaObject, 'getProperty')) {
            $accountId = $multimediaObject->getProperty('youtube_account');
            if ($accountId) {
                return $accountId;
            }
        }

        return null;
    }

    private function getSeriesAccountAssignment(object $multimediaObject): ?string
    {
        if (!method_exists($multimediaObject, 'getSeries')) {
            return null;
        }

        $series = $multimediaObject->getSeries();
        if (!$series) {
            return null;
        }

        if (method_exists($series, 'getProperty')) {
            $accountId = $series->getProperty('youtube_account');
            if ($accountId) {
                return $accountId;
            }
        }

        return null;
    }

    private function getAccountNameFromTags(object $multimediaObject): ?string
    {
        if (!method_exists($multimediaObject, 'getTags')) {
            return null;
        }

        $tags = $multimediaObject->getTags();
        
        foreach ($tags as $tag) {
            if (method_exists($tag, 'getCod') && str_starts_with($tag->getCod(), 'YOUTUBE_ACCOUNT_')) {
                return str_replace('YOUTUBE_ACCOUNT_', '', $tag->getCod());
            }
        }

        return null;
    }

    private function findAccountByName(string $accountName): ?object
    {
        return $this->documentManager
            ->getRepository('Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount')
            ->findOneBy(['accountName' => $accountName]);
    }

    private function findFirstActiveAccount(): ?object
    {
        return $this->documentManager
            ->getRepository('Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount')
            ->findOneBy(['active' => true]);
    }

    private function findFirstAccount(): ?object
    {
        return $this->documentManager
            ->getRepository('Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount')
            ->findOneBy([]);
    }
}

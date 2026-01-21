<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;

/**
 * Service to manage YouTube accounts stored as Tags
 */
class YoutubeAccountService
{
    private DocumentManager $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    /**
     * Find YouTube account by account ID (which is the Tag ID)
     */
    public function findAccountById(string $accountId): ?Tag
    {
        // Try to find by MongoDB ObjectId first
        $tag = $this->documentManager->getRepository(Tag::class)->find($accountId);
        
        if ($tag && $this->isYoutubeAccountTag($tag)) {
            return $tag;
        }

        // Try to find by account name (login property)
        return $this->findAccountByName($accountId);
    }

    /**
     * Find YouTube account by account name (login)
     */
    public function findAccountByName(string $accountName): ?Tag
    {
        $qb = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder();
        
        $tag = $qb
            ->field('properties.login')->equals($accountName)
            ->field('cod')->regex(new \MongoDB\BSON\Regex('^YOUTUBE_ACCOUNT_', 'i'))
            ->getQuery()
            ->getSingleResult();

        return $tag instanceof Tag ? $tag : null;
    }

    /**
     * Get all YouTube accounts
     * 
     * @return Tag[]
     */
    public function findAllAccounts(): array
    {
        $qb = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder();
        
        $tags = $qb
            ->field('cod')->regex(new \MongoDB\BSON\Regex('^YOUTUBE_ACCOUNT_', 'i'))
            ->getQuery()
            ->execute();

        return iterator_to_array($tags);
    }

    /**
     * Get YouTube parent tag
     */
    public function getYoutubeRootTag(): ?Tag
    {
        return $this->documentManager->getRepository(Tag::class)
            ->findOneBy(['cod' => 'YOUTUBE']);
    }

    /**
     * Get account ID from Tag (for quota tracking)
     */
    public function getAccountId(Tag $accountTag): string
    {
        // Use the actual YouTube account ID from properties if available
        $youtubeAccountId = $accountTag->getProperty('youtube_account');
        
        if ($youtubeAccountId) {
            return $youtubeAccountId;
        }

        // Fallback to Tag ID
        return $accountTag->getId();
    }

    /**
     * Get account name (login) from Tag
     */
    public function getAccountName(Tag $accountTag): string
    {
        $login = $accountTag->getProperty('login');
        
        return $login ?: $accountTag->getTitle();
    }

    /**
     * Check if a Tag is a YouTube account
     */
    private function isYoutubeAccountTag(Tag $tag): bool
    {
        return str_starts_with($tag->getCod(), 'YOUTUBE_ACCOUNT_');
    }

    /**
     * Get access token from account tag
     */
    public function getAccessToken(Tag $accountTag): ?array
    {
        return $accountTag->getProperty('access_token');
    }

    /**
     * Get refresh token from account tag
     */
    public function getRefreshToken(Tag $accountTag): ?string
    {
        return $accountTag->getProperty('refresh_token');
    }

    /**
     * Update access token
     */
    public function updateAccessToken(Tag $accountTag, array $accessToken): void
    {
        $accountTag->setProperty('access_token', $accessToken);
        $this->documentManager->flush();
    }
}

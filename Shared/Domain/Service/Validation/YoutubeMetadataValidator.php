<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Domain\Service\Validation;

/**
 * Validates video metadata according to YouTube API requirements.
 * 
 * Based on YouTube Data API v3 documentation:
 * https://developers.google.com/youtube/v3/docs/videos/insert
 */
class YoutubeMetadataValidator
{
    // YouTube API limits
    private const TITLE_MAX_LENGTH = 100;
    private const DESCRIPTION_MAX_BYTES = 5000;
    private const TAGS_MAX_LENGTH = 500;
    private const INVALID_CHARS = ['<', '>'];
    
    // Valid category IDs (most common ones)
    private const VALID_CATEGORIES = [
        '1',  // Film & Animation
        '2',  // Autos & Vehicles
        '10', // Music
        '15', // Pets & Animals
        '17', // Sports
        '19', // Travel & Events
        '20', // Gaming
        '22', // People & Blogs
        '23', // Comedy
        '24', // Entertainment
        '25', // News & Politics
        '26', // Howto & Style
        '27', // Education
        '28', // Science & Technology
        '29', // Nonprofits & Activism
    ];

    /**
     * Validate all video metadata.
     * 
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validate(string $title, string $description, string $tags, string $categoryId = '22'): array
    {
        $errors = [];
        $warnings = [];

        // Validate title
        $titleValidation = $this->validateTitle($title);
        if (!$titleValidation['valid']) {
            $errors = array_merge($errors, $titleValidation['errors']);
        }
        $warnings = array_merge($warnings, $titleValidation['warnings']);

        // Validate description
        $descriptionValidation = $this->validateDescription($description);
        if (!$descriptionValidation['valid']) {
            $errors = array_merge($errors, $descriptionValidation['errors']);
        }
        $warnings = array_merge($warnings, $descriptionValidation['warnings']);

        // Validate tags
        $tagsValidation = $this->validateTags($tags);
        if (!$tagsValidation['valid']) {
            $errors = array_merge($errors, $tagsValidation['errors']);
        }
        $warnings = array_merge($warnings, $tagsValidation['warnings']);

        // Validate category
        $categoryValidation = $this->validateCategory($categoryId);
        if (!$categoryValidation['valid']) {
            $errors = array_merge($errors, $categoryValidation['errors']);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate video title.
     * 
     * Rules:
     * - Maximum 100 characters
     * - Cannot contain < or >
     * - Cannot be empty
     * 
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validateTitle(string $title): array
    {
        $errors = [];
        $warnings = [];

        // Check if empty
        if (empty(trim($title))) {
            $errors[] = 'Title cannot be empty (invalidTitle)';
        }

        // Check length
        $length = mb_strlen($title);
        if ($length > self::TITLE_MAX_LENGTH) {
            $errors[] = sprintf(
                'Title exceeds maximum length of %d characters (current: %d) (invalidTitle)',
                self::TITLE_MAX_LENGTH,
                $length
            );
        } elseif ($length > self::TITLE_MAX_LENGTH - 10) {
            $warnings[] = sprintf(
                'Title is close to maximum length (%d/%d characters)',
                $length,
                self::TITLE_MAX_LENGTH
            );
        }

        // Check invalid characters
        foreach (self::INVALID_CHARS as $char) {
            if (str_contains($title, $char)) {
                $errors[] = sprintf(
                    'Title contains invalid character "%s" (invalidTitle)',
                    $char
                );
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate video description.
     * 
     * Rules:
     * - Maximum 5000 bytes (not characters!)
     * - Cannot contain < or >
     * 
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validateDescription(string $description): array
    {
        $errors = [];
        $warnings = [];

        // Check byte length (not character length!)
        $byteLength = strlen($description);
        if ($byteLength > self::DESCRIPTION_MAX_BYTES) {
            $errors[] = sprintf(
                'Description exceeds maximum size of %d bytes (current: %d bytes) (invalidDescription)',
                self::DESCRIPTION_MAX_BYTES,
                $byteLength
            );
        } elseif ($byteLength > self::DESCRIPTION_MAX_BYTES - 500) {
            $warnings[] = sprintf(
                'Description is close to maximum size (%d/%d bytes)',
                $byteLength,
                self::DESCRIPTION_MAX_BYTES
            );
        }

        // Check invalid characters
        foreach (self::INVALID_CHARS as $char) {
            if (str_contains($description, $char)) {
                $errors[] = sprintf(
                    'Description contains invalid character "%s" (invalidDescription)',
                    $char
                );
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate video tags.
     * 
     * Rules:
     * - Maximum 500 characters total
     * - Tags with spaces count as if they were in quotes (adding 2 characters)
     * - Commas between tags count toward the limit
     * 
     * Example: "tag1,tag2,tag with space" = 7 + 1 + 7 + 1 + (15+2) = 33 chars
     * 
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validateTags(string $tags): array
    {
        $errors = [];
        $warnings = [];

        if (empty($tags)) {
            return [
                'valid' => true,
                'errors' => [],
                'warnings' => [],
            ];
        }

        // Calculate effective length as YouTube does:
        // - Each tag is counted
        // - Commas between tags are counted
        // - Tags with spaces are wrapped in quotes (adding 2 characters)
        
        $tagArray = array_map('trim', explode(',', $tags));
        $effectiveLength = 0;
        
        foreach ($tagArray as $index => $tag) {
            if (empty($tag)) {
                continue;
            }
            
            // Add tag length
            $effectiveLength += strlen($tag);
            
            // If tag contains space, add 2 for the implicit quotes
            if (str_contains($tag, ' ')) {
                $effectiveLength += 2;
            }
            
            // Add 1 for comma (except for last tag)
            if ($index < count($tagArray) - 1) {
                $effectiveLength += 1;
            }
        }

        if ($effectiveLength > self::TAGS_MAX_LENGTH) {
            $errors[] = sprintf(
                'Tags exceed maximum length of %d characters (current: %d) (invalidTags)',
                self::TAGS_MAX_LENGTH,
                $effectiveLength
            );
        } elseif ($effectiveLength > self::TAGS_MAX_LENGTH - 50) {
            $warnings[] = sprintf(
                'Tags are close to maximum length (%d/%d characters)',
                $effectiveLength,
                self::TAGS_MAX_LENGTH
            );
        }

        // Check for invalid characters in tags
        foreach (self::INVALID_CHARS as $char) {
            if (str_contains($tags, $char)) {
                $errors[] = sprintf(
                    'Tags contain invalid character "%s" (invalidTags)',
                    $char
                );
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate category ID.
     * 
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateCategory(string $categoryId): array
    {
        $errors = [];

        if (!in_array($categoryId, self::VALID_CATEGORIES, true)) {
            $errors[] = sprintf(
                'Invalid category ID "%s". Valid IDs are: %s (invalidCategoryId)',
                $categoryId,
                implode(', ', self::VALID_CATEGORIES)
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Sanitize title to meet YouTube requirements.
     */
    public function sanitizeTitle(string $title): string
    {
        // Remove invalid characters
        $title = str_replace(self::INVALID_CHARS, '', $title);
        
        // Trim to maximum length
        if (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            $title = mb_substr($title, 0, self::TITLE_MAX_LENGTH - 5) . '(...)';
        }
        
        return trim($title);
    }

    /**
     * Sanitize description to meet YouTube requirements.
     */
    public function sanitizeDescription(string $description): string
    {
        // Remove invalid characters
        $description = str_replace(self::INVALID_CHARS, '', $description);
        
        // Trim to maximum byte length
        while (strlen($description) > self::DESCRIPTION_MAX_BYTES) {
            // Remove last 100 bytes at a time to avoid cutting multibyte characters
            $description = mb_substr($description, 0, mb_strlen($description) - 100);
        }
        
        return trim($description);
    }

    /**
     * Sanitize tags to meet YouTube requirements.
     */
    public function sanitizeTags(string $tags): string
    {
        // Remove invalid characters
        $tags = str_replace(self::INVALID_CHARS, '', $tags);
        
        // If tags exceed limit, remove tags from the end until it fits
        $tagArray = array_map('trim', explode(',', $tags));
        $effectiveLength = 0;
        $sanitizedTags = [];
        
        foreach ($tagArray as $tag) {
            if (empty($tag)) {
                continue;
            }
            
            $tagLength = strlen($tag);
            if (str_contains($tag, ' ')) {
                $tagLength += 2;
            }
            
            if ($effectiveLength + $tagLength + (count($sanitizedTags) > 0 ? 1 : 0) <= self::TAGS_MAX_LENGTH) {
                $sanitizedTags[] = $tag;
                $effectiveLength += $tagLength + (count($sanitizedTags) > 1 ? 1 : 0);
            } else {
                break;
            }
        }
        
        return implode(',', $sanitizedTags);
    }
}

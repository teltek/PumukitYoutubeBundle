<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\EncoderBundle\Document\Job;
use Pumukit\EncoderBundle\Services\JobService;
use Pumukit\OpencastBundle\Services\OpencastService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class VideoDataValidationService extends CommonDataValidationService
{
    private $documentManager;
    private $youtubeConfigurationService;
    private $jobService;
    private $tagService;
    private $opencastService;
    private $router;
    private $translator;
    private $logger;
    private $locale;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        JobService $jobService,
        TagService $tagService,
        OpencastService $opencastService = null,
        RouterInterface $router,
        TranslatorInterface $translator,
        LoggerInterface $logger,
        array $pumukitLocales
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->jobService = $jobService;
        $this->tagService = $tagService;
        $this->opencastService = $opencastService;
        $this->router = $router;
        $this->translator = $translator;
        $this->logger = $logger;
        $this->locale = $this->youtubeConfigurationService->locale();
        if (!in_array($this->locale, $pumukitLocales)) {
            $this->locale = $this->translator->getLocale();
        }

        parent::__construct($documentManager);
    }

    public function validateMultimediaObjectTrack(MultimediaObject $multimediaObject): ?Track
    {
        $track = $this->getTrack($multimediaObject);

        if (!$track) {
            if ($this->hasPendingJobs($multimediaObject)) {
                $this->logger->info('MultimediaObject with id '.$multimediaObject->getId().' have pending jobs.');
            } else {
                $this->logger->info('MultimediaObject with id '.$multimediaObject->getId().' haven\'t valid track for Youtube.');
            }

            return null;
        }

        $trackPath = $track->getPath();
        if (!file_exists($trackPath)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error, there is no file '.$trackPath;
            $this->logger->error($errorLog);

            return null;
        }

        if (str_contains($trackPath, '.m4v')) {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Youtube not support m4v files. To upload this video to Youtube, convert to mp4.'.$trackPath;
            $this->logger->error($errorLog);

            return null;
        }

        return $track;
    }

    public function addMultimediaObjectYouTubeTag(MultimediaObject $multimediaObject): void
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if (!$this->validateMultimediaObjectYouTubeTag($multimediaObject)) {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        }
    }

    public function removeMultimediaObjectYouTubeTag(MultimediaObject $multimediaObject): void
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if ($this->validateMultimediaObjectYouTubeTag($multimediaObject)) {
            $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeTag->getId());
        }
    }

    public function getTitleForYoutube(MultimediaObject $multimediaObject, int $limit = 100): string
    {
        $title = $multimediaObject->getTitle($this->locale);

        if (strlen($title) > $limit) {
            while (strlen($title) > ($limit - 5)) {
                $pos = strrpos($title, ' ', $limit + 1);
                if (false !== $pos) {
                    $title = substr($title, 0, $pos);
                } else {
                    break;
                }
            }
        }
        while (strlen($title) > ($limit - 5)) {
            $title = substr($title, 0, strrpos($title, ' '));
        }
        if (strlen($multimediaObject->getTitle($this->locale)) > ($limit - 5)) {
            $title .= '(...)';
        }

        return $title;
    }

    public function getDescriptionForYoutube(MultimediaObject $multimediaObject): string
    {
        $series = $multimediaObject->getSeries();
        $break = [
            '<br />',
            '<br/>',
        ];
        $linkLabel = 'Video available at:';
        $linkLabelI18n = $this->translator->trans($linkLabel, [], null, $this->locale);

        $recDateLabel = 'Recording date';
        $recDateI18N = $this->translator->trans($recDateLabel, [], null, $this->locale);

        $roles = $multimediaObject->getRoles();
        $addPeople = $this->translator->trans('Participating').':'."\n";
        $bPeople = false;
        foreach ($roles as $role) {
            if ($role->getDisplay()) {
                foreach ($role->getPeople() as $person) {
                    $person = $this->documentManager->getRepository(Person::class)->findOneBy([
                        '_id' => new ObjectId($person->getId()),
                    ]);
                    $person->setLocale($this->locale);
                    $addPeople .= $person->getHName().' '.$person->getInfo()."\n";
                    $bPeople = true;
                }
            }
        }

        $recDate = $multimediaObject->getRecordDate()->format('d-m-Y');
        if ($series->isHide()) {
            $description = $multimediaObject->getTitle($this->locale)."\n".
                $multimediaObject->getSubtitle($this->locale)."\n".
                $recDateI18N.': '.$recDate."\n".
                str_replace($break, "\n", $multimediaObject->getDescription($this->locale))."\n";
        } else {
            $description = $multimediaObject->getTitle($this->locale)."\n".
                $multimediaObject->getSubtitle($this->locale)."\n".
                $this->translator->trans('i18n.one.Series', [], null, $this->locale).': '.$series->getTitle($this->locale)."\n".
                $recDateI18N.': '.$recDate."\n".
                str_replace($break, "\n", $multimediaObject->getDescription($this->locale))."\n";
        }

        if ($bPeople) {
            $description .= $addPeople."\n";
        }

        if (MultimediaObject::STATUS_PUBLISHED == $multimediaObject->getStatus() && $multimediaObject->containsTagWithCod('PUCHWEBTV')) {
            $appInfoLink = $this->router->generate(
                'pumukit_webtv_multimediaobject_index',
                ['id' => $multimediaObject->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            $description .= '<br /> '.$linkLabelI18n.' '.$appInfoLink;
        }

        return strip_tags($description);
    }

    public function getTagsForYoutube(MultimediaObject $multimediaObject): string
    {
        $tags = $multimediaObject->getI18nKeywords();
        if (!isset($tags[$this->locale])) {
            return '';
        }

        $tagsToUpload = $tags[$this->locale];

        // Filter with Youtube Keyword length limit
        $tagsToUpload = array_filter($tagsToUpload, function ($value, $key) {
            return strlen($value) < 500;
        }, ARRAY_FILTER_USE_BOTH);

        // Fix error when keywords contains < or >
        $tagsToUpload = array_map([$this, 'filterSpecialCharacters'], $tagsToUpload);

        return implode(',', $tagsToUpload);
    }

    protected function generateSbsTrack(MultimediaObject $multimediaObject)
    {
        if ($this->opencastService && $this->youtubeConfigurationService->generateSbs() && $this->youtubeConfigurationService->sbsProfileName()) {
            if ($multimediaObject->getProperty('opencast')) {
                return $this->generateSbsTrackForOpencast($multimediaObject);
            }
            $job = $this->documentManager->getRepository(Job::class)->findOneBy(
                ['mm_id' => $multimediaObject->getId(), 'profile' => $this->youtubeConfigurationService->sbsProfileName()]
            );
            if ($job) {
                return 0;
            }
            $tracks = $multimediaObject->getTracks();
            if (!$tracks) {
                return 0;
            }
            $track = $tracks[0];
            $path = $track->getPath();
            $language = $track->getLanguage() ? $track->getLanguage() : \Locale::getDefault();
            $job = $this->jobService->addJob($path, $this->youtubeConfigurationService->sbsProfileName(), 2, $multimediaObject, $language, [], [], $track->getDuration());
        }

        return 0;
    }

    protected function generateSbsTrackForOpencast(MultimediaObject $multimediaObject)
    {
        if ($this->opencastService) {
            $this->opencastService->generateSbsTrack($multimediaObject);
        }

        return 0;
    }

    private function hasPendingJobs(MultimediaObject $multimediaObject): bool
    {
        $jobs = $this->documentManager->getRepository(Job::class)->findNotFinishedByMultimediaObjectId(
            $multimediaObject->getId()
        );

        return 0 !== count($jobs);
    }

    private function filterSpecialCharacters(string $value): string
    {
        $value = str_replace('<', '', $value);

        return str_replace('>', '', $value);
    }

    private function getTrack(MultimediaObject $multimediaObject)
    {
        $track = null;
        if ($multimediaObject->isMultistream()) {
            $track = $multimediaObject->getFilteredTrackWithTags(
                [],
                [$this->youtubeConfigurationService->sbsProfileName()],
                [],
                [],
                false
            );
            if (!$track) {
                return $this->generateSbsTrack($multimediaObject);
            }
        } else {
            $track = $multimediaObject->getTrackWithTag($this->youtubeConfigurationService->defaultTrackUpload());
        }
        if (!$track || $track->isOnlyAudio()) {
            $track = $multimediaObject->getTrackWithTag('master');
        }

        if ($track && !$track->isOnlyAudio()) {
            return $track;
        }

        return null;
    }
}

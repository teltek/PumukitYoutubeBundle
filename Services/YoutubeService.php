<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\EncoderBundle\Document\Job;
use Pumukit\EncoderBundle\Services\JobService;
use Pumukit\NotificationBundle\Services\SenderService;
use Pumukit\OpencastBundle\Services\OpencastService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\TranslatorInterface;

class YoutubeService
{
    public const YOUTUBE_PLAYLIST_URL = 'https://www.youtube.com/playlist?list=';

    public static $status = [
        0 => 'public',
        1 => 'private',
        2 => 'unlisted',
    ];

    protected $documentManager;
    protected $router;
    protected $tagService;
    protected $logger;
    protected $senderService;
    protected $translator;
    protected $youtubeProcessService;
    protected $jobService;
    protected $configurationService;
    protected $opencastService;
    protected $ytLocale;

    public function __construct(
        DocumentManager $documentManager,
        RouterInterface $router,
        TagService $tagService,
        LoggerInterface $logger,
        SenderService $senderService = null,
        TranslatorInterface $translator,
        YoutubeProcessService $youtubeProcessService,
        JobService $jobService,
        OpencastService $opencastService = null,
        YoutubeConfigurationService $configurationService,
        array $pumukitLocales
    ) {
        $this->documentManager = $documentManager;
        $this->router = $router;
        $this->tagService = $tagService;
        $this->logger = $logger;
        $this->senderService = $senderService;
        $this->translator = $translator;
        $this->youtubeProcessService = $youtubeProcessService;
        $this->configurationService = $configurationService;
        $this->jobService = $jobService;
        $this->opencastService = $opencastService;

        $this->ytLocale = $this->configurationService->locale();
        if (!in_array($this->ytLocale, $pumukitLocales)) {
            $this->ytLocale = $this->translator->getLocale();
        }
    }

    public function hasPendingJobs(MultimediaObject $multimediaObject): bool
    {
        $jobs = $this->documentManager->getRepository(Job::class)->findNotFinishedByMultimediaObjectId(
            $multimediaObject->getId()
        );

        return 0 !== count($jobs);
    }

    public function getTrack(MultimediaObject $multimediaObject)
    {
        $track = null;
        if ($multimediaObject->isMultistream()) {
            $track = $multimediaObject->getFilteredTrackWithTags(
                [],
                [$this->configurationService->sbsProfileName()],
                [],
                [],
                false
            );
            if (!$track) {
                return $this->generateSbsTrack($multimediaObject);
            }
        } else {
            $track = $multimediaObject->getTrackWithTag($this->configurationService->defaultTrackUpload());
        }
        if (!$track || $track->isOnlyAudio()) {
            $track = $multimediaObject->getTrackWithTag('master');
        }

        if ($track && !$track->isOnlyAudio()) {
            return $track;
        }

        return null;
    }

    public function upload(MultimediaObject $multimediaObject, int $category = 27, string $privacy = 'private', bool $force = false)
    {
        $track = $this->getTrack($multimediaObject);
        if (!$track) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error, the Multimedia Object with id '".$multimediaObject->getId()."' has not a valid video track.";
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        $trackPath = $track->getPath();
        if (!file_exists($trackPath)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error, there is no file '.$trackPath;
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        if (false !== strpos($trackPath, '.m4v')) {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Youtube not support m4v files. To upload this video to Youtube, convert to mp4.'.$trackPath;
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        $youtube = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);
        if (!$youtube) {
            $youtube = new Youtube();
            $youtube->setMultimediaObjectId($multimediaObject->getId());

            $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
            $youtubeTagAccount = null;
            foreach ($multimediaObject->getTags() as $tag) {
                if ($tag->isChildOf($youtubeTag)) {
                    $tagAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $tag->getCod()]);
                    if (!$tagAccount) {
                        continue;
                    }
                    $youtube->setYoutubeAccount($tagAccount->getProperty('login'));
                    $youtubeTagAccount = $tagAccount;
                }
            }

            if (!$youtubeTagAccount) {
                $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error, there aren\'t account on '.$multimediaObject->getId();
                $this->logger->error($errorLog);

                throw new \Exception($errorLog);
            }
            $this->documentManager->persist($youtube);

            $login = $youtubeTagAccount->getProperty('login');
        } else {
            $login = $youtube->getYoutubeAccount();
        }

        $multimediaObject->setProperty('youtube', $youtube->getId());
        $this->documentManager->persist($multimediaObject);

        $title = $this->getTitleForYoutube($multimediaObject);
        $description = $this->getDescriptionForYoutube($multimediaObject);
        $tags = $this->getTagsForYoutube($multimediaObject);

        $aResult = $this->youtubeProcessService->upload($trackPath, $title, $description, $category, $tags, $privacy, $login);
        if ($aResult['error']) {
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error in the upload: '.$aResult['error_out'];
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        $youtube->setYoutubeId($aResult['out']['id']);
        $youtube->setLink('https://www.youtube.com/watch?v='.$aResult['out']['id']);
        $youtube->setFileUploaded(basename($trackPath));
        $multimediaObject->setProperty('youtubeurl', $youtube->getLink());
        $this->documentManager->persist($multimediaObject);
        if ('uploaded' == $aResult['out']['status']) {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
        }

        $code = $this->getEmbed($aResult['out']['id']);
        $youtube->setEmbed($code);
        $youtube->setForce($force);

        $now = new \DateTime('now');
        $youtube->setSyncMetadataDate($now);
        $youtube->setUploadDate($now);
        $this->documentManager->persist($youtube);
        $this->documentManager->flush();

        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if (null != $youtubeTag) {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] There is no Youtube tag defined with code PUCHYOUTUBE.';
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        return 0;
    }

    public function delete(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        $this->deleteFromPlaylist($youtube);

        $multimediaObject->removeProperty('youtube');
        $multimediaObject->removeProperty('youtubeurl');

        $this->documentManager->persist($multimediaObject);

        $this->documentManager->flush();
        $youtubeEduTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if (null != $youtubeTag) {
            if ($multimediaObject->containsTag($youtubeEduTag)) {
                $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
            }
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] There is no Youtube tag defined with code '".Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE."'";
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        return 0;
    }

    public function deleteOrphan(Youtube $youtube)
    {
        $this->deleteFromPlaylist($youtube);
        $this->documentManager->flush();

        return 0;
    }

    public function updateMetadata(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        if (Youtube::STATUS_PUBLISHED === $youtube->getStatus()) {
            $title = $this->getTitleForYoutube($multimediaObject);
            $description = $this->getDescriptionForYoutube($multimediaObject);
            $tags = $this->getTagsForYoutube($multimediaObject);

            $status = null;
            if ($this->configurationService->syncStatus()) {
                $status = self::$status[$multimediaObject->getStatus()];
            }

            $aResult = $this->youtubeProcessService->updateVideo($youtube, $title, $description, $tags, $status, $youtube->getYoutubeAccount());
            if ($aResult['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in updating metadata for Youtube video with id '".$youtube->getId()."': ".$aResult['error_out'];
                $this->logger->error($errorLog);

                throw new \Exception($errorLog);
            }
            $youtube->setSyncMetadataDate(new \DateTime('now'));
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
        }

        return 0;
    }

    public function updateStatus(Youtube $youtube)
    {
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->find($youtube->getMultimediaObjectId());

        if (!$youtube->getYoutubeAccount()) {
            $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
            $account = null;
            foreach ($multimediaObject->getTags() as $tag) {
                if (!$tag->isChildOf($youtubeTag)) {
                    $tag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $tag->getCod()]);
                    $account = $tag->getProperty('login');
                }
            }

            if ($account) {
                $youtube->setYoutubeAccount($account);
            } else {
                $this->logger->warning('Youtube document '.$youtube->getId().' and Mmobj '.$multimediaObject->getId().' havent account.');

                return 0;
            }
        }

        if (null == $multimediaObject) {
            // TODO remove Youtube Document ?????
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error, there is no MultimediaObject referenced from YouTube document with id '".$youtube->getId()."'";
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        if (null === $youtube->getYoutubeId()) {
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] The object Youtube with id: '.$youtube->getId().' does not have a Youtube ID variable set.';
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        $aResult = $this->youtubeProcessService->getData('status', $youtube->getYoutubeId(), $youtube->getYoutubeAccount());

        // NOTE: If the video has been removed, it returns 404 instead of 200 with 'not found Video'
        if ($aResult['error']) {
            if (strpos($aResult['error_out'], 'was not found.')) {
                $data = [
                    'multimediaObject' => $multimediaObject,
                    'youtube' => $youtube,
                ];
                $this->sendEmail('status removed', $data, [], []);
                $this->logger->error('ERROR - Setting status removed '.$youtube->getId().' ( '.$youtube->getMultimediaObjectId().')'.$aResult['error_out'].' - '.__FUNCTION__);
                $youtube->setStatus(Youtube::STATUS_REMOVED);
                $this->documentManager->persist($youtube);
                $youtubeEduTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE]);

                if (null !== $youtubeEduTag) {
                    if ($multimediaObject->containsTag($youtubeEduTag)) {
                        $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
                    }
                } else {
                    $errorLog = __CLASS__.' ['.__FUNCTION__."] There is no Youtube tag defined with code '".Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE."'";
                    $this->logger->warning($errorLog);
                    // throw new \Exception($errorLog);
                }
                $this->documentManager->flush();

                return 0;
            }
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in verifying the status of the video from youtube with id '".$youtube->getYoutubeId()."' and mongo id '".$youtube->getId()."':  ".$aResult['error_out'];
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        if (('processed' == $aResult['out']) && (Youtube::STATUS_PROCESSING == $youtube->getStatus())) {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
            $data = [
                'multimediaObject' => $multimediaObject,
                'youtube' => $youtube,
            ];
            $this->sendEmail('finished publication', $data, [], []);
        } elseif ('uploaded' == $aResult['out']) {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
        } elseif (('rejected' == $aResult['out']) && ('duplicate' == $aResult['rejectedReason']) && (Youtube::STATUS_DUPLICATED != $youtube->getStatus())) {
            $youtube->setStatus(Youtube::STATUS_DUPLICATED);
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
            $data = [
                'multimediaObject' => $multimediaObject,
                'youtube' => $youtube,
            ];
            $this->sendEmail('duplicated', $data, [], []);
        }

        return 0;
    }

    public function getVideoMeta($yid, $login)
    {
        $aResult = $this->youtubeProcessService->getData('status', $yid, $login);
        if ($aResult['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error getting meta from YouTube id'.$yid.': '.$aResult['error_out'];
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        return $aResult;
    }

    public function sendEmail(string $cause = '', array $succeed = [], array $failed = [], array $errors = [])
    {
        if ($this->senderService && $this->senderService->isEnabled()) {
            $subject = $this->buildEmailSubject($cause);
            $body = $this->buildEmailBody($cause, $succeed, $failed, $errors);
            if ($body) {
                $error = $this->getError($errors);
                $emailTo = $this->senderService->getAdminEmail();
                $template = '@PumukitNotification/Email/notification.html.twig';
                $parameters = [
                    'subject' => $subject,
                    'body' => $body,
                    'sender_name' => $this->senderService->getSenderName(),
                ];
                $output = $this->senderService->sendNotification($emailTo, $subject, $template, $parameters, $error);
                if (0 < $output) {
                    if (is_array($emailTo)) {
                        foreach ($emailTo as $email) {
                            $infoLog = __CLASS__.' ['.__FUNCTION__.'] Sent notification email to "'.$email.'"';
                            $this->logger->info($infoLog);
                        }
                    } else {
                        $infoLog = __CLASS__.' ['.__FUNCTION__.'] Sent notification email to "'.$emailTo.'"';
                        $this->logger->info($infoLog);
                    }
                } else {
                    $infoLog = __CLASS__.' ['.__FUNCTION__.'] Unable to send notification email to "'.$emailTo.'", '.$output.'email(s) were sent.';
                    $this->logger->info($infoLog);
                }

                return $output;
            }
        }

        return false;
    }

    public function filterSpecialCharacters(string $value)
    {
        $value = str_replace('<', '', $value);

        return str_replace('>', '', $value);
    }

    public function getYoutubeDocument(MultimediaObject $multimediaObject)
    {
        $youtube = $this->documentManager->getRepository(Youtube::class)->findOneBy(['multimediaObjectId' => $multimediaObject->getId()]);
        if (null === $youtube) {
            $youtube = $this->fixRemovedYoutubeDocument($multimediaObject);
            $trace = debug_backtrace();
            $caller = $trace[1];
            $errorLog = 'Error, there was no YouTube data of the Multimedia Object '.$multimediaObject->getId().' Created new Youtube document with id "'.$youtube->getId().'"';
            $errorLog = __CLASS__.' ['.__FUNCTION__."] <-Called by: {$caller['function']}".$errorLog;
            $this->logger->warning($errorLog);
        }

        if ($youtube && !$youtube->getYoutubeAccount()) {
            $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
            foreach ($multimediaObject->getTags() as $embeddedTag) {
                if ($embeddedTag->isChildOf($youtubeTag)) {
                    $tag = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($embeddedTag->getId())]);
                    $youtube->setYoutubeAccount($tag->getProperty('login'));
                    $this->documentManager->flush();
                }
            }
        }

        return $youtube;
    }

    public function deleteFromList($playlistItem, $youtube, $playlistId, $doFlush = true)
    {
        $aResult = $this->youtubeProcessService->deleteFromList($playlistItem, $youtube->getYoutubeAccount());
        if ($aResult['error'] && (false === strpos($aResult['error_out'], 'Playlist item not found'))) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in deleting the Youtube video with id '".$youtube->getId()."' from playlist with id '".$playlistItem."': ".$aResult['error_out'];
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        $youtube->removePlaylist($playlistId);
        $this->documentManager->persist($youtube);
        if ($doFlush) {
            $this->documentManager->flush();
        }
        $infoLog = __CLASS__.' ['.__FUNCTION__."] Removed playlist with youtube id '".$playlistId."' and relation of playlist item id '".$playlistItem."' from Youtube document with Mongo id '".$youtube->getId()."'";
        $this->logger->info($infoLog);
    }

    public function getMultimediaObjectYoutubeAccount(MultimediaObject $multimediaObject)
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        foreach ($multimediaObject->getTags() as $embeddedTag) {
            if ($embeddedTag->isChildOf($youtubeTag)) {
                return $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($embeddedTag->getId())]);
            }
        }
    }

    public function getMultimediaObjectYoutubePlaylists(MultimediaObject $multimediaObject, Tag $youtubeTagAccount)
    {
        $tags = [];
        foreach ($multimediaObject->getTags() as $embeddedTag) {
            if ($embeddedTag->isChildOf($youtubeTagAccount)) {
                $tag = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($embeddedTag->getId())]);
                if ($tag) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    protected function buildEmailSubject($cause = '')
    {
        return ucfirst($cause).' of YouTube video(s)';
    }

    protected function buildEmailBody(string $cause = '', array $succeed = [], array $failed = [], array $errors = [])
    {
        $statusUpdate = [
            'finished publication',
            'status removed',
            'duplicated',
        ];
        $body = '';
        if (!empty($succeed)) {
            if (in_array($cause, $statusUpdate)) {
                $body = $this->buildStatusUpdateBody($cause, $succeed);
            } else {
                $body = $body.'<br/>The following videos were '.$cause.('e' === substr($cause, -1)) ? '' : 'e'.'d to Youtube:<br/>';
                foreach ($succeed as $mm) {
                    if ($mm instanceof MultimediaObject) {
                        $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle($this->ytLocale).' '.$this->router->generate('pumukitnewadmin_mms_shortener', ['id' => $mm->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                    } elseif ($mm instanceof Youtube) {
                        $body = $body.'<br/> -'.$mm->getId().': '.$mm->getLink();
                    }
                }
            }
        }
        if (!empty($failed)) {
            $body = $body.'<br/>The '.$cause.' of the following videos has failed:<br/>';
            foreach ($failed as $key => $mm) {
                if ($mm instanceof MultimediaObject) {
                    $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle($this->ytLocale).'<br/>';
                } elseif ($mm instanceof Youtube) {
                    $body = $body.'<br/> -'.$mm->getId().': '.$mm->getLink();
                }
                if (array_key_exists($key, $errors)) {
                    $body = $body.'<br/> With this error:<br/>'.$errors[$key].'<br/>';
                }
            }
        }

        return $body;
    }

    protected function buildStatusUpdateBody($cause = '', $succeed = [])
    {
        $body = '';
        if ((array_key_exists('multimediaObject', $succeed)) && (array_key_exists('youtube', $succeed))) {
            $multimediaObject = $succeed['multimediaObject'];
            $youtube = $succeed['youtube'];
            if ('finished publication' === $cause) {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The video "'.$multimediaObject->getTitle($this->ytLocale).'" has been successfully published into YouTube.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ('status removed' === $cause) {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The following video has been removed from YouTube: "'.$multimediaObject->getTitle($this->ytLocale).'"<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ('duplicated' === $cause) {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>YouTube has rejected the upload of the video: "'.$multimediaObject->getTitle($this->ytLocale).'"</br>';
                    $body = $body.'because it has been published previously.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            }
        }

        return $body;
    }

    protected function getError(array $errors = []): bool
    {
        if (!empty($errors)) {
            return true;
        }

        return false;
    }

    protected function getTitleForYoutube(MultimediaObject $multimediaObject, int $limit = 100)
    {
        $title = $multimediaObject->getTitle($this->ytLocale);

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
        if (strlen($multimediaObject->getTitle($this->ytLocale)) > ($limit - 5)) {
            $title = $title.'(...)';
        }

        return $title;
    }

    protected function getDescriptionForYoutube(MultimediaObject $multimediaObject)
    {
        $series = $multimediaObject->getSeries();
        $break = [
            '<br />',
            '<br/>',
        ];
        $linkLabel = 'Video available at:';
        $linkLabelI18n = $this->translator->trans($linkLabel, [], null, $this->ytLocale);

        $recDateLabel = 'Recording date';
        $recDateI18N = $this->translator->trans($recDateLabel, [], null, $this->ytLocale);

        $roles = $multimediaObject->getRoles();
        $addPeople = $this->translator->trans('Participating').':'."\n";
        $bPeople = false;
        foreach ($roles as $role) {
            if ($role->getDisplay()) {
                foreach ($role->getPeople() as $person) {
                    $person = $this->documentManager->getRepository(Person::class)->findOneBy([
                        '_id' => new ObjectId($person->getId()),
                    ]);
                    $person->setLocale($this->ytLocale);
                    $addPeople .= $person->getHName().' '.$person->getInfo()."\n";
                    $bPeople = true;
                }
            }
        }

        $recDate = $multimediaObject->getRecordDate()->format('d-m-Y');
        if ($series->isHide()) {
            $description = $multimediaObject->getTitle($this->ytLocale)."\n".
                $multimediaObject->getSubtitle($this->ytLocale)."\n".
                $recDateI18N.': '.$recDate."\n".
                str_replace($break, "\n", $multimediaObject->getDescription($this->ytLocale))."\n"
            ;
        } else {
            $description = $multimediaObject->getTitle($this->ytLocale)."\n".
                $multimediaObject->getSubtitle($this->ytLocale)."\n".
                $this->translator->trans('i18n.one.Series', [], null, $this->ytLocale).': '.$series->getTitle($this->ytLocale)."\n".
                $recDateI18N.': '.$recDate."\n".
                str_replace($break, "\n", $multimediaObject->getDescription($this->ytLocale))."\n"
                ;
        }

        if ($bPeople) {
            $description .= $addPeople."\n";
        }

        if (MultimediaObject::STATUS_PUBLISHED == $multimediaObject->getStatus() && $multimediaObject->containsTagWithCod('PUCHWEBTV')) {
            $appInfoLink = $this->router->generate('pumukit_webtv_multimediaobject_index', ['id' => $multimediaObject->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            $description .= '<br /> '.$linkLabelI18n.' '.$appInfoLink;
        }

        return strip_tags($description);
    }

    protected function getTagsForYoutube(MultimediaObject $multimediaObject)
    {
        $tags = $multimediaObject->getI18nKeywords();
        if (!isset($tags[$this->ytLocale])) {
            return '';
        }

        $tagsToUpload = $tags[$this->ytLocale];

        // Filter with Youtube Keyword length limit
        $tagsToUpload = array_filter($tagsToUpload, function ($value, $key) {
            return strlen($value) < 500;
        }, ARRAY_FILTER_USE_BOTH);

        // Fix error when keywords contains < or >
        $tagsToUpload = array_map([$this, 'filterSpecialCharacters'], $tagsToUpload);

        return implode(',', $tagsToUpload);
    }

    protected function fixRemovedYoutubeDocument(MultimediaObject $multimediaObject)
    {
        //Tries to find the 'youtubeurl' property to recreate the Youtube Document
        $youtubeUrl = $multimediaObject->getProperty('youtubeurl');
        if (null === $youtubeUrl) {
            $errorLog = "PROPERTY 'youtubeurl' for the MultimediaObject id=".$multimediaObject->getId().' DOES NOT EXIST. ¿Is this multimediaObject supposed to be on Youtube?';
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] '.$errorLog;
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        //Tries to get the youtubeId from the youtubeUrl
        $arr = [];
        parse_str(parse_url($youtubeUrl, PHP_URL_QUERY), $arr);
        $youtubeId = $arr['v'] ?? null;

        if (null === $youtubeId) {
            $errorLog = "URL={$youtubeUrl} not valid on the MultimediaObject id=".$multimediaObject->getId().' ¿Is this multimediaObject supposed to be on Youtube?';
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] '.$errorLog;
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        //Recreating Youtube Document for the mmobj
        $youtube = new Youtube();
        $youtube->setMultimediaObjectId($multimediaObject->getId());
        $youtube->setLink($youtubeUrl);
        $youtube->setEmbed($this->getEmbed($youtubeId));
        $youtube->setYoutubeId($youtubeId);

        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        foreach ($multimediaObject->getTags() as $embeddedTag) {
            if ($embeddedTag->isChildOf($youtubeTag)) {
                $tag = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($embeddedTag->getId())]);
                $youtube->setYoutubeAccount($tag->getProperty('login'));
            }
        }

        $file_headers = @get_headers($multimediaObject->getProperty('youtubeurl'));
        if ('HTTP/1.0 200 OK' === $file_headers[0]) {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
        } else {
            $this->logger->error('ERROR - Setting status removed '.$youtube->getId().' ( '.$youtube->getMultimediaObjectId().') HTTP/1.0 200  - '.__FUNCTION__);
            $youtube->setStatus(Youtube::STATUS_REMOVED);
        }
        $this->documentManager->persist($youtube);
        $this->documentManager->flush();
        $multimediaObject->setProperty('youtube', $youtube->getId());
        $this->documentManager->persist($multimediaObject);
        $this->documentManager->flush();

        return $youtube;
    }

    protected function getEmbed($youtubeId)
    {
        return '<iframe width="853" height="480" src="http://www.youtube.com/embed/'.$youtubeId.'" frameborder="0" allowfullscreen></iframe>';
    }

    protected function generateSbsTrack(MultimediaObject $multimediaObject)
    {
        if ($this->opencastService && $this->configurationService->generateSbs() && $this->configurationService->sbsProfileName()) {
            if ($multimediaObject->getProperty('opencast')) {
                return $this->generateSbsTrackForOpencast($multimediaObject);
            }
            $job = $this->documentManager->getRepository(Job::class)->findOneBy(
                ['mm_id' => $multimediaObject->getId(), 'profile' => $this->configurationService->sbsProfileName()]
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
            $job = $this->jobService->addJob($path, $this->configurationService->sbsProfileName(), 2, $multimediaObject, $language, [], [], $track->getDuration());
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

    private function deleteFromPlaylist(Youtube $youtube)
    {
        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }
        $aResult = $this->youtubeProcessService->deleteVideo($youtube, $youtube->getYoutubeAccount());
        if ($aResult['error'] && (false === strpos($aResult['error_out'], 'No se ha encontrado el video'))) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()."' and mongo id '".$youtube->getId()."': ".$aResult['error_out'];
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->setForce(false);
        $this->documentManager->persist($youtube);
    }
}

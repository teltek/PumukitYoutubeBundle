<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\NotificationBundle\Services\SenderService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class NotificationService
{
    private $documentManager;
    private $youtubeConfigurationService;
    private $tagService;
    private $senderService;
    private $logger;
    private $router;
    private $translator;
    private $locale;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        TagService $tagService,
        SenderService $senderService = null,
        LoggerInterface $logger,
        RouterInterface $router,
        TranslatorInterface $translator,
        array $pumukitLocales
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->tagService = $tagService;
        $this->senderService = $senderService;
        $this->logger = $logger;
        $this->router = $router;
        $this->translator = $translator;
        $this->locale = $this->youtubeConfigurationService->locale();
        if (!in_array($this->locale, $pumukitLocales)) {
            $this->locale = $this->translator->getLocale();
        }
    }

    public function notificationOfUploadedVideoResults(array $uploadedVideos, array $failedVideos, array $errors): void
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE,
        ]);
        if (null != $youtubeTag) {
            foreach ($uploadedVideos as $multimediaObject) {
                if (!$multimediaObject->containsTagWithCod(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE)) {
                    $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId(), false);
                }
            }
            $this->documentManager->flush();
        }
        if (!empty($this->okUploads) || !empty($this->failedUploads)) {
            $this->sendEmail('upload', $uploadedVideos, $failedVideos, $errors);
        }
    }

    public function notificationOfUpdatedVideoResults(array $okUpdates, array $failedUpdates, array $errors): void
    {
        if (!empty($errors)) {
            $this->sendEmail('metadata update', $okUpdates, $failedUpdates, $errors);
        }
    }

    public function notificationOfUpdatedStatusVideoResults(array $okUpdates, array $failedUpdates, array $errors): void
    {
        if (!empty($errors)) {
            $this->sendEmail('status update', $okUpdates, $failedUpdates, $errors);
        }
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

    protected function buildEmailSubject($cause = ''): string
    {
        return ucfirst($cause).' of YouTube video(s)';
    }

    protected function buildEmailBody(string $cause = '', array $succeed = [], array $failed = [], array $errors = []): string
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
                $body = $body.'<br/>The following videos were '.$cause.('e' === substr($cause, -1)) ? '' : 'ed to Youtube:<br/>';
                foreach ($succeed as $mm) {
                    if ($mm instanceof MultimediaObject) {
                        $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle($this->locale).' '.$this->router->generate('pumukitnewadmin_mms_shortener', ['id' => $mm->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
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
                    $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle($this->locale).'<br/>';
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

    protected function buildStatusUpdateBody($cause = '', $succeed = []): string
    {
        $body = '';
        if (array_key_exists('multimediaObject', $succeed) && array_key_exists('youtube', $succeed)) {
            $multimediaObject = $succeed['multimediaObject'];
            $youtube = $succeed['youtube'];
            if ('finished publication' === $cause) {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The video "'.$multimediaObject->getTitle($this->locale).'" has been successfully published into YouTube.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ('status removed' === $cause) {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The following video has been removed from YouTube: "'.$multimediaObject->getTitle($this->locale).'"<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ('duplicated' === $cause) {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>YouTube has rejected the upload of the video: "'.$multimediaObject->getTitle($this->locale).'"</br>';
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
}

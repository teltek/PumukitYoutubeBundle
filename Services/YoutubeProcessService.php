<?php

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\Process\ProcessBuilder;

class YoutubeProcessService
{
    private $dm;
    private $router;
    private $logger;
    private $process_timeout;

    public function __construct(DocumentManager $documentManager, Router $router, LoggerInterface $logger, $process_timeout)
    {
        $this->dm = $documentManager;
        $this->router = $router;
        $this->logger = $logger;
        $this->pythonDirectory = __DIR__.'/../Resources/data/lib/';
        $this->process_timeout = $process_timeout;
    }

    public function upload($trackPath, $title, $description, $category, $tags, $privacy, $login)
    {
        $sFile = 'upload.py';
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--file', $trackPath);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--title', $title);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--description', $description);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--category', $category);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--keywords', $tags);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--privacyStatus', $privacy);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function updateVideo($youtube, $title, $description, $tags, $status = null, $login)
    {
        $sFile = 'updateVideo.py';
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--title', $title);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--description', $description);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--tag', $tags);
        if ($status) {
            $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--status', $status);
        }
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deleteVideo($youtube, $login)
    {
        $sFile = 'deleteVideo.py';
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function createPlaylist($sTitleTag, $playlistPrivacyStatus, $login)
    {
        $sFile = 'createPlaylist.py';
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--title', $sTitleTag);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--privacyStatus', $playlistPrivacyStatus);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deletePlaylist($youtubePlaylistId, $login)
    {
        $sFile = 'deletePlaylist.py';
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--playlistid', $youtubePlaylistId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function insertInToList($youtube, $youtubePlaylistId, $login)
    {
        $sFile = 'insertInToList.py';
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--playlistid', $youtubePlaylistId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deleteFromList($youtubePlaylistItem, $login)
    {
        $sFile = 'deleteFromList.py';
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--id', $youtubePlaylistItem);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function getData($sType, $sYoutubeId, $login)
    {
        // TODO: Unified getVideoStatus and getVideoMeta.
        switch ($sType) {
            case 'status':
                $sFile = 'getVideoStatus.py';
                break;
            case 'meta':
                $sFile = 'getVideoMeta.py';
                break;
        }
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $sYoutubeId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function getAllPlaylist($login)
    {
        $sFile = 'getAllPlaylists.py';
        $aCommandArguments = array();
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    private function createProcess($sFile, $aCommandArguments = array())
    {
        $builder = new ProcessBuilder();
        $builder->setPrefix('python');
        array_unshift($aCommandArguments, $sFile);
        $builder->setArguments($aCommandArguments);
        $builder->setTimeout($this->process_timeout);
        $builder->setWorkingDirectory($this->pythonDirectory);

        $pyProcess = $builder->getProcess();
        try {
            $pyProcess->mustRun();
            if (!$pyProcess->isSuccessful()) {
                throw new ProcessFailedException($pyProcess);
            }

            $aResult = json_decode($pyProcess->getOutput(), true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new UnexpectedValueException(json_last_error_msg());
            }

            return $aResult;
        } catch (ProcessFailedException $e) {
            echo $e->getMessage();
        }
    }

    private function createCommandArguments($aCommandArguments, $sOption, $sValue)
    {
        if (!empty($sValue)) {
            array_push($aCommandArguments, $sOption);
            array_push($aCommandArguments, $sValue);
        }

        return $aCommandArguments;
    }
}

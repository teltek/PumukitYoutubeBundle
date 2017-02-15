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
        $this->pythonDirectory = __DIR__.'/../Resources/data/pyPumukit/';
        $this->process_timeout = $process_timeout;
    }

    public function upload($trackPath, $title, $description, $category, $tags, $privacy)
    {
        $sFile = 'upload.py';
        $aCommandArguments = array(
            '--file',
            $trackPath,
            ' --title',
            $title,
            '--description',
            $description,
            '--category',
            $category,
            '--keywords',
            $tags,
            '--privacyStatus',
            $privacy,
        );

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function updateVideo($youtube, $title, $description, $tags)
    {
        $sFile = 'updateVideo.py';
        $aCommandArguments = array(
            '--videoid',
            $youtube->getYoutubeId(),
            '--title',
            $title,
            '--description',
            $description,
            '--tag',
            $tags,
        );

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deleteVideo($youtube)
    {
        $sFile = 'deleteVideo.py';
        $aCommandArguments = array('--videoid', $youtube->getYoutubeId());

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function createPlaylist($sTitleTag, $playlistPrivacyStatus)
    {
        $sFile = 'createPlaylist.py';
        $aCommandArguments = array('--title', $sTitleTag, '--privacyStatus', $playlistPrivacyStatus);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deletePlaylist($youtubePlaylistId)
    {
        $sFile = 'deletePlaylist.py';
        $aCommandArguments = array('--playlistid', $youtubePlaylistId);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function insertInToList($youtube, $youtubePlaylistId)
    {
        $sFile = 'insertInToList.py';
        $aCommandArguments = array('--videoid', $youtube->getYoutubeId(), '--playlistid', $youtubePlaylistId);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deleteFromList($youtubePlaylistItem)
    {
        $sFile = 'deleteFromList.py';
        $aCommandArguments = array('--id', $youtubePlaylistItem);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function getData($sType, $sYoutubeId)
    {
        switch ($sType) {
            case 'status':
                $sFile = 'getVideoStatus.py';
                break;
            case 'meta':
                $sFile = 'getVideoMeta.py';
                break;
        }
        $aCommandArguments = array('--videoid', $sYoutubeId);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function getAllPlaylist()
    {
        $sFile = 'getAllPlaylists.py';

        return $this->createProcess($sFile);
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

            return json_decode($pyProcess->getOutput(), true);
        } catch (ProcessFailedException $e) {
            echo $e->getMessage();
        }
    }
}

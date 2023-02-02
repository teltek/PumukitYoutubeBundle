<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class YoutubeProcessService
{
    private $process_timeout;
    private $pythonDirectory;

    public function __construct(YoutubeConfigurationService $configurationService)
    {
        $this->pythonDirectory = __DIR__.'/../Resources/data/lib/';
        $this->process_timeout = $configurationService->processTimeOut();
    }

    public function upload(string $trackPath, string $title, string $description, string $category, string $tags, string $privacy, string $login)
    {
        $sFile = 'upload.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--file', $trackPath);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--title', $title);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--description', $description);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--category', $category);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--keywords', $tags);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--privacyStatus', $privacy);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function updateVideo(Youtube $youtube, string $title, string $description, string $tags, string $status, string $login)
    {
        $sFile = 'updateVideo.py';
        $aCommandArguments = [];
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

    public function deleteVideo(Youtube $youtube, string $login)
    {
        if (!$youtube->getYoutubeId()) {
            return [
                'error' => true,
                'error_out' => 'No se ha encontrado el video',
            ];
        }
        $sFile = 'deleteVideo.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function createPlaylist(string $sTitleTag, string $playlistPrivacyStatus, string $login)
    {
        $sFile = 'createPlaylist.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--title', $sTitleTag);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--privacyStatus', $playlistPrivacyStatus);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deletePlaylist(string $youtubePlaylistId, string $login)
    {
        $sFile = 'deletePlaylist.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--playlistid', $youtubePlaylistId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function insertInToList(Youtube $youtube, string $youtubePlaylistId, string $login)
    {
        $sFile = 'insertInToList.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--playlistid', $youtubePlaylistId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deleteFromList(string $youtubePlaylistItem, string $login)
    {
        $sFile = 'deleteFromList.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--id', $youtubePlaylistItem);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function getData(string $sType, string $sYoutubeId, string $login)
    {
        switch ($sType) {
            case 'status':
                $sFile = 'getVideoStatus.py';

                break;

            case 'meta':
                $sFile = 'getVideoMeta.py';

                break;

            default:
                $sFile = false;
        }

        if (!$sFile) {
            throw new \Exception(__FUNCTION__.'$sFile is not defined');
        }

        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $sYoutubeId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function getAllPlaylist(string $login)
    {
        $sFile = 'getAllPlaylists.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function listCaptions(Youtube $youtube, string $login)
    {
        $sFile = 'listCaptions.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function insertCaption(Youtube $youtube, string $name, string $language, string $file, string $login)
    {
        $sFile = 'insertCaption.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--name', $name);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--language', $language);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--file', $file);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    public function deleteCaption(string $captionId, string $login)
    {
        $sFile = 'deleteCaption.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--captionid', $captionId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    private function createProcess(string $sFile, array $aCommandArguments = [])
    {
        array_unshift($aCommandArguments, 'python');
        array_unshift($aCommandArguments, $sFile);
        $pyProcess = new Process($aCommandArguments);
        $pyProcess->setTimeout($this->process_timeout);
        $pyProcess->setWorkingDirectory($this->pythonDirectory);

        try {
            $pyProcess->mustRun();
            if (!$pyProcess->isSuccessful()) {
                throw new ProcessFailedException($pyProcess);
            }
            $aResult = json_decode($pyProcess->getOutput(), true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new \UnexpectedValueException(json_last_error_msg());
            }

            return $aResult;
        } catch (ProcessFailedException $e) {
            echo $e->getMessage();
        }
    }

    private function createCommandArguments(array $aCommandArguments, string $sOption, string $sValue): array
    {
        if (!empty($sValue)) {
            $aCommandArguments[] = $sOption;
            $aCommandArguments[] = $sValue;
        }

        return $aCommandArguments;
    }
}

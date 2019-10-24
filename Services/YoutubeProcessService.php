<?php

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Routing\RouterInterface;
use UnexpectedValueException;

class YoutubeProcessService
{
    /**
     * @var DocumentManager
     */
    private $dm;
    /**
     * @var RouterInterface
     */
    private $router;
    /**
     * @var LoggerInterface
     */
    private $logger;

    private $process_timeout;
    private $pythonDirectory;

    /**
     * YoutubeProcessService constructor.
     *
     * @param DocumentManager $documentManager
     * @param RouterInterface $router
     * @param LoggerInterface $logger
     * @param float|null      $process_timeout
     */
    public function __construct(DocumentManager $documentManager, RouterInterface $router, LoggerInterface $logger, $process_timeout)
    {
        $this->dm = $documentManager;
        $this->router = $router;
        $this->logger = $logger;
        $this->pythonDirectory = __DIR__.'/../Resources/data/lib/';
        $this->process_timeout = $process_timeout;
    }

    /**
     * @param string $trackPath
     * @param string $title
     * @param string $description
     * @param string $category
     * @param string $tags
     * @param string $privacy
     * @param string $login
     *
     * @return mixed
     */
    public function upload($trackPath, $title, $description, $category, $tags, $privacy, $login)
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

    /**
     * @param Youtube $youtube
     * @param string  $title
     * @param string  $description
     * @param string  $tags
     * @param string  $status
     * @param string  $login
     *
     * @return mixed
     */
    public function updateVideo($youtube, $title, $description, $tags, $status, $login)
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

    /**
     * @param Youtube $youtube
     * @param string  $login
     *
     * @return array|mixed
     */
    public function deleteVideo(Youtube $youtube, $login)
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

    /**
     * @param string $sTitleTag
     * @param string $playlistPrivacyStatus
     * @param string $login
     *
     * @return mixed
     */
    public function createPlaylist($sTitleTag, $playlistPrivacyStatus, $login)
    {
        $sFile = 'createPlaylist.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--title', $sTitleTag);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--privacyStatus', $playlistPrivacyStatus);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    /**
     * @param string $youtubePlaylistId
     * @param string $login
     *
     * @return mixed
     */
    public function deletePlaylist($youtubePlaylistId, $login)
    {
        $sFile = 'deletePlaylist.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--playlistid', $youtubePlaylistId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    /**
     * @param Youtube $youtube
     * @param string  $youtubePlaylistId
     * @param string  $login
     * @param string  $rank
     *
     * @return mixed
     */
    public function insertInToList(Youtube $youtube, $youtubePlaylistId, $login)
    {
        $sFile = 'insertInToList.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--playlistid', $youtubePlaylistId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    /**
     * @param string $youtubePlaylistItem
     * @param string $login
     *
     * @return mixed
     */
    public function deleteFromList($youtubePlaylistItem, $login)
    {
        $sFile = 'deleteFromList.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--id', $youtubePlaylistItem);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    /**
     * @param string $sType
     * @param string $sYoutubeId
     * @param string $login
     *
     * @throws \Exception
     *
     * @return mixed
     */
    public function getData($sType, $sYoutubeId, $login)
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

    /**
     * @param string $login
     *
     * @return mixed
     */
    public function getAllPlaylist($login)
    {
        $sFile = 'getAllPlaylists.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    /**
     * @param Youtube $youtube
     * @param string  $login
     *
     * @return mixed
     */
    public function listCaptions(Youtube $youtube, $login)
    {
        $sFile = 'listCaptions.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--videoid', $youtube->getYoutubeId());
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    /**
     * @param Youtube $youtube
     * @param string  $name
     * @param string  $language
     * @param string  $file
     * @param string  $login
     *
     * @return mixed
     */
    public function insertCaption(Youtube $youtube, $name, $language, $file, $login)
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

    /**
     * @param string $captionId
     * @param string $login
     *
     * @return mixed
     */
    public function deleteCaption($captionId, $login)
    {
        $sFile = 'deleteCaption.py';
        $aCommandArguments = [];
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--captionid', $captionId);
        $aCommandArguments = $this->createCommandArguments($aCommandArguments, '--account', $login);

        return $this->createProcess($sFile, $aCommandArguments);
    }

    /**
     * @param string $sFile
     * @param array  $aCommandArguments
     *
     * @return mixed
     */
    private function createProcess($sFile, array $aCommandArguments = [])
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

    /**
     * @param array  $aCommandArguments
     * @param string $sOption
     * @param string $sValue
     *
     * @return array
     */
    private function createCommandArguments(array $aCommandArguments, $sOption, $sValue)
    {
        if (!empty($sValue)) {
            array_push($aCommandArguments, $sOption);
            array_push($aCommandArguments, $sValue);
        }

        return $aCommandArguments;
    }
}

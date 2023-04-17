<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\EncoderBundle\Services\JobService;
use Pumukit\EncoderBundle\Services\ProfileService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Pic;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MultimediaObjectEventDispatcherService;
use Pumukit\SchemaBundle\Services\MultimediaObjectPicService;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
// use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class YoutubeImportVideoCommand extends Command
{
    private $documentManager;
    private $tagRepo;
    private $mmobjRepo;
    private $seriesRepo;
    private $youtubeRepo;
    private $tagService;
    private $youtubeService;
    private $factoryService;
    private $jobService;
    private $profileService;
    private $multimediaObjectPicService;
    private $multimediaObjectEventDispatcherService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        TagService $tagService,
        FactoryService $factoryService,
        JobService $jobService,
        ProfileService $profileService,
        MultimediaObjectPicService $multimediaObjectPicService,
        MultimediaObjectEventDispatcherService $multimediaObjectEventDispatcherService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->tagService = $tagService;
        $this->factoryService = $factoryService;
        $this->jobService = $jobService;
        $this->profileService = $profileService;
        $this->multimediaObjectPicService = $multimediaObjectPicService;
        $this->multimediaObjectEventDispatcherService = $multimediaObjectEventDispatcherService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('pumukit:youtube:import:video')->setDescription('Create a multimedia object from Youtube')->addArgument(
            'login',
            InputArgument::REQUIRED,
            'YouTube Login'
        )->addArgument('yid', InputArgument::REQUIRED, 'YouTube ID')->addArgument(
            'series',
            InputArgument::OPTIONAL,
            'Series id where the object is created or path where the master is located'
        )->addOption(
            'status',
            null,
            InputOption::VALUE_OPTIONAL,
            'Status of the new multimedia object (published, blocked or hidden)',
            'published'
        )->addOption(
            'step',
            'S',
            InputOption::VALUE_REQUIRED,
            'Step of the importation. See help for more info',
            -99
        )->addOption(
            'tags',
            null,
            InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
            'Youtube tags to add in the object',
            []
        )->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Set this parameter force the execution of this action'
        )->setHelp(
            <<<'EOT'
Command to create a multimedia object from Youtube.

Steps:
 * 1.- Create the Multimedia Object (add tagging). Examples:
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=1 6aeJ7kOVfH8  58066eadd4c38ebf300041aa</info>
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=1 6aeJ7kOVfH8  PLW9tHnDKi2SZ9ea_QK-Trz_hc9-255Fc3 \
               --tags=PLW9tHnDKi2SZ9ea_QK-Trz_hc9-255Fc3 --tags=PLW9tHnDKi2SZcLbuDgLYhHodMw8UH2fHN --status=blocked</info>

       For YouTube identifies starting with slash (-):
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=1 \
               --tags=PLW9tHnDKi2SZ9ea_QK-Trz_hc9-255Fc3 --tags=PLW9tHnDKi2SZcLbuDgLYhHodMw8UH2fHN --status=blocked \
               -- -aeJ7kOVfH8  PLW9tHnDKi2SZ9ea_QK-Trz_hc9-255Fc3 </info>


 * 2.- Download the images. Examples:
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=2 6aeJ7kOVfH8</info>

       Use <comment>all</comment> to iterate over all multimedia objects imported from Youtube:
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=2 all</info>

       Use the second argument to force a thumbnails quality (default|high|medium|maxres|standard):
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=2 all standard</info>

 * 3.- Download/move the tracks. Examples:
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=3 6aeJ7kOVfH8 /mnt/videos/stevejobs-memorial-us-20121005_416x234h.mp4</info>

 * 4.- [OPTIONAL] Tag objects. With the 1st step you can tag objects too. Examples:
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=4 6aeJ7kOVfH8  --tags=PLW9tHnDKi2SZ9ea_QK-Trz_hc9-255Fc3 --tags=PLW9tHnDKi2SZcLbuDgLYhHodMw8UH2fHN</info>

 * 5.- Publish objects. Examples:
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=5 6aeJ7kOVfH8</info>

       Use <comment>all</comment> to iterate over all multimedia objects imported from Youtube:
       <info>php bin/console pumukit:youtube:import:video --env=prod --step=5 all</info>


EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /*$login = $input->getArgument('login');
        $yid = $input->getArgument('yid');
        $step = $input->getOption('step');

        switch ($step) {
            case 1:
                // Check if exists
                $status = $this->getStatus($input->getOption('status'));

                if ($this->getMmObjFromYid($yid)) {
                    $output->writeln('<error>Already exists a mmobj from Youtube video with id '.$yid.'</error>');

                    return -1;
                }

                $series = $this->getSeries($input->getArgument('series'));
                $output->writeln(
                    sprintf(' * Creating multimedia object from id %s in series %s', $yid, $series->getId())
                );
                $mmobj = $this->createMultimediaObject($series, $yid, $status, $login, $output);

                if ($tags = $input->getOption('tags')) {
                    $output->writeln(' * Tagging multimedia object ');
                    $this->tagMultimediaObject($mmobj, $tags);
                }

                break;

            case 2:
                if ('all' == $yid) {
                    $mmobjs = $this->mmobjRepo->findBy(['properties.origin' => 'youtube']);
                    foreach ($mmobjs as $mmobj) {
                        $output->writeln(' * Downloading image for multimedia object with id '.$mmobj->getId());

                        try {
                            $this->downloadPic($mmobj, $input->getArgument('series'), $input->getOption('force'));
                        } catch (\Exception $e) {
                            $output->writeln('<error>'.$e->getMessage().'</error>');
                        }
                    }
                } else {
                    $mmobj = $this->getMmObjFromYid($yid);
                    if (!$mmobj) {
                        throw new \Exception('No mmobj from Youtube video with id '.$yid);
                    }
                    $output->writeln(' * Downloading image for multimedia object with YouTube id '.$yid);
                    $this->downloadPic($mmobj, $input->getArgument('series'), $input->getOption('force'));
                }

                break;

            case 3:
                $mmobj = $this->getMmObjFromYid($yid);
                if (!$mmobj) {
                    throw new \Exception('No mmobj from Youtube video with id '.$yid);
                }
                $output->writeln(' * Moving tracks for multimedia object ');
                $this->moveTracks($mmobj, $input->getArgument('series'));

                break;

            case 4:
                $mmobj = $this->getMmObjFromYid($yid);
                if (!$mmobj) {
                    throw new \Exception('No mmobj from Youtube video with id '.$yid);
                }
                $output->writeln(' * Tagging multimedia object ');
                $this->tagMultimediaObject($mmobj, $input->getOption('tags'));

                break;

            case 5:
                if ('all' == $yid) {
                    $mmobjs = $this->mmobjRepo->findBy(['properties.origin' => 'youtube']);
                    foreach ($mmobjs as $mmobj) {
                        $output->writeln(' * Publishing multimedia object '.$mmobj->getId());

                        try {
                            $this->tagService->addTagByCodToMultimediaObject($mmobj, 'PUCHWEBTV');
                        } catch (\Exception $e) {
                            $output->writeln('<error>'.$e->getMessage().'</error>');
                        }
                    }
                    foreach ($mmobjs as $mmobj) {
                        $this->multimediaObjectEventDispatcherService->dispatchUpdate($mmobj);
                    }
                } else {
                    $mmobj = $this->getMmObjFromYid($yid);
                    if (!$mmobj) {
                        throw new \Exception('No mmobj from Youtube video with id '.$yid);
                    }
                    $output->writeln(' * Publishing multimedia object '.$mmobj->getId());
                    $this->tagService->addTagByCodToMultimediaObject($mmobj, 'PUCHWEBTV');
                    $this->multimediaObjectEventDispatcherService->dispatchUpdate($mmobj);
                }

                break;

            default:
                $output->writeln('<error>Select a valid step</error>');
        }
*/
        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        /* $this->tagRepo = $this->documentManager->getRepository(Tag::class);
         $this->mmobjRepo = $this->documentManager->getRepository(MultimediaObject::class);
         $this->seriesRepo = $this->documentManager->getRepository(Series::class);
         $this->youtubeRepo = $this->documentManager->getRepository(Youtube::class);*/
    }

    private function moveTracks(MultimediaObject $mmobj, $trackPath)
    {
        /*if ($this->profileService->getProfile('master_copy')) {
            $masterProfile = 'master_copy';
        } elseif ($this->profileService->getProfile('master-copy')) {
            $masterProfile = 'master-copy';
        } else {
            throw new \Exception('Error: No master_copy|master-copy profile');
        }

        if ($this->profileService->getProfile('video_h264')) {
            $videoH264Profile = 'video_h264';
        } elseif ($this->profileService->getProfile('broadcast-mp4')) {
            $videoH264Profile = 'broadcast-mp4';
        } else {
            throw new \Exception('Error: No video_h264|broadcast-mp4 profile');
        }

        if ($mmobj->getTrackWithTag('master')) {
            throw new \Exception('Object already has master track');
        }

        try {
            $this->jobService->createTrackWithFile($trackPath, $masterProfile, $mmobj);
        } catch (\Exception $e) {
            throw new \Exception('Error coping master file "'.$trackPath.'"');
        }*/
    }

    private function downloadPic(MultimediaObject $mmobj, $quality = null, $force = false)
    {
        /*$meta = $mmobj->getProperty('youtubemeta');

        if (!$quality) {
            $picUrl = $meta['snippet']['thumbnails']['standard']['url'] ??
                $meta['snippet']['thumbnails']['default']['url'];
        } else {
            if (!isset($meta['snippet']['thumbnails'][$quality]['url'])) {
                throw new \Exception('Object "'.$mmobj->getId().'" doesn\'t have image with "'.$quality.'" quality');
            }
            $picUrl = $meta['snippet']['thumbnails'][$quality]['url'];
        }

        if ($force) {
            $picIds = array_map(
                function ($p) {
                    return $p->getId();
                },
                $mmobj->getPics()->toArray()
            );
            foreach ($picIds as $picId) {
                $this->multimediaObjectPicService->removePicFromMultimediaObject($mmobj, $picId);
            }
        } else {
            if (0 != count($mmobj->getPics())) {
                throw new \Exception('Object "'.$mmobj->getId().'" already has pics');
            }
        }

        if (!$picUrl) {
            throw new \Exception('No pic for object with id '.$mmobj->getId());
        }

        $filePath = $this->multimediaObjectPicService->getTargetPath($mmobj);
        $fileName = basename(parse_url($picUrl, PHP_URL_PATH));

        $this->download($picUrl, $filePath, $fileName);

        $path = $filePath.'/'.$fileName;
        $pic = new Pic();
        $pic->setUrl(str_replace($filePath, $this->multimediaObjectPicService->getTargetUrl($mmobj), $path));
        $pic->setPath($path);
        $mmobj->addPic($pic);

        $this->documentManager->persist($mmobj);
        $this->documentManager->flush();*/
    }

    /**
     * @throws \Exception
     */
    private function tagMultimediaObject(MultimediaObject $mmobj, array $tagIds)
    {
        /*$tags = $this->tagRepo->findBy(
            [
                'properties.origin' => 'youtube',
                'properties.youtube' => ['$in' => $tagIds],
            ]
        );
        if (count($tagIds) != count($tags)) {
            throw new \Exception(
                sprintf(
                    'No all tags found with this Youtube ids, input has %d id(s) and only %d tag(s) found',
                    count($tagIds),
                    count($tags)
                )
            );
        }

        foreach ($tags as $tag) {
            $this->tagService->addTag($mmobj, $tag);
        }*/
    }

    private function createMultimediaObject(Series $series, $yid, $status, $login, OutputInterface $output)
    {
        /* try {
             $meta = $this->youtubeService->getVideoMeta($yid, $login);
         } catch (\Exception $e) {
             throw new \Exception('No Youtube video with id '.$yid);
         }

         $mmobj = $this->factoryService->createMultimediaObject($series, false);
         $mmobj->setStatus($status);
         $mmobj->setTitle($meta['out']['snippet']['title']);
         if (isset($meta['out']['snippet']['description'])) {
             $mmobj->setDescription($meta['out']['snippet']['description']);
         }
         if (isset($meta['out']['snippet']['tags'])) {
             $mmobj->setKeywords($meta['out']['snippet']['tags']);
         }
         $dataTime = \DateTime::createFromFormat('Y-m-d\TH:i:s', substr($meta['out']['snippet']['publishedAt'], 0, 19));
         $mmobj->setRecordDate($dataTime);
         $mmobj->setPublicDate($dataTime);
         $mmobj->setProperty('origin', 'youtube');
         $mmobj->setProperty('youtubemeta', $meta['out']);

         $this->documentManager->persist($mmobj);
         $this->documentManager->flush();

         return $mmobj;*/
    }

    private function getMmObjFromYid($yid)
    {
        /*$mmobj = $this->mmobjRepo->findOneBy(['properties.youtubemeta.id' => $yid]);
        if ($mmobj) {
            return $mmobj;
        }

        $yt = $this->youtubeRepo->find($yid);
        if (!$yt) {
            return null;
        }

        return $this->mmobjRepo->find($yt->getMultimediaObjectId());*/
    }

    private function getSeries($seriesId)
    {
        /*if (!$seriesId) {
            throw new \Exception('No series id argument');
        }

        $series = $this->seriesRepo->find($seriesId);
        if ($series) {
            $this->logger->info(sprintf('Using series with id %s', $seriesId));

            return $series;
        }

        $series = $this->seriesRepo->findOneBy(
            [
                'properties.origin' => 'youtube',
                'properties.fromyoutubetag' => $seriesId,
            ]
        );
        if ($series) {
            $this->logger->info(sprintf('Using series with YouTube property %s', $seriesId));

            return $series;
        }

        // tag with youtube
        $tag = $this->tagRepo->findOneBy(
            [
                'properties.origin' => 'youtube',
                'properties.youtube' => $seriesId,
            ]
        );
        if ($tag) {
            $this->logger->info(sprintf('Creating series from YouTube property %s', $seriesId));
            $series = $this->factoryService->createSeries();
            $series->setI18nTitle($tag->getI18nTitle());
            $series->setProperty('origin', 'youtube');
            $series->setProperty('fromyoutubetag', $seriesId);

            $this->documentManager->persist($series);
            $this->documentManager->flush();

            return $series;
        }

        throw new \Exception('No series, or YouTube tag with id '.$seriesId);*/
    }

    private function getStatus($status)
    {/*
        $status = strtolower($status);
        $validStatus = [
            'published',
            'pub',
            'block',
            'bloq',
            'blocked',
            'hide',
            'hidden',
        ];
        if (!in_array($status, $validStatus)) {
            throw new \Exception('Status "'.$status.'" not in '.implode(', ', $validStatus));
        }

        switch ($status) {
            case 'published':
            case 'pub':
                return MultimediaObject::STATUS_PUBLISHED;

            case 'bloq':
            case 'block':
            case 'blocked':
                return MultimediaObject::STATUS_BLOCKED;

            case 'hide':
            case 'hidden':
                return MultimediaObject::STATUS_HIDDEN;
        }

        return MultimediaObject::STATUS_PUBLISHED;*/
    }

    /**
     * @param string $url
     * @param string $directory
     * @param string $filename
     */
    private function download($url, $directory, $filename)
    {
        /*if (!is_dir($directory)) {
            if (false === @mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new FileException(sprintf('Unable to create the "%s" directory', $directory));
            }
        } elseif (!is_writable($directory)) {
            throw new FileException(sprintf('Unable to write in the "%s" directory', $directory));
        }

        $file_headers = @get_headers($url);
        if (strstr($file_headers[0], 'HTTP/1.1 404')) {
            throw new FileException(sprintf('Unable to download  "%s"', $url));
        }

        // $output = file_put_contents($directory.'/'.$filename, fopen(str_replace(' ', '%20', $url), 'r'));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // curl_setopt($ch, CURLOPT_SSLVERSION,3);
        $data = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new FileException(sprintf('Error downloading  "%s": %s', $url, $error));
        }

        $file = fopen($directory.'/'.$filename, 'w+');
        $output = fwrite($file, $data);
        $output2 = fclose($file);

        if (!$output || !$output2) {
            throw new FileException(sprintf('Error downloading  "%s"', $url));
        }*/
    }
}

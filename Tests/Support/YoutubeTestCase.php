<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Support;

use MongoDB\BSON\ObjectId;
use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\Material;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\VideoAudio;
use Pumukit\SchemaBundle\Document\MediaType\Storage;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Document\ValueObject\i18nText;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Document\ValueObject\StorageUrl;
use Pumukit\SchemaBundle\Document\ValueObject\Tags;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\Services\CaptionsListService;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

abstract class YoutubeTestCase extends PumukitTestCase
{
    protected const VIDEO_STREAMS = '{"format":{"duration":"10.000000"},"streams":[{"codec_type":"video","codec_name":"h264","width":640,"height":480}]}';
    protected const AUDIO_STREAMS = '{"format":{"duration":"10.000000"},"streams":[{"codec_type":"audio","codec_name":"aac"}]}';

    protected KernelBrowser $client;
    protected FakeYoutubeApi $api;
    protected Tag $youtubeTag;
    protected Tag $publicationChannel;
    protected Tag $account;

    private array $files = [];
    private int $numericalId = 0;

    public function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->dm = static::getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->clearBBDD();
        $this->dm->getDocumentCollection(Youtube::class)->deleteMany([]);

        $this->api = new FakeYoutubeApi();
        static::getContainer()->set(GoogleAccountService::class, new FakeGoogleAccountService($this->api));
        static::getContainer()->set(CaptionsListService::class, new FakeCaptionsListService($this->api));

        $root = $this->tag('ROOT');
        $publicationChannels = $this->tag('PUBCHANNELS', $root);
        $this->publicationChannel = $this->tag(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE, $publicationChannels);
        $this->youtubeTag = $this->tag(PumukitYoutubeBundle::YOUTUBE_TAG_CODE, $root);
        $this->account = $this->account('channel');
        $this->dm->flush();
    }

    public function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->dm->getDocumentCollection(Youtube::class)->deleteMany([]);
        parent::tearDown();
    }

    protected function tag(string $cod, ?Tag $parent = null): Tag
    {
        $tag = new Tag();
        $tag->setCod($cod);
        $tag->setTitle($cod);
        if ($parent) {
            $tag->setParent($parent);
        }
        $this->dm->persist($tag);
        $this->dm->flush();

        return $tag;
    }

    protected function account(string $login): Tag
    {
        $account = $this->tag('account_'.$login, $this->youtubeTag);
        $account->setProperty('login', $login);
        $account->setProperty('access_token', ['access_token' => 'token', 'created' => time(), 'expires_in' => 3600]);
        $this->dm->flush();

        return $account;
    }

    protected function playlist(string $cod, string $youtubeId, ?Tag $account = null): Tag
    {
        $playlist = $this->tag($cod, $account ?? $this->account);
        $playlist->setProperty('youtube', $youtubeId);
        $playlist->setProperty('youtube_playlist', true);
        $this->dm->flush();

        return $playlist;
    }

    protected function file(string $content = 'video', string $extension = 'mp4'): string
    {
        $file = sys_get_temp_dir().'/'.uniqid('youtube', true).'.'.$extension;
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }

    protected function sparseFile(int $bytes): string
    {
        $file = $this->file('');
        $handle = fopen($file, 'c');
        ftruncate($handle, $bytes);
        fclose($handle);

        return $file;
    }

    protected function track(?string $file = null, array $tags = ['master'], string $metadata = self::VIDEO_STREAMS): Track
    {
        $file ??= $this->file();

        return Track::create(
            basename($file),
            i18nText::create(['en' => '']),
            'en',
            Tags::create($tags),
            false,
            true,
            0,
            Storage::create(StorageUrl::create(''), Path::create($file)),
            VideoAudio::create($metadata)
        );
    }

    protected function video(array $tags = [], ?Track $track = null, int $status = MultimediaObject::STATUS_PUBLISHED, string $broadcast = EmbeddedBroadcast::TYPE_PUBLIC): MultimediaObject
    {
        ++$this->numericalId;
        $series = new Series();
        $series->setNumericalID($this->numericalId);
        $series->setTitle('Series');
        $this->dm->persist($series);

        $embeddedBroadcast = new EmbeddedBroadcast();
        $embeddedBroadcast->setType($broadcast);

        $multimediaObject = new MultimediaObject();
        $multimediaObject->setNumericalID($this->numericalId);
        $multimediaObject->setStatus($status);
        $multimediaObject->setType(MultimediaObject::TYPE_VIDEO);
        $multimediaObject->setTitle('Video '.$this->numericalId);
        $multimediaObject->setDescription('Description');
        $multimediaObject->setSeries($series);
        $multimediaObject->setEmbeddedBroadcast($embeddedBroadcast);
        $multimediaObject->addTrack($track ?? $this->track());
        foreach ($tags as $tag) {
            $multimediaObject->addTag($tag);
        }
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        return $multimediaObject;
    }

    protected function videoForYoutube(?Track $track = null): MultimediaObject
    {
        return $this->video([$this->publicationChannel, $this->youtubeTag, $this->account], $track);
    }

    protected function youtubeDocument(MultimediaObject $multimediaObject, int $status = Youtube::STATUS_PUBLISHED, ?string $youtubeId = 'ytid', ?int $ageInSeconds = null): Youtube
    {
        $youtube = new Youtube();
        if (null !== $ageInSeconds) {
            $id = new \ReflectionProperty(Youtube::class, 'id');
            $id->setValue($youtube, (string) new ObjectId(sprintf('%08x', time() - $ageInSeconds).substr((string) new ObjectId(), 8)));
        }
        $youtube->setMultimediaObjectId($multimediaObject->getId());
        $youtube->setYoutubeAccount($this->account->getProperty('login'));
        $youtube->setStatus($status);
        if (null !== $youtubeId) {
            $youtube->setYoutubeId($youtubeId);
            $youtube->setLink('https://www.youtube.com/watch?v='.$youtubeId);
        }
        $this->dm->persist($youtube);
        $this->dm->flush();

        $multimediaObject->setProperty('youtube', (string) $youtube->getId());
        if (null !== $youtubeId) {
            $multimediaObject->setProperty('youtubeurl', $youtube->getLink());
        }
        $this->dm->flush();

        return $youtube;
    }

    protected function material(MultimediaObject $multimediaObject, string $language = 'es', string $mimeType = 'vtt'): Material
    {
        $material = new Material();
        $material->setPath($this->file('WEBVTT', 'vtt'));
        $material->setMimeType($mimeType);
        $material->setLanguage($language);
        $material->setName('Caption '.$language);
        $multimediaObject->addMaterial($material);
        $this->dm->flush();

        return $material;
    }

    protected function user(array $roles, string $username = 'user'): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username.'@pumukit.test');
        $user->setRoles($roles);
        $this->dm->persist($user);
        $this->dm->flush();

        return $user;
    }

    protected function runCommand(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application($this->client->getKernel()))->find($name));
        $tester->execute($input);

        return $tester;
    }

    protected function reload(string $class, string $id): object
    {
        $this->dm->clear();

        return $this->dm->getRepository($class)->find($id);
    }

    protected function youtubeOf(MultimediaObject $multimediaObject): ?Youtube
    {
        $this->dm->clear();

        return $this->dm->getRepository(Youtube::class)->findOneBy(['multimediaObjectId' => $multimediaObject->getId()]);
    }

    protected function youtubeDocuments(): array
    {
        $this->dm->clear();

        return $this->dm->getRepository(Youtube::class)->findAll();
    }
}

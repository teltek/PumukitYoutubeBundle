<?php

namespace Pumukit\YoutubeBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * Pumukit\YoutubeBundle\Document\Youtube.
 *
 * @MongoDB\Document(repositoryClass="Pumukit\YoutubeBundle\Repository\YoutubeAccountRepository")
 */
class YoutubeAccount
{
    /**
     * @var string
     *
     * @MongoDB\Id
     */
    private $id;

    /**
     * @var string
     *
     * @MongoDB\String
     */
    private $name;

    /**
     * @var string
     *
     * @MongoDB\String
     */
    private $login;

    /**
     * @var string
     *
     * @MongoDB\Raw
     */
    private $playlist;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->playlists = array();
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getLogin()
    {
        return $this->login;
    }

    /**
     * @param string $login
     */
    public function setLogin($login)
    {
        $this->login = $login;
    }

    /**
     * @return string
     */
    public function getPlaylist()
    {
        return $this->playlist;
    }

    /**
     * @param string $playlist
     *
     * @return array
     */
    public function addPlaylist($playlist)
    {
        $this->playlist[] = $playlist;

        return $this->playlist = array_unique($this->playlist);
    }
}

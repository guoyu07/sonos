<?php

namespace duncan3dc\Sonos;

use duncan3dc\DomParser\XmlParser;
use duncan3dc\Sonos\Services\Radio;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides methods to locate speakers/controllers/playlists on the current network.
 */
class Network implements LoggerAwareInterface
{
    /**
     * @var DeviceCollection $collection The collection of devices on the network.
     */
    protected $collection;

    /**
     * @var Playlists[]|null $playlists Playlists that are available on the current network.
     */
    protected $playlists;

    /**
     * @var Alarm[]|null $alarms Alarms that are available on the current network.
     */
    protected $alarms;

    /**
     * Create a new instance.
     *
     * @param DeviceCollection $collection The collection of devices on this network
     */
    public function __construct(DeviceCollection $collection = null)
    {
        if ($collection === null) {
            $collection = new DeviceCollection;
        }
        $this->collection = $collection;
    }


    /**
     * Set the logger object to use.
     *
     * @var LoggerInterface $logger The logging object
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->collection->setLogger($logger);

        return $this;
    }


    /**
     * Get the logger object to use.
     *
     * @return LoggerInterface $logger The logging object
     */
    public function getLogger()
    {
        return $this->collection->getLogger();
    }


    /**
     * Get all the speakers on the network.
     *
     * @return Speaker[]
     */
    public function getSpeakers(): array
    {
        return $this->collection->getSpeakers();
    }


    /**
     * Reset any previously gathered speaker information.
     *
     * @return self
     */
    public function clearTopology(): self
    {
        $this->collection->clearTopology();

        return $this;
    }


    /**
     * Get a Controller instance from the network.
     *
     * Useful for managing playlists/alarms, as these need a controller but it doesn't matter which one.
     *
     * @return Controller|null
     */
    public function getController(): Controller
    {
        $controllers = $this->getControllers();
        if ($controller = reset($controllers)) {
            return $controller;
        }
    }


    /**
     * Get a speaker with the specified room name.
     *
     * @param string $room The name of the room to look for
     *
     * @return Speaker|null
     */
    public function getSpeakerByRoom(string $room): Speaker
    {
        $speakers = $this->getSpeakers();
        foreach ($speakers as $speaker) {
            if ($speaker->room === $room) {
                return $speaker;
            }
        }
    }


    /**
     * Get all the speakers with the specified room name.
     *
     * @param string $room The name of the room to look for
     *
     * @return Speaker[]
     */
    public function getSpeakersByRoom(string $room): array
    {
        $return = [];

        $speakers = $this->getSpeakers();
        foreach ($speakers as $controller) {
            if ($controller->room === $room) {
                $return[] = $controller;
            }
        }

        return $return;
    }


    /**
     * Get all the coordinators on the network.
     *
     * @return Controller[]
     */
    public function getControllers(): array
    {
        $controllers = [];

        $speakers = $this->getSpeakers();
        foreach ($speakers as $speaker) {
            if (!$speaker->isCoordinator()) {
                continue;
            }
            $controllers[$speaker->ip] = new Controller($speaker, $this);
        }

        return $controllers;
    }


    /**
     * Get the coordinator for the specified room name.
     *
     * @param string $room The name of the room to look for
     *
     * @return Controller|null
     */
    public function getControllerByRoom(string $room): Controller
    {
        if (!$speaker = $this->getSpeakerByRoom($room)) {
            return;
        }

        $group = $speaker->getGroup();

        $controllers = $this->getControllers();
        foreach ($controllers as $controller) {
            if ($controller->getGroup() === $group) {
                return $controller;
            }
        }
    }


    /**
     * Get the coordinator for the specified ip address.
     *
     * @param string $ip The ip address of the speaker
     *
     * @return Controller|null
     */
    public function getControllerByIp(string $ip): Controller
    {
        $speakers = $this->getSpeakers();
        if (!array_key_exists($ip, $speakers)) {
            throw new \InvalidArgumentException("No speaker found for the IP address '{$ip}'");
        }

        $group = $speakers[$ip]->getGroup();

        foreach ($this->getControllers() as $controller) {
            if ($controller->getGroup() === $group) {
                return $controller;
            }
        }
    }


    /**
     * Get all the playlists available on the network.
     *
     * @return Playlist[]
     */
    public function getPlaylists(): array
    {
        if (is_array($this->playlists)) {
            return $this->playlists;
        }

        $controller = $this->getController();
        if ($controller === null) {
            throw new \RuntimeException("No controller found on the current network");
        }

        $data = $controller->soap("ContentDirectory", "Browse", [
            "ObjectID"          =>  "SQ:",
            "BrowseFlag"        =>  "BrowseDirectChildren",
            "Filter"            =>  "",
            "StartingIndex"     =>  0,
            "RequestedCount"    =>  100,
            "SortCriteria"      =>  "",
        ]);
        $parser = new XmlParser($data["Result"]);

        $playlists = [];
        foreach ($parser->getTags("container") as $container) {
            $playlists[] = new Playlist($container, $controller);
        }

        return $this->playlists = $playlists;
    }


    /**
     * Check if a playlist with the specified name exists on this network.
     *
     * If no case-sensitive match is found it will return a case-insensitive match.
     *
     * @param string The name of the playlist
     *
     * @return bool
     */
    public function hasPlaylist(string $name): bool
    {
        $playlists = $this->getPlaylists();
        foreach ($playlists as $playlist) {
            if ($playlist->getName() === $name) {
                return true;
            }
            if (strtolower($playlist->getName()) === strtolower($name)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Get the playlist with the specified name.
     *
     * If no case-sensitive match is found it will return a case-insensitive match.
     *
     * @param string The name of the playlist
     *
     * @return Playlist|null
     */
    public function getPlaylistByName(string $name): Playlist
    {
        $roughMatch = false;

        $playlists = $this->getPlaylists();
        foreach ($playlists as $playlist) {
            if ($playlist->getName() === $name) {
                return $playlist;
            }
            if (strtolower($playlist->getName()) === strtolower($name)) {
                $roughMatch = $playlist;
            }
        }

        if ($roughMatch) {
            return $roughMatch;
        }
    }


    /**
     * Get the playlist with the specified id.
     *
     * @param int The ID of the playlist
     *
     * @return Playlist
     */
    public function getPlaylistById(int $id): Playlist
    {
        $controller = $this->getController();
        if ($controller === null) {
            throw new \RuntimeException("No controller found on the current network");
        }

        return new Playlist($id, $controller);
    }


    /**
     * Create a new playlist.
     *
     * @param string The name to give to the playlist
     *
     * @return Playlist
     */
    public function createPlaylist(string $name): Playlist
    {
        $controller = $this->getController();
        if ($controller === null) {
            throw new \RuntimeException("No controller found on the current network");
        }

        $data = $controller->soap("AVTransport", "CreateSavedQueue", [
            "Title"                 =>  $name,
            "EnqueuedURI"           =>  "",
            "EnqueuedURIMetaData"   =>  "",
        ]);

        $playlist = new Playlist($data["AssignedObjectID"], $controller);

        $this->playlists[] = $playlist;

        return $playlist;
    }


    /**
     * Get all the alarms available on the network.
     *
     * @return Alarm[]
     */
    public function getAlarms(): array
    {
        if (is_array($this->alarms)) {
            return $this->alarms;
        }

        $data = $this->getController()->soap("AlarmClock", "ListAlarms");
        $parser = new XmlParser($data["CurrentAlarmList"]);

        $alarms = [];
        foreach ($parser->getTags("Alarm") as $tag) {
            $alarms[] = new Alarm($tag, $this);
        }

        return $this->alarms = $alarms;
    }


    /**
     * Get the alarm from the specified id.
     *
     * @param int $id The ID of the alarm
     *
     * @return Alarm|null
     */
    public function getAlarmById(int $id): Alarm
    {
        $id = (int) $id;

        $alarms = $this->getAlarms();
        foreach ($alarms as $alarm) {
            if ($alarm->getId() === $id) {
                return $alarm;
            }
        }
    }


    /**
     * Get a Radio instance for the network.
     *
     * @return Radio
     */
    public function getRadio(): Radio
    {
        $controller = $this->getController();
        return new Radio($controller);
    }
}

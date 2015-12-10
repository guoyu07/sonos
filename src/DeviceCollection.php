<?php

namespace duncan3dc\Sonos;

use Psr\Cache\CacheItemPoolInterface as CacheInterface;
use duncan3dc\DomParser\XmlParser;
use GuzzleHttp\Client;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manage a group of devices.
 */
class DeviceCollection implements LoggerAwareInterface
{
    const CACHE_KEY = "device-ip-addresses-1.8.0";

    protected $addresses = [];

    /**
     * @var Speaker[]|null $speakers Speakers that are available on the current network.
     */
    protected $speakers;

    /**
     * @var CacheInterface $cache The long-lived cache object.
     */
    protected $cache;

    /**
     * @var LoggerInterface $logger The logging object.
     */
    protected $logger;


    /**
     * Create an instance of the DeviceCollection class.
     *
     * @param CacheInterface $cache The cache object to use for the expensive multicast discover to find Sonos devices on the network
     * @param LoggerInterface $logger A logging object
     */
    public function __construct(CacheInterface $cache = null, LoggerInterface $logger = null)
    {
        if ($cache === null) {
            $cache = new Cache;
        }
        $this->cache = $cache;

        if ($logger === null) {
            $logger = new NullLogger;
        }
        $this->logger = $logger;
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
        $this->logger = $logger;

        return $this;
    }


    /**
     * Get the logger object to use.
     *
     * @return LoggerInterface $logger The logging object
     */
    public function getLogger()
    {
        return $this->logger;
    }


    public function getDevices(): array
    {
        if (count($this->addresses) < 1) {
            if ($this->cache->hasItem(self::CACHE_KEY)) {
                $this->logger->info("getting device info from cache");
                $this->addresses = $this->cache->getItem(self::CACHE_KEY)->get();
            } else {
                $this->discoverDevices();
            }
        }

        $devices = [];
        foreach ($this->addresses as $ip) {
            $devices[] = new Device($ip, $this->cache, $this->logger);
        }

        return $devices;
    }


    /**
     * Get all the devices on the current network.
     *
     * @return void
     */
    public function discoverDevices(string $address = "239.255.255.250")
    {
        $this->logger->info("discovering devices...");

        $port = 1900;

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($sock, getprotobyname("ip"), IP_MULTICAST_TTL, 2);

        $data = "M-SEARCH * HTTP/1.1\r\n";
        $data .= "HOST: {$address}:reservedSSDPport\r\n";
        $data .= "MAN: ssdp:discover\r\n";
        $data .= "MX: 1\r\n";
        $data .= "ST: urn:schemas-upnp-org:device:ZonePlayer:1\r\n";

        $this->logger->debug($data);

        socket_sendto($sock, $data, strlen($data), null, $address, $port);

        $read = [$sock];
        $write = [];
        $except = [];
        $name = null;
        $port = null;
        $tmp = "";

        $response = "";
        while (socket_select($read, $write, $except, 1)) {
            socket_recvfrom($sock, $tmp, 2048, null, $name, $port);
            $response .= $tmp;
        }

        $this->logger->debug($response);

        $devices = [];
        foreach (explode("\r\n\r\n", $response) as $reply) {
            if (!$reply) {
                continue;
            }

            $data = [];
            foreach (explode("\r\n", $reply) as $line) {
                if (!$pos = strpos($line, ":")) {
                    continue;
                }
                $key = strtolower(substr($line, 0, $pos));
                $val = trim(substr($line, $pos + 1));
                $data[$key] = $val;
            }
            $devices[] = $data;
        }

        $unique = [];
        foreach ($devices as $device) {
            if ($device["st"] !== "urn:schemas-upnp-org:device:ZonePlayer:1") {
                continue;
            }
            if (in_array($device["usn"], $unique)) {
                continue;
            }
            $this->logger->info("found device: {usn}", $device);

            $unique[] = $device["usn"];

            $url = parse_url($device["location"]);
            $this->addIp($url["host"]);
        }

        return $this;
    }


    public function addIp(string $ip): self
    {
        if (!in_array($ip, $this->addresses, true)) {
            $this->addresses[] = $ip;

            $cacheItem = $this->cache->getItem(self::CACHE_KEY);
            $cacheItem->set($this->addresses);
            $this->cache->save($cacheItem);
        }

        return $this;
    }


    public function clear(): self
    {
        $this->addresses = [];

        return $this;
    }


    /**
     * Get all the speakers for these devices.
     *
     * @return Speaker[]
     */
    public function getSpeakers(): array
    {
        if (is_array($this->speakers)) {
            return $this->speakers;
        }

        $devices = $this->getDevices();
        if (count($devices) < 1) {
            throw new \RuntimeException("No devices found on the current network");
        }

        $this->logger->info("creating speaker instances");

        # Get the topology information from 1 speaker
        $topology = [];
        $ip = reset($devices)->ip;
        $uri = "http://{$ip}:1400/status/topology";
        $this->logger->notice("Getting topology info from: {$uri}");
        $xml = (string) (new Client)->get($uri)->getBody();
        $players = (new XmlParser($xml))->getTag("ZonePlayers")->getTags("ZonePlayer");
        foreach ($players as $player) {
            $attributes = $player->getAttributes();
            $ip = parse_url($attributes["location"])["host"];
            $topology[$ip] = $attributes;
        }

        $this->speakers = [];
        foreach ($devices as $device) {
            if (!$device->isSpeaker()) {
                continue;
            }

            $speaker = new Speaker($device);

            if (!isset($topology[$device->ip])) {
                throw new \RuntimeException("Failed to lookup the topology info for this speaker");
            }

            $speaker->setTopology($topology[$device->ip]);

            $this->speakers[$device->ip] = $speaker;
        }

        return $this->speakers;
    }


    /**
     * Reset any previously gathered speaker information.
     *
     * @return self
     */
    public function clearTopology(): self
    {
        $this->speakers = null;

        return $this;
    }
}

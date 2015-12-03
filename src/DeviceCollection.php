<?php

namespace duncan3dc\Sonos;

use Doctrine\Common\Cache\Cache as CacheInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manage a group of devices.
 */
class DeviceCollection
{
    const CACHE_KEY = "device-ip-addresses-1.8.0";

    protected $addresses = [];

    /**
     * @var CacheInterface $cache The long-lived cache object from the Network instance.
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


    public function getDevices(): array
    {
        if (count($this->addresses) < 1) {
            if ($this->cache->contains(self::CACHE_KEY)) {
                $this->logger->info("getting device info from cache");
                $this->addresses = $this->cache->fetch(self::CACHE_KEY);
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
            $this->cache->save(self::CACHE_KEY, $this->addresses);
        }

        return $this;
    }


    public function clear(): self
    {
        $this->addresses = [];

        return $this;
    }
}

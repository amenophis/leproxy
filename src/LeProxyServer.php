<?php

namespace LeProxy\LeProxy;

use Clue\React\Socks\Server as SocksServer;
use React\EventLoop\LoopInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\Server as Socket;
use InvalidArgumentException;
use React\Socket\ConnectionInterface;

/**
 * Integrates HTTP and SOCKS proxy servers into a single server instance
 */
class LeProxyServer
{
    private $connector;
    private $loop;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null)
    {
        if ($connector === null) {
            $connector = new Connector($loop);
        }

        $this->connector = $connector;
        $this->loop = $loop;
    }

    /**
     * @param string $listen
     * @param bool   $allowUnprotected
     * @return \React\Socket\ServerInterface
     * @throws \InvalidArgumentException
     */
    public function listen($listen, $allowUnprotected)
    {
        if (preg_match('/^(([^:]*):([^@]*)@)?(.?.?\/.*)$/', $listen, $parts)) {
            // match Unix domain sockets (UDS) paths like "[user:pass@]/path"
            $socket = new Socket('unix://' . $parts[4], $this->loop);
            $parts = isset($parts[1]) && $parts[1] !== '' ? array('user' => $parts[2], 'pass' => $parts[3]) : array();
        } else {
            // parse "[user:pass@]host[:port]" with optional auth and port

            // null port means random port assignment and needs to be parsed separately
            $nullport = false;
            if (substr($listen, -2) === ':0') {
                $nullport = true;
                $listen = substr($listen, 0, -2) . ':10000';
            }

            $parts = parse_url('http://' . $listen);
            if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port'])) {
                throw new InvalidArgumentException('Invalid URI for listening address');
            }

            if ($nullport) {
                $parts['port'] = 0;
            }

            $socket = new Socket($parts['host'] . ':' . $parts['port'], $this->loop);
        }

        // start new proxy server which uses the given connector for forwarding/chaining
        $unification = new ProtocolDetector($socket);
        $http = new HttpProxyServer($this->loop, $unification->http, $this->connector);
        $socks = new SocksServer($this->loop, $unification->socks, new SocksErrorConnector($this->connector));

        // require authentication if listening URI contains username/password
        if (isset($parts['user']) || isset($parts['pass'])) {
            $auth = array(
                rawurldecode($parts['user']) => isset($parts['pass']) ? rawurldecode($parts['pass']) : ''
            );

            $http->setAuthArray($auth);
            $socks->setAuthArray($auth);
        } elseif (!$allowUnprotected) {
            // no authentication required, so only allow local requests (protected mode)
            $http->allowUnprotected = false;

            // SOCKS works by setting authentication on a per-connection basis
            $socks->on('connection', function (ConnectionInterface $conn) use ($socks) {
                $remote = parse_url($conn->getRemoteAddress(), PHP_URL_HOST);
                if ($remote === null || ConnectorFactory::isIpLocal(trim($remote, '[]'))) {
                    // do not require authentication for local requests
                    $socks->unsetAuth();
                } else {
                    // enforce authentication, but always fail for non-local requests
                    // this implies that SOCKS4 will be rejected right away
                    $socks->setAuth(function () {
                        return false;
                    });
                }
            });
        }

        return $socket;
    }
}

<?php
/**
 * League.Uri (http://uri.thephpleague.com)
 *
 * @package    League.uri
 * @subpackage League\Uri\Modifiers
 * @author     Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @copyright  2017 Ignace Nyamagana Butera
 * @license    https://github.com/thephpleague/uri-manipulations/blob/master/LICENSE (MIT License)
 * @version    1.1.0
 * @link       https://github.com/thephpleague/uri-manipulations
 */
declare(strict_types=1);

namespace League\Uri;

use Exception as PhpException;
use League\Uri\Interfaces\Uri as LeagueUriInterface;
use Psr\Http\Message\UriInterface;
use ReflectionClass;

class Factory
{
    /**
     * Supported schemes
     *
     * @var string[]
     */
    protected $map = [
        'http' => Http::class,
        'https' => Http::class,
        'ftp' => Ftp::class,
        'ws' => Ws::class,
        'wss' => Ws::class,
        'data' => Data::class,
        'file' => File::class,
    ];

    /**
     * Dot segments
     *
     * @var array
     */
    protected static $dot_segments = ['.' => 1, '..' => 1];

    /**
     * supported URI interfaces
     *
     * @var array
     */
    protected static $uri_interfaces = [
        LeagueUriInterface::class,
        UriInterface::class,
    ];

    /**
     * new instance
     *
     * @param array $map An override map of URI classes indexed by their supported schemes.
     */
    public function __construct($map = [])
    {
        foreach ($map as $scheme => $className) {
            $this->addMap(strtolower($scheme), $className);
        }
    }

    /**
     * Add a new classname for a given scheme URI
     *
     * @param string $scheme    valid URI scheme
     * @param string $className classname which implements LeagueUriInterface or UriInterface
     *
     * @throws Exception if the scheme is invalid
     * @throws Exception if the class does not implements a supported interface
     */
    protected function addMap(string $scheme, string $className)
    {
        if (!is_scheme($scheme)) {
            throw new Exception(sprintf('Please verify the submitted scheme `%s`', $scheme));
        }

        if (empty(array_intersect((new ReflectionClass($className))->getInterfaceNames(), self::$uri_interfaces))) {
            throw new Exception(sprintf('Please verify the submitted class `%s`', $className));
        }

        $this->map[$scheme] = $className;
    }

    /**
     * Create a new URI optionally according to
     * a base URI object
     *
     * The base URI can be
     * <ul>
     * <li>UriInterface
     * <li>LeagueUriInterface
     * <li>a string
     * </ul>
     *
     * @param string $uri
     * @param mixed  $base_uri
     *
     * @throws Exception if the base_uri is not absolute
     * @throws Exception if the uri is really malformed or an invalid URI
     *
     * @return LeagueUriInterface|UriInterface
     */
    public function create(string $uri, $base_uri = null)
    {
        $components = parse($uri);

        if (null === $base_uri) {
            $className = $this->map[strtolower($components['scheme'] ?? '')] ?? Uri::class;
            return $this->newInstance($components, $className);
        }

        if (!$base_uri instanceof UriInterface && !$base_uri instanceof LeagueUriInterface) {
            $base_uri = $this->create($base_uri);
        }

        if ('' === $base_uri->getScheme()) {
            throw new Exception(sprintf('The submitted base uri %s must be an absolute URI', $base_uri));
        }

        try {
            $uri = $this->newInstance($components, get_class($base_uri));

            return $this->resolve($uri, $base_uri);
        } catch (PhpException $e) {
            $className = $this->map[strtolower($components['scheme'] ?? '')] ?? Uri::class;
            $uri = $this->newInstance($components, $className);

            return $this->resolve($uri, $base_uri);
        }
    }

    /**
     * create a new URI object from its name using Reflection
     *
     * @param array  $components
     * @param string $className
     *
     * @return LeagueUriInterface|UriInterface
     */
    protected function newInstance(array $components, string $className)
    {
        return (new ReflectionClass($className))
            ->newInstanceWithoutConstructor()
            ->withHost($components['host'] ?? '')
            ->withPort($components['port'] ?? null)
            ->withUserInfo($components['user'] ?? '', $components['pass'] ?? null)
            ->withScheme($components['scheme'] ?? '')
            ->withPath($components['path'] ?? '')
            ->withQuery($components['query'] ?? '')
            ->withFragment($components['fragment'] ?? '')
        ;
    }

    /**
     * Resolve an URI against a base URI
     *
     * @param LeagueUriInterface|UriInterface $uri
     * @param LeagueUriInterface|UriInterface $base_uri
     *
     * @return LeagueUriInterface|UriInterface
     */
    protected function resolve($uri, $base_uri)
    {
        if ('' !== $uri->getScheme()) {
            return $uri
                ->withPath($this->removeDotSegments($uri->getPath()));
        }

        if ('' !== $uri->getAuthority()) {
            return $uri
                ->withScheme($base_uri->getScheme())
                ->withPath($this->removeDotSegments($uri->getPath()));
        }

        list($base_uri_user, $base_uri_pass) = explode(':', $base_uri->getUserInfo(), 2) + ['', null];
        list($uri_path, $uri_query) = $this->resolvePathAndQuery($uri, $base_uri);

        return $uri
            ->withPath($this->removeDotSegments($uri_path))
            ->withQuery($uri_query)
            ->withHost($base_uri->getHost())
            ->withPort($base_uri->getPort())
            ->withUserInfo($base_uri_user, $base_uri_pass)
            ->withScheme($base_uri->getScheme())
        ;
    }

    /**
     * Remove dot segments from the URI path
     *
     * @internal used internally to create an URI object
     *
     * @param string $path
     *
     * @return string
     */
    protected function removeDotSegments(string $path): string
    {
        if (false === strpos($path, '.')) {
            return $path;
        }

        $old_segments = explode('/', $path);
        $new_path = implode('/', array_reduce($old_segments, [$this, 'reducer'], []));
        if (isset(self::$dot_segments[end($old_segments)])) {
            $new_path .= '/';
        }

        return $new_path;
    }

    /**
     * Remove dot segments
     *
     * @param array  $carry
     * @param string $segment
     *
     * @return array
     */
    protected function reducer(array $carry, string $segment)
    {
        if ('..' === $segment) {
            array_pop($carry);

            return $carry;
        }

        if (!isset(self::$dot_segments[$segment])) {
            $carry[] = $segment;
        }

        return $carry;
    }

    /**
     * Resolve an URI path and query component
     *
     * @internal used internally to create an URI object
     *
     * @param LeagueUriInterface|UriInterface $uri
     * @param LeagueUriInterface|UriInterface $base_uri
     *
     * @return string[]
     */
    protected function resolvePathAndQuery($uri, $base_uri)
    {
        $target_path = $uri->getPath();
        $target_query = $uri->getQuery();

        if (0 === strpos($target_path, '/')) {
            return [$target_path, $target_query];
        }

        if ('' === $target_path) {
            $target_path = $base_uri->getPath();
            //because some PSR-7 Uri implementations allow this RFC3986 forbidden construction
            //@codeCoverageIgnoreStart
            if ('' !== $base_uri->getAuthority() && 0 !== strpos($target_path, '/')) {
                $target_path = '/'.$target_path;
            }
            //codeCoverageIgnoreEnd

            if ('' === $target_query) {
                $target_query = $base_uri->getQuery();
            }

            return [$target_path, $target_query];
        }

        $base_path = $base_uri->getPath();
        if ('' !== $base_uri->getAuthority() && '' === $base_path) {
            $target_path = '/'.$target_path;
        }

        if ('' !== $base_path) {
            $segments = explode('/', $base_path);
            array_pop($segments);
            $target_path = implode('/', $segments).'/'.$target_path;
        }

        return [$target_path, $target_query];
    }
}

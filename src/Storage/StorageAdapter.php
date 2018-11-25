<?php

namespace Liara\SDK\Storage;

use Exception;
use League\Flysystem\Util;
use League\Flysystem\Config;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Client as HTTPClient;
use League\Flysystem\AdapterInterface;
use GuzzleHttp\Exception\ClientException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

class Adapter extends AbstractAdapter implements CanOverwriteFiles
{
    use NotSupportingVisibilityTrait;

    /**
     * @var string
     */
    protected $baseURL;

    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var HTTPClient
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;

        $url = 'https://api.liara.ir';
        if( ! empty($options['url'])) {
            $url = $options['url'];
        }

        if(empty($options['secret'])) {
            throw new Exception('secret key is required.');
        }

        if(empty($options['namespace'])) {
            throw new Exception('namespace is required.');
        }

        $this->baseURL = $url;

        $this->namespace = $options['namespace'];

        $this->client = new HTTPClient([
            'base_uri' => $url,
            'headers' => [
                'User-Agent' => 'LiaraLaravelSDK/0.1.0',
                'Authorization' => 'Bearer ' . $options['secret'],
            ],
        ]);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config) {
        return $this->upload($path, $contents, $config);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config) {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config) {
        return $this->upload($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config) {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath) {
        if ( ! $this->copy($path, $newpath)) {
            return false;
        }
        return $this->delete($path);
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath) {
        try {
            $response = $this->client->request('POST', '/v1/objects/copy', [
                'json' => [
                    'key' => trim($path, '/'),
                    'newKey' => trim($newpath, '/'),
                ]
            ]);

            return true;

        } catch (ClientException $e) {
            return false;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path) {
        $url = '/v1/objects/' . trim($path, '/');

        try {
            $response = $this->client->request('DELETE', $url);

            return true;

        } catch (ClientException $e) {
            return false;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname) {
        $url = '/v1/objects/' . trim($dirname, '/') . '/';

        try {
            $response = $this->client->request('DELETE', $url);

            return true;

        } catch (ClientException $e) {
            return false;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config) {
        return $this->upload(rtrim($dirname) . '/', '', $config);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return bool
     */
    public function has($path)
    {
        $has = $this->getMetadata($path);

        if($has === false) {
            return false;
        }

        return true;
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $url = '/v1/storage/' . ltrim($path, '/');

        try {
            $response = $this->client->request('GET', $url);

            $resource = StreamWrapper::getResource($response->getBody());
    
            return [
                'stream' => $resource,
            ];

        } catch (ClientException $e) {
            return false;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function read($path)
    {
        $url = '/v1/storage/' . ltrim($path, '/');

        try {
            $response = $this->client->request('GET', $url);

            return [
                'contents' => $response->getBody()->getContents(),
            ];

        } catch (ClientException $e) {
            return false;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $prefix = rtrim($directory, '/') . '/';
        $options = [
            'prefix' => ltrim($prefix, '/'),
        ];

        if ($recursive === false) {
            $options['delimiter'] = '/';
        }

        // TODO:
        // $marker = null;
        // $listing = [];
        // while (true) {
        //     $objectList = $this->objectList([
        //         'prefix' => $location,
        //         'marker' => $marker,
        //     ]);

        //     $listing = array_merge($listing, $objectList['objects']);
        //     $marker = $objectList['nextMarker'];

        //     if ($objectList['isTruncated'] === false) {
        //         break;
        //     }
        // }

        $objects = array_map(function ($object) {
            return [
                'type' => 'file',
                'dirname' => Util::dirname($object->key),
                'path' => rtrim($object->key, '/'),
                'timestamp' => strtotime($object->lastModified),
                'size' => (int) $object->size
            ];
        }, $this->objectList($options)->objects);

        $dirs = array_map(function ($dir) {
            return [
                'type' => 'dir',
                'dirname' => Util::dirname($dir),
                'path' => rtrim($dir, '/'),
                'size' => 0
            ];
        }, $this->objectList($options)->commonPrefixes);

        $listing = array_merge($objects, $dirs);

        return Util::emulateDirectories($listing);
    }

    protected function objectList($options)
    {
        $response = $this->client->request('GET', '/v1/objects/list', [
            'query' => $options,
        ]);

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getMetadata($path)
    {
        $url = '/v1/objects/metadata/' . ltrim($path, '/');

        try {
            $response = $this->client->request('GET', $url);

            $body = json_decode($response->getBody()->getContents(), true);
            if( ! empty($body['metadata']['lastModified'])) {
                $body['metadata']['timestamp'] = strtotime($body['metadata']['lastModified']);
            }

            return $body['metadata'];

        } catch (ClientException $e) {
            return false;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    // TODO:
    // allFiles, allDirectories and setVisibility

    /**
     * Get file URL
     * 
     * @param string $path
     * 
     * @return string
     */
    public function getUrl($path) {
        return $this->baseURL . '/v1/storage/' . $this->namespace . '/' . ltrim($path, '/');
    }

    /**
     * Upload an object.
     *
     * @param        $path
     * @param        $body
     * @param Config $config
     *
     * @return array
     */
    protected function upload($path, $body, Config $config)
    {
        $size = is_string($body) ? Util::contentSize($body) : Util::getStreamSize($body);

        $visibility = $config->get('visibility') === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private';

        try {
            $response = $this->client->request('POST', '/v1/objects', [
                'body' => $body,
                'headers' => [
                    'X-Liara-Object-Size' => $size,
                    'X-Liara-Object-Key' => $path,
                    'X-Liara-Object-ACL' => $visibility,
                ]
            ]);

            return true;

        } catch (ClientException $e) {
            return false;

        } catch (Exception $e) {
            throw $e;
        }
    }
}

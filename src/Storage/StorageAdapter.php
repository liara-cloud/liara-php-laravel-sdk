<?php

namespace Liara\Storage;

use League\Flysystem\Util;
use League\Flysystem\Config;
use GuzzleHttp\Client as HTTPClient;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

class Adapter extends AbstractAdapter implements CanOverwriteFiles
{
    use NotSupportingVisibilityTrait;

    const API_URL = 'http://localhost:3000';

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

        $this->client = new HTTPClient([
            'base_uri' => LiaraStorageAdapter::API_URL,
            'headers' => [
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

    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path) {

    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname) {

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
        $location = $this->applyPathPrefix($path);
        if ($this->s3Client->doesObjectExist($this->bucket, $location, $this->options)) {
            return true;
        }
        return $this->doesDirectoryExist($location);
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
        $response = $this->readObject($path);
        if ($response !== false) {
            $response['stream'] = $response['contents']->detach();
            unset($response['contents']);
        }
        return $response;
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
        $response = $this->readObject($path);
        if ($response !== false) {
            $response['contents'] = $response['contents']->getContents();
        }
        return $response;
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
        $prefix = $this->applyPathPrefix(rtrim($directory, '/') . '/');
        $options = ['Bucket' => $this->bucket, 'Prefix' => ltrim($prefix, '/')];
        if ($recursive === false) {
            $options['Delimiter'] = '/';
        }
        $listing = $this->retrievePaginatedListing($options);
        $normalizer = [$this, 'normalizeResponse'];
        $normalized = array_map($normalizer, $listing);
        return Util::emulateDirectories($normalized);
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
        $command = $this->s3Client->getCommand(
            'headObject',
            [
                'Bucket' => $this->bucket,
                'Key'    => $this->applyPathPrefix($path),
            ] + $this->options
        );
        /* @var Result $result */
        try {
            $result = $this->s3Client->execute($command);
        } catch (S3Exception $exception) {
            $response = $exception->getResponse();
            if ($response !== null && $response->getStatusCode() === 404) {
                return false;
            }
            throw $exception;
        }
        return $this->normalizeResponse($result->toArray(), $path);
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

        try {
            $response = $this->client->request('POST', '/v1/storage/objects', [
                'body' => $body,
                'headers' => [
                    'X-Liara-Object-Size' => $size,
                    'X-Liara-Object-Key' => $path,
                ]
            ]);
        } catch (\Exception $e) {
            dd($e);
            return false;
        }
    }
}

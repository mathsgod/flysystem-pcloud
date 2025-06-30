<?php

namespace League\Flysystem\pCloud;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use pCloud\Sdk\Api;

class pCloudAdapter implements FilesystemAdapter
{

    static public $usHost = "https://api.pcloud.com/";
    static public $euHost = "https://eapi.pcloud.com/";
    public $client;
    private $api;

    public function __construct(
        private string $region,
        private string $accessToken,
    ) {
        // Initialize pCloud client here if needed

        $this->client = new \GuzzleHttp\Client([
            'base_uri' => $region === 'eu' ? self::$euHost : self::$usHost,
            "verify" => false, // Disable SSL verification for testing purposes
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);
        $this->api = new Api($this->client);
    }

    public function fileExists(string $path): bool
    {
        $result = $this->api->stat(path: $path);
        if ($meta = $result["metadata"]) {
            if ($meta["isfolder"]) {
                return false;
            } else {
                return true;
            }
        }
        return false;
    }

    public function directoryExists(string $path): bool
    {
        $result = $this->api->stat(path: $path);
        if ($meta = $result["metadata"]) {
            if ($meta["isfolder"]) {
                return true;
            } else {
                return false;
            }
        }
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {

        $dir = dirname($path);
        if ($dir === '.' || $dir === '/') {
            $dir = '/';
        } else {
            $dir = rtrim($dir, '/');
        }

        $folder = $this->api->createfolderifnotexists(path: $dir);

        $upload = $this->api->upload_create();

        $this->api->upload_write(uploadid: $upload["uploadid"], uploadoffset: 0, data: $contents);

        $this->api->upload_save(uploadid: $upload["uploadid"], name: basename($path), folderid: $folder["metadata"]["folderid"]);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (is_resource($contents)) {
            $dir = dirname($path);
            if ($dir === '.' || $dir === '/') {
                $dir = '/';
            } else {
                $dir = rtrim($dir, '/');
            }

            $folder = $this->api->createfolderifnotexists(path: $dir);
            $upload = $this->api->upload_create();

            $uploadid = $upload["uploadid"];
            $offset = 0;
            $chunkSize = 1024 * 1024; // 1MB

            while (!feof($contents)) {
                $data = fread($contents, $chunkSize);
                if ($data === false) {
                    throw new \RuntimeException("Failed to read from stream.");
                }
                $this->api->upload_write(uploadid: $uploadid, uploadoffset: $offset, data: $data);
                $offset += strlen($data);
            }

            $this->api->upload_save(uploadid: $uploadid, name: basename($path), folderid: $folder["metadata"]["folderid"]);
        } else {
            throw new \InvalidArgumentException("Contents must be a resource.");
        }
    }

    public function read(string $path): string
    {
        $result = $this->api->getfilelink(path: $path, forcedownload: true);
        if (isset($result["hosts"][0])) {
            return file_get_contents("https://" . $result["hosts"][0] . $result["path"]);
        }

        throw new \RuntimeException("Failed to read file: " . $path);
    }

    public function readStream(string $path): string
    {
        return $this->read($path); // pCloud does not support readStream directly, using read instead.
    }

    public function delete(string $path): void
    {
        $result = $this->api->deletefile(path: $path);
        if (!$result["result"]) {
            throw new \RuntimeException("Failed to delete file: " . $path);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $result = $this->api->deletefolder(path: $path);
        if (!$result["result"]) {
            throw new \RuntimeException("Failed to delete directory: " . $path);
        }
    }


    public function createDirectory(string $path, Config $config): void
    {
        $result = $this->api->createfolderifnotexists(path: $path);
        if (!$result["result"]) {
            throw new \RuntimeException("Failed to create directory: " . $path);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // pCloud does not support setting visibility directly.
        // You can implement custom logic here if needed.
        throw new \RuntimeException("Setting visibility is not supported by pCloud.");
    }

    public function visibility(string $path): FileAttributes
    {
        // pCloud does not support visibility in the same way as other filesystems.
        // You can implement custom logic here if needed.
        throw new \RuntimeException("Visibility is not supported by pCloud.");
    }

    public function mimeType(string $path): FileAttributes
    {
        $result = $this->api->stat(path: $path);
        if ($meta = $result["metadata"]) {
            if (isset($meta["contenttype"])) {
                return new FileAttributes(
                    path: $path,
                    fileSize: $meta["size"] ?? null,
                    mimeType: $meta["contenttype"],
                    lastModified: strtotime($meta["modified"]),
                    extraMetadata: [
                        'isFolder' => $meta['isfolder'] ?? false,
                    ]
                );
            }
        }
        throw new \RuntimeException("Failed to retrieve mime type for: " . $path);
    }


    public function lastModified(string $path): FileAttributes
    {
        $result = $this->api->stat(path: $path);
        if ($meta = $result["metadata"]) {
            return new FileAttributes(
                path: $path,
                fileSize: $meta["size"] ?? null,
                mimeType: $meta["contenttype"] ?? null,
                lastModified: strtotime($meta["modified"]),
                extraMetadata: [
                    'isFolder' => $meta['isfolder'] ?? false,
                ]
            );
        }
        throw new \RuntimeException("Failed to retrieve last modified for: " . $path);
    }

    public function fileSize(string $path): FileAttributes
    {
        $result = $this->api->stat(path: $path);
        if ($meta = $result["metadata"]) {
            return new FileAttributes(

                path: $path,
                fileSize: $meta["size"] ?? null,
                mimeType: $meta["contenttype"] ?? null,
                lastModified: strtotime($meta["modified"]),
                extraMetadata: [
                    'isFolder' => $meta['isfolder'] ?? false,
                ]
            );
        }
        throw new \RuntimeException("Failed to retrieve file size for: " . $path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $result = $this->client->get('listfolder', [
            'query' => [
                "folderid" => 0
            ]
        ]);

        $result = json_decode($result->getBody()->getContents(), true);
        if (isset($result['metadata']['contents'])) {
            foreach ($result['metadata']['contents'] as $item) {
                if (!empty($item['isfolder'])) {
                    // Directory
                    yield new \League\Flysystem\DirectoryAttributes(
                        $item['path'],
                        null,
                        strtotime($item['modified'])
                    );
                } else {
                    // File
                    yield new \League\Flysystem\FileAttributes(
                        $item['path'],
                        $item['size'] ?? null,
                        null,
                        strtotime($item['modified']),
                        $item['contenttype'] ?? null
                    );
                }
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $result = $this->api->renamefile(
            path: $source,
            topath: $destination
        );
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $result = $this->api->copyfile(
            path: $source,
            topath: $destination
        );
    }
}

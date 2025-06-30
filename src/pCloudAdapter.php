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
        $path = '/' . ltrim($path, '/');
        $result = $this->api->stat(path: $path);
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
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
        $path = '/' . ltrim($path, '/');
        $result = $this->api->stat(path: $path);
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
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

        $path = '/' . ltrim($path, '/');
        $dir = dirname($path);
        if ($dir === '.' || $dir === '/') {
            $dir = '/';
        } else {
            $dir = rtrim($dir, '/');
        }

        $this->createDirectory($dir, $config);

        $folder = $this->api->stat(path: $dir);
        if ($folder["result"] !== 0) {
            throw new \Exception($folder["error"]);
        }
        $folder = $folder["metadata"];


        $upload = $this->api->upload_create();
        if ($upload["result"] !== 0) {
            throw new \Exception($upload["error"]);
        }
        $this->api->upload_write(uploadid: $upload["uploadid"], uploadoffset: 0, data: $contents);

        $save = $this->api->upload_save(uploadid: $upload["uploadid"], name: basename($path), folderid: $folder["folderid"]);
        if ($save["result"] !== 0) {
            throw new \Exception($save["error"]);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $path = '/' . ltrim($path, '/');
        if (is_resource($contents)) {
            $dir = dirname($path);
            if ($dir === '.' || $dir === '/') {
                $dir = '/';
            } else {
                $dir = rtrim($dir, '/');
            }

            $folder = $this->api->createfolderifnotexists(path: $dir);
            if ($folder["result"] !== 0) {
                throw new \Exception($folder["error"]);
            }

            $stat = fstat($contents);
            $meta_data = stream_get_meta_data($contents);
            $result = $this->api->uploadfile(
                files: [
                    $meta_data['uri'],
                ],
                path: $dir,
                nopartial: true,
                mtime: $stat['mtime'] ?? null,
            );

            if ($result["result"] !== 0) {
                throw new \Exception($result["error"]);
            }
        } else {
            throw new \InvalidArgumentException("Contents must be a resource.");
        }
    }

    public function read(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $result = $this->api->getfilelink(path: $path, forcedownload: true);
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
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
        $path = '/' . ltrim($path, '/');
        $result = $this->api->deletefile(path: $path);
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $path = '/' . ltrim($path, '/');
        $result = $this->api->deletefolderrecursive(path: $path);
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $path = '/' . ltrim($path, '/');
        $parts = array_filter(explode('/', $path));
        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            $result = $this->api->createfolderifnotexists(path: $current);
            if ($result["result"] !== 0) {
                throw new \Exception($result["error"]);
            }
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
        $path = '/' . ltrim($path, '/');
        $result = $this->api->stat(path: $path);
        if ($result["result"] !== 0) {
            throw new \RuntimeException($result["error"]);
        }
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
        $path = '/' . ltrim($path, '/');
        $result = $this->api->stat(path: $path);
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
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
        $path = '/' . ltrim($path, '/');
        $result = $this->api->stat(path: $path);
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
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
        $path = '/' . ltrim($path, '/');
        $data = $this->api->listfolder(path: $path, recursive: $deep);
        if ($data["result"] !== 0) {
            throw new \Exception($data["error"]);
        }

        $walk = function (array $items, string $parentPath = '') use (&$walk) {
            foreach ($items as $item) {
                $itemPath = ($parentPath === '' ? '/' : $parentPath . '/' . ($item['name'] ?? ''));
                if (!empty($item['isfolder'])) {
                    yield new \League\Flysystem\DirectoryAttributes(
                        $itemPath,
                        null,
                        isset($item['modified']) ? strtotime($item['modified']) : null
                    );
                    // 修正：遞迴時要傳正確的 parentPath
                    if (!empty($item['contents']) && is_array($item['contents'])) {
                        // 傳入 $itemPath 作為 parentPath
                        foreach ($walk($item['contents'], $itemPath) as $sub) {
                            yield $sub;
                        }
                    }
                } else {
                    yield new \League\Flysystem\FileAttributes(
                        $itemPath,
                        $item['size'] ?? null,
                        null,
                        isset($item['modified']) ? strtotime($item['modified']) : null,
                        $item['contenttype'] ?? null
                    );
                }
            }
        };

        if (isset($data["metadata"]["contents"]) && is_array($data["metadata"]["contents"])) {
            yield from $walk($data["metadata"]["contents"], rtrim($path, '/'));
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $source = '/' . ltrim($source, '/');
        $destination = '/' . ltrim($destination, '/');
        // Ensure the destination directory exists
        $destinationDir = dirname($destination);
        $this->createDirectory($destinationDir, $config);

        $result = $this->api->renamefile(
            path: $source,
            topath: $destination
        );
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $source = '/' . ltrim($source, '/');
        $destination = '/' . ltrim($destination, '/');

        //destination folder may not exist, so we need to ensure it exists
        $destinationDir = dirname($destination);
        $this->createDirectory($destinationDir, $config);


        $result = $this->api->copyfile(
            path: $source,
            topath: $destination
        );
        if ($result["result"] !== 0) {
            throw new \Exception($result["error"]);
        }
    }
}

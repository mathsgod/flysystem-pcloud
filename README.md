# flysystem-pcloud

A [Flysystem](https://flysystem.thephpleague.com/) adapter for [pCloud](https://www.pcloud.com/), supporting PHP 8+. Usable in Laravel, Symfony, and any PHP project.

## Features

- Upload, download, delete, copy, and move files/folders
- Stream upload for large files
- Recursive directory listing
- Auto-create missing directories
- Robust error handling

## Installation

```bash
composer require mathsgod/flysystem-pcloud
```

## Usage

```php
use League\Flysystem\Filesystem;
use League\Flysystem\pCloud\pCloudAdapter;

$adapter = new pCloudAdapter(
    region: 'eu', // or 'us'
    accessToken: 'your-access-token'
);

$filesystem = new Filesystem($adapter);

// Upload a file
$filesystem->write('/folder/file.txt', 'content');

// Read a file
$content = $filesystem->read('/folder/file.txt');

// List directory contents
foreach ($filesystem->listContents('/folder', true) as $item) {
    echo $item->path() . PHP_EOL;
}
```

## Parameters

- `region`: `'eu'` or `'us'`, depending on your pCloud account region
- `accessToken`: Your pCloud OAuth2 access token

## Notes

- Directory operations will auto-create missing parent folders
- pCloud API only supports some file attributes; visibility is not supported
- Any API error will throw an Exception

## License

MIT

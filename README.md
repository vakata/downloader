# downloader
A PHP class for making a static copy (or indexing) a site

## Usage
```php
// download a whole site to a folder
(new Downloader('https://domain.tld/'))->download(__DIR__ . '/domain.tld/');

// download a part of site to a folder
(new Downloader('https://domain.tld/some-path/'))->download(__DIR__ . '/my-backup-copy/');

// if you want to host the static copy out of a subfolder - specify the webroot prefix
(new Downloader('https://domain.tld/'))->download(__DIR__ . '/static/', 'static');

// it is possible to specify a custom downloader
(new Downloader(
    'https://domain.tld/',
    function (string $url): string {
        // fetch the $url and return the contents as you see fit
    }
))->download(__DIR__ . '/static/');

// you can invoke a callback for each item and if that callback returns false - omit writing to disk
// this is useful for indexing purposes
(new Downloader(
    'https://domain.tld/',
    null,
    function (string $url, string $data) {
        // EXAMPLE: save to a database for indexing
        return false;
    }
))->download(__DIR__ . '/static/');
```

## Helpers
```php
// there are a couple of methods to help with copying and removing dirs
public static function emptyDir(string $dir, bool $delete_self = false): void;
public static function copyDir(string $src, string $dst): void;
```

## Example shell script

Used as:
```sh
./downloader.php "https://some.tld/"
```

```php
#!/usr/bin/env php
<?php
require_once __DIR__ . '/src/Downloader.php';

// the main URL
$base = isset($argv[1]) && strpos($argv[1], '://') !== false ? $argv[1] : 'http://localhost/';
// the host
$host = explode('/', explode('://', $base, 2)[1], 2)[0];
// where to save the static version to
$dest = __DIR__ . '/downloaded/' . basename($host) . '/';

if (!$base) {
    echo "Missing configuration!\r\n";
    die();
}

if (is_dir($dest)) {
    \vakata\downloader\Downloader::emptyDir($dest);
} else {
    mkdir($dest, 0775, true);
}

\vakata\downloader\Downloader::get($base)
    // ->add('/search')
    // ->rewrite(function ($url) {
    //     if (strpos($url, '/upload/') !== false) {
    //         return preg_replace('(upload/(\d+)/.*(\.[a-z0-9]+)$)ui', 'upload/$1$2', $url);
    //     }
    //     return $url;
    // })
    // ->filter(function ($url, $path) {
    //     return strpos($url, 'upload/') === false;
    // })
    ->download($dest);
```

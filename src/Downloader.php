<?php

declare(strict_types=1);

namespace vakata\downloader;

class Downloader
{
    protected string $url;
    /**
     * @var callable(string): string
     */
    protected $fetch;
    /**
     * @var callable|null
     */
    protected $item = null;
    /**
     * @var array<int,string>
     */
    protected array $queue = [];
    /**
     * @var array<int,callable>
     */
    protected array $filters = [];
    /**
     * @var array<int,callable>
     */
    protected array $rewrites = [];
    /**
     * @var array<string,bool>
     */
    protected array $processed = [];

    public function __construct(
        string $url,
        ?callable $fetch = null,
        ?callable $item = null,
    ) {
        $this->url = $url;
        $this->item = $item;
        $this->fetch = $fetch ?? function (string $url) {
            $data = file_get_contents(html_entity_decode($url), false, stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]));
            if ($data === false) {
                throw new \Exception();
            }
            return $data;
        };
        $this->add($url);
    }
    public function filter(callable $func) : static
    {
        $this->filters[] = $func;
        return $this;
    }
    public function rewrite(callable $func) : static
    {
        $this->rewrites[] = $func;
        return $this;
    }
    public function add(string $url) : static
    {
        $this->queue[] = $this->normalizeUrl($url);
        $this->processed[$url] = false;
        return $this;
    }
    /**
     * @param string $destination
     * @param string|null $remotePrefix
     * @return array<string,bool>
     */
    public function download(string $destination, ?string $remotePrefix = null): array
    {
        $relative = $remotePrefix === null;
        $destination = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        while ($url = array_shift($this->queue)) {
            try {
                $data = call_user_func($this->fetch, $url);
            } catch (\Exception $e) {
                continue;
            }
            $path = urldecode($this->urlToPath($url));
            if ($relative) {
                $remotePrefix = './';
                $i = count(explode(DIRECTORY_SEPARATOR, $path)) - 1;
                for ($i; $i > 0; $i--) {
                    $remotePrefix .= '../';
                }
            }
            if ($this->shouldSearch($url)) {
                $matches = [];
                if (preg_match_all('((src=|href=|url\\()(\'|")?([^ \'"\\)]+)(\'|"|\\)))i', $data, $matches)) {
                    foreach ($matches[3] as $k => $match) {
                        if (strpos($match, '#') === 0 ||
                            strpos($match, 'tel:') === 0 ||
                            strpos($match, 'data:') === 0 ||
                            strpos($match, 'mailto:') === 0 ||
                            strpos($match, 'javascript:') === 0
                        ) {
                            continue;
                        }
                        $matchUrl = $this->normalizeUrl($match, $url);
                        if (strpos($matchUrl, '//') !== false && strpos(explode('//', $matchUrl)[1], '/') === false) {
                            $matchUrl .= '/';
                        }
                        if ($this->shouldRewrite($matchUrl)) {
                            $data = str_replace(
                                $matches[1][$k] . $matches[2][$k] . $match . $matches[4][$k],
                                $matches[1][$k] . $matches[2][$k] . $remotePrefix .
                                $this->localUrl($matchUrl) . $matches[4][$k],
                                $data
                            );
                        }
                        if (!isset($this->processed[$matchUrl]) &&
                            $this->shouldDownload($matchUrl, $destination . $this->urlToPath($matchUrl))
                        ) {
                            $this->queue[] = $matchUrl;
                            $this->processed[$matchUrl] = false;
                        }
                    }
                }
            }
            $this->processed[$url] = true;
            if ($this->item === null || call_user_func($this->item, $url, $data) !== false) {
                $this->processed[$url] = $this->write($destination . $path, $data);
            }
        }

        return $this->processed;
    }

    protected function shouldSearch(string $url) : bool
    {
        if (strpos($url, 'cdn-cgi/') !== false) {
            return false;
        }
        $file = array_reverse(explode('/', explode('?', $url)[0]))[0] ?? '';
        $ext  = strpos($file, '.') === false ? 'html' : array_reverse(explode('.', $file))[0] ?? '';
        return in_array($ext, ['htm','html','css']);
    }
    protected function shouldRewrite(string $url) : bool
    {
        if (strpos($url, rtrim($this->url, '/')) === 0) {
            return true;
        }
        return false;
    }
    protected function shouldDownload(string $url, string $path) : bool
    {
        if (strpos($url, 'cdn-cgi/') !== false) {
            return false;
        }
        if (strpos($url, rtrim($this->url, '/')) !== 0) {
            return false;
        }
        foreach ($this->filters as $filter) {
            if (!call_user_func($filter, $url, $path)) {
                return false;
            }
        }
        return true;
    }
    protected function write(string $path, string $data) : bool
    {
        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            return file_put_contents($path, $data) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function normalizeUrl(string $url, ?string $currentUrl = null) : string
    {
        $url = explode('#', $url)[0];
        $url = html_entity_decode($url);
        $data = parse_url($this->url);
        if ($url === 'p+') {
            return $url;
        }
        if ($url[0] === '?') {
            return explode('?', $currentUrl ?? '')[0] . $url;
        }
        if (substr($url, 0, 2) === '//') {
            return ($data['scheme'] ?? 'http') . ':' . $url;
        }
        if ($url[0] === '/') {
            return ($data['scheme'] ?? 'http') . '://' . ($data['host'] ?? 'localhost') . $url;
        }
        if (strpos($url, '//') === false && $currentUrl) {
            $currentUrl = explode('/', substr($currentUrl, strlen($this->url)));
            unset($currentUrl[count($currentUrl) - 1]);
            $segments = explode('/', ltrim(preg_replace('(^\\./)', '', $url) ?? '', '/'));
            foreach ($segments as $k => $segment) {
                if ($segment === '..') {
                    if (!count($currentUrl)) {
                        return $url;
                    }
                    unset($currentUrl[count($currentUrl) - 1]);
                    unset($segments[$k]);
                }
            }
            return $this->url . implode('/', array_filter(array_merge($currentUrl, $segments)));
        }
        return $url;
    }
    protected function urlToPath(string $url) : string
    {
        $url = explode('?', $url, 2);
        $params = $url[1] ?? null;
        $url = $url[0];
        foreach ($this->rewrites as $rewrite) {
            $tmp = call_user_func($rewrite, $url);
            if (is_string($tmp)) {
                $url = $tmp;
            }
        }
        $isDir = substr($url, -1) === '/';
        $real = str_replace('/', DIRECTORY_SEPARATOR, trim(substr($url, strlen($this->url)), '/'));
        if (!strlen($real)) {
            $isDir = true;
        }
        if ($isDir) {
            $real .= DIRECTORY_SEPARATOR . 'index.html';
        } else {
            $last = array_reverse(explode(DIRECTORY_SEPARATOR, $real))[0] ?? '';
            if (strpos($last, '.') === false) {
                $real .= '.html';
            }
        }
        if ($params) {
            $real = preg_replace('(.[a-z]+$)i', '_' . md5($params) . '$0', $real);
        }
        return ltrim($real ?? '', DIRECTORY_SEPARATOR);
    }
    protected function pathToUrl(string $path) : string
    {
        return trim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/');
    }
    protected function localUrl(string $url) : string
    {
        return $this->pathToUrl($this->urlToPath($url));
    }

    public static function emptyDir(string $dir, bool $delete_self = false): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        if ($delete_self) {
            rmdir($dir);
        }
    }
    
    public static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0777, true);
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $item) {
            if ($item->isDir()) {
                mkdir($dst . DIRECTORY_SEPARATOR . $files->getSubPathName(), 0777, true);
            } else {
                if (strtolower($item->getExtension()) !== 'php' && substr($item->getBasename(), 0, 1) !== '.') {
                    copy($item->getRealPath(), $dst . DIRECTORY_SEPARATOR . $files->getSubPathName());
                }
            }
        }
    }
}

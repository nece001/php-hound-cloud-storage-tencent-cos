<?php

namespace Nece\Hound\Cloud\Storage;

use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\ServiceResponseException;

class TencentCos extends ObjectStorage implements IStorage
{
    /**
     *  COS客户端
     *
     * @var Client
     */
    private $client;
    private $bucket;
    private $region;
    private $base_uri;
    private $object_meta_data = array();

    public function __construct($secret_id, $secret_key, $bucket, $region, $base_uri = '', $token = null, $timeout = 10, $connect_timeout = 3, $proxy = null)
    {
        $config = [
            'schema' => 'https',
            'region' => $region,
            'timeout' => $timeout,
            'connect_timeout' => $connect_timeout,
            'proxy' => $proxy,
            'credentials' => [
                'secretId' => $secret_id,
                'secretKey' => $secret_key,
            ]
        ];

        if ($token) {
            $config['credentials']['token'] = $token;
        }

        $this->bucket = $bucket;
        $this->region = $region;
        $this->base_uri = rtrim(str_replace('\\', '/', $base_uri), '/');
        $this->client = new Client($config);
    }

    /**
     * @inheritDoc
     */
    public function exists(string $path): bool
    {
        $key = $this->keyPath($path);
        $result = $this->client->doesObjectExist($this->bucket, $key);
        if (!$result) {
            // 再查一下是不是目录
            return $this->isDir($path);
        }
        return $result ? true : false;
    }

    /**
     * @inheritDoc
     */
    public function isDir(string $path): bool
    {
        $key = $this->dirPath($path);
        $args = array(
            'Bucket' => $this->bucket,
            'Prefix' => $key,
            'MaxKeys' => 1,
        );
        $result = $this->client->listObjects($args);
        // print_r($result);
        $contents = isset($result['Contents']) ? $result['Contents'] : null;
        return $contents ? true : false;
    }

    /**
     * @inheritDoc
     */
    public function isFile(string $path): bool
    {
        $key = $this->keyPath($path);
        $head = $this->headObject($key);
        if (isset($head['Key'])) {
            // 最后一个字符不是"/"说明是文件
            if ('/' !== substr($head['Key'], -1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function copy(string $from, string $to): bool
    {
        $src = $this->keyPath($from);
        $dst = $this->keyPath($to);

        if (!$this->exists($from)) {
            throw new StorageException('源文件或目录不存在：' . $from, Consts::ERROR_CODE_NOT_FOUND);
        }

        try {
            if ($this->isFile($from)) {
                $this->copyFile($from, $to);
            } else {
                $src = $this->dirPath($from);
                $dst = $this->dirPath($to);
                $next_marker = '';
                while (true) {
                    $args = array(
                        'Bucket' => $this->bucket,
                        'Prefix' => $src,
                        'NextMarker' => $next_marker,
                    );

                    $objects = [];
                    $result = $this->client->listObjects($args);
                    if ($result) {
                        $next_marker = isset($result['NextMarker']) ? $result['NextMarker'] : '';
                        if (isset($result['Contents']) && $result['Contents']) {
                            foreach ($result['Contents'] as $object) {
                                $objects[$object['Key']] = $object['Key'];
                            }
                        }
                    }

                    foreach ($objects as $key) {
                        $copy_to = $dst . substr($key, strlen($src));
                        $this->copyFile($key, $copy_to);
                    }

                    if (!$next_marker) {
                        break;
                    }
                }
            }
        } catch (ServiceResponseException $e) {
            echo $e->getMessage(), PHP_EOL;
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): bool
    {
        $this->copy($from, $to);
        $this->delete($from);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): bool
    {
        if ($this->isFile($path)) {
            $key = $this->keyPath($path);
            $this->client->deleteObject(array(
                'Bucket' => $this->bucket,
                'Key' => $key,
            ));
        } else {
            $this->rmdir($path);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function mkdir(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        $key = $this->dirPath($path);
        $result = $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => '', // 空内容
        ]);
        return $result ? true : false;
    }

    /**
     * @inheritDoc
     */
    public function rmdir(string $path): bool
    {
        $key = $this->dirPath($path);
        $nextMarker = '';
        do {

            $args = [
                'Bucket'   => $this->bucket,
                'Prefix'   => $key,
                'Marker'   => $nextMarker,
                'MaxKeys'  => 1000,
            ];

            $list = $this->client->listObjects($args);
            $nextMarker = isset($list['NextMarker']) ? $list['NextMarker'] : '';
            if (isset($list['Contents']) && $list['Contents']) {
                $objects = [];
                foreach ($list['Contents'] as $c) {
                    $objects[] = ['Key' => $c['Key']];
                }

                $this->client->deleteObjects([
                    'Bucket'  => $this->bucket,
                    'Objects' => $objects,
                ]);
            }
        } while ($nextMarker);

        // 最后删目录本身
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function list(string $path, int $order = Consts::SCANDIR_SORT_ASCENDING, string $next_marker='', $max_keys=1000): array
    {
        $prefix = $this->dirPath($path);

        $args = array(
            'Bucket' => $this->bucket,
            'Prefix' => '/' == $prefix ? '' : $prefix,
            'Delimiter' => '/',
            'Marker' => $next_marker,
            'MaxKeys' => $max_keys,
        );
        
        $result = $this->client->listObjects($args);

        $list = array();
        // 列出目录
        if (isset($result['CommonPrefixes']) && $result['CommonPrefixes']) {
            foreach ($result['CommonPrefixes'] as $row) {
                $folder = $row['Prefix'];
                if ($prefix && 0 === strpos($folder, $prefix)) {
                    $folder = substr($folder, strlen($prefix));
                }

                if ($folder) {
                    $name = trim($folder, '/');
                    $list[] = $this->buildObjectListItem($name, 0, true, 0, 0, 0);
                }
            }
        }

        // 列出文件
        if (isset($result['Contents'])) {
            foreach ($result['Contents'] as $row) {
                $file = $row['Key'];
                $name = $file;
                if ($prefix && 0 === strpos($file, $prefix)) {
                    $name = substr($file, strlen($prefix));
                }

                if ($name) {
                    $size = $row['Size'];
                    $mtime = strtotime($row['LastModified']);
                    $atime = $mtime;
                    $ctime = $mtime;
                    $is_dir = false;

                    $list[] = $this->buildObjectListItem($name, $size, $is_dir, $atime, $ctime, $mtime);
                }
            }
        }

        return $list;
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local_src, string $to): bool
    {
        if (!file_exists($local_src)) {
            throw new StorageException('源文件或目录不存在', Consts::ERROR_CODE_NOT_FOUND);
        }

        if (is_file($local_src)) {
            $this->client->upload(
                $this->bucket,
                $to,
                fopen($local_src, 'rb')
            );
        } else {
            $dir = scandir($local_src);
            $dst = $this->dirPath($to);
            foreach ($dir as $name) {
                if (!in_array($name, ['.', '..'])) {
                    $file = $local_src . DIRECTORY_SEPARATOR . $name;
                    $key = $dst . $name;

                    $this->client->upload(
                        $this->bucket,
                        $key,
                        fopen($file, 'rb')
                    );
                }
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function download(string $src, string $local_dst): bool
    {
        if (!$this->exists($src)) {
            throw new StorageException('源文件或目录不存在', Consts::ERROR_CODE_NOT_FOUND);
        }

        if ($this->isFile($src)) {
            $from = $this->keyPath($src);
            $this->client->download($this->bucket, $from, $local_dst);
        } else {
            $from = trim($this->dirPath($src), '/'); // 去掉目录末尾的"/"才能列出所有对象
            $local_dst = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $local_dst), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            if (!file_exists($local_dst)) {
                mkdir($local_dst, 0755, true);
            }

            $next_marker = '';
            $is_truncated = true;

            while ($is_truncated) {

                $args = array(
                    'Bucket' => $this->bucket,
                    'Marker' => $next_marker,
                    'Prefix' => $from,
                    'MaxKeys' => 1,
                );
                $result = $this->client->listObjects($args);

                $is_truncated = $result['IsTruncated'];
                $next_marker = $result['NextMarker'];

                if (isset($result['Contents']) && $result['Contents']) {
                    foreach ($result['Contents'] as $content) {
                        $key = $content['Key'];
                        $file = str_replace('/', DIRECTORY_SEPARATOR, $local_dst . trim(substr($key, strlen($from)), '/'));

                        $is_dir = (substr($key, -1) === '/');
                        if ($is_dir) {
                            $dir = $file;
                        } else {
                            $dir = dirname($file);
                        }

                        if (!file_exists($dir)) {
                            mkdir($dir, 0755, true);
                        }

                        if (!$is_dir) {
                            $result = $this->client->download(
                                $this->bucket,
                                $key,
                                $file
                            );
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function file(string $path): IObject
    {
        $key = $this->keyPath($path);
        $meta = $this->headObject($key);
        if (!$meta) {
            return TencentCosObject::createObject($this->client, $this->bucket, $key, 0, 0, 0, '', '', false);
        }
        return TencentCosObject::createObject($this->client, $this->bucket, $key, strtotime($meta['LastModified']), strtotime($meta['LastModified']),  $meta['ContentLength'], $meta['ContentType'], $meta['Location'], false);
    }

    /**
     * @inheritDoc
     */
    public function uri(string $path): string
    {
        return $this->keyPath($path);
    }

    /**
     * @inheritDoc
     */
    public function url(string $path, string $schema = 'https'): string
    {
        $key = $this->keyPath($path);
        if ($this->base_uri) {
            return $this->base_uri . '/' . $key;
        }

        $info = $this->headObject($key);
        if ($info) {
            return $schema . '://' . $info['Location'];
        }

        return '';
    }

    private function copyFile($from, $to)
    {
        return $this->client->copy(
            $this->bucket,
            $to,
            array(
                'Region' => $this->region,
                'Bucket' => $this->bucket,
                'Key' => $from,
            )
        );
    }

    private function headObject($key)
    {
        if (!isset($this->object_meta_data[$key])) {
            try {
                $this->object_meta_data[$key] = $this->client->headObject(array(
                    'Bucket' => $this->bucket,
                    'Key' => $key
                ));
            } catch (ServiceResponseException $e) {
                // GuzzleHttp\Psr7\Response
                // 目录可能查不到重新查一次
                if ($e->getResponse()->getStatusCode() == 404) {
                    try {
                        $this->object_meta_data[$key] = $this->client->headObject(array(
                            'Bucket' => $this->bucket,
                            'Key' => $key . '/'
                        ));
                    } catch (ServiceResponseException $e) {
                        if ($e->getResponse()->getStatusCode() == 404) {
                            return null;
                        }
                    }
                }
            }
        }
        return $this->object_meta_data[$key];
    }
}

<?php

namespace Nece\Hound\Cloud\Storage;

use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\ServiceResponseException;

class TencentCosObject implements IObject
{
    /**
     * TencentCos客户端
     *
     * @var Client
     */
    private $client;

    /**
     * 对象元数据
     *
     * @var array
     */
    private $info = array();

    public static function createObject(Client $client, string $bucket, string $key, int $mtime, int $atime, int $size, string $mime_type, string $url, bool $is_dir)
    {
        $info = array(
            'bucket' => $bucket,
            'key' => $key,
            'mtime' => $mtime,
            'atime' => $atime,
            'size' => $size,
            'mime_type' => $mime_type,
            'url' => $url,
            'is_dir' => $is_dir,
        );
        return new self($client, $info);
    }

    public function __construct(Client $client, array $info)
    {
        $this->client = $client;
        $this->info = $info;
    }

    /**
     * @inheritdoc
     */
    public function getAccessTime(): int
    {
        return $this->info['atime'];
    }

    /**
     * @inheritdoc
     */
    public function getCreateTime(): int
    {
        return $this->getModifyTime();
    }

    /**
     * @inheritdoc
     */
    public function getModifyTime(): int
    {
        return $this->info['mtime'];
    }

    /**
     * @inheritdoc
     */
    public function getBasename(string $suffix = ""): string
    {
        return basename($this->getKey(), $suffix);
    }

    /**
     * @inheritdoc
     */
    public function getExtension(): string
    {
        return pathinfo($this->getKey(), PATHINFO_EXTENSION);
    }

    /**
     * @inheritdoc
     */
    public function getFilename(): string
    {
        return pathinfo($this->getKey(), PATHINFO_FILENAME);
    }

    /**
     * @inheritdoc
     */
    public function getPath(): string
    {
        return dirname($this->getKey());
    }

    /**
     * @inheritdoc
     */
    public function getRealname(): string
    {
        return $this->getKey();
    }

    /**
     * @inheritdoc
     */
    public function getKey(): string
    {
        return $this->info['key'];
    }

    /**
     * @inheritdoc
     */
    public function getSize(): int
    {
        return $this->info['size'];
    }

    /**
     * @inheritdoc
     */
    public function getMimeType(): string
    {
        return $this->info['mime_type'];
    }

    /**
     * @inheritdoc
     */
    public function isDir(): bool
    {
        return $this->info['is_dir'];
    }

    /**
     * @inheritdoc
     */
    public function isFile(): bool
    {
        return !$this->isDir();
    }

    /**
     * @inheritdoc
     */
    public function getContent(): string
    {
        $result = $this->client->getObject(array(
            'Bucket' => $this->info['bucket'],
            'Key' => $this->getKey(),
        ));

        return $result['Body']->getContents();
    }

    /**
     * @inheritdoc
     */
    public function putContent(string $content, bool $append = false): bool
    {
        if ($append) {
            try {
                $tmp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aliyun_oss_append_tmp_file_' . rand();
                $this->client->download($this->info['bucket'], $this->getKey(), $tmp_file);
                file_put_contents($tmp_file, $content, FILE_APPEND);
                $content = file_get_contents($tmp_file);
                unlink($tmp_file);
            } catch (ServiceResponseException $e) {
                if ($e->getStatusCode() != 404) {
                    throw $e;
                }
            }
        }

        $this->client->putObject(array(
            'Bucket' => $this->info['bucket'],
            'Key' => $this->getKey(),
            'Body' => $content,
        ));

        return true;
    }

    /**
     * @inheritdoc
     */
    public function delete(): bool
    {
        $args = array(
            'Bucket' => $this->info['bucket'],
            'Key' => $this->getKey(),
        );
        $this->client->deleteObject($args);
        return true;
    }

    /**
     * @inheritdoc
     */
    public function __toString(): string
    {
        return $this->getKey();
    }
}

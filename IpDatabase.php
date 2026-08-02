<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/** Reader for the compact, read-only VLP IPv4 database format. */
class VisitorLoggerPro_IpDatabase
{
    const HEADER_SIZE = 40;
    const RECORD_SIZE = 12;

    private $handle;
    private $recordCount;
    private $locationCount;
    private $bucketOffset;
    private $recordOffset;
    private $locationIndexOffset;
    private $locationDataOffset;

    public function __construct($path)
    {
        $this->handle = @fopen($path, 'rb');
        if ($this->handle === false) {
            throw new RuntimeException('无法打开 IP 数据库: ' . $path);
        }
        $header = $this->read(0, self::HEADER_SIZE);
        if (substr($header, 0, 8) !== "VLPIPD1\0") {
            throw new RuntimeException('不支持的 IP 数据库格式: ' . $path);
        }
        $values = unpack(
            'Vversion/Vrecords/Vlocations/Vbucket/Vrecord/VlocationIndex/VlocationData/VfileSize',
            substr($header, 8)
        );
        if ($values['version'] !== 1 || $values['fileSize'] !== filesize($path)) {
            throw new RuntimeException('IP 数据库版本或文件长度无效: ' . $path);
        }
        $this->recordCount = $values['records'];
        $this->locationCount = $values['locations'];
        $this->bucketOffset = $values['bucket'];
        $this->recordOffset = $values['record'];
        $this->locationIndexOffset = $values['locationIndex'];
        $this->locationDataOffset = $values['locationData'];
    }

    public function search($ip)
    {
        $packed = @inet_pton((string) $ip);
        if ($packed === false || strlen($packed) !== 4) {
            throw new InvalidArgumentException('无效的 IPv4 地址: ' . $ip);
        }
        $octets = unpack('C4', $packed);
        $number = unpack('Nvalue', $packed)['value'];
        $bucket = unpack(
            'Vlow/Vhigh',
            $this->read($this->bucketOffset + $octets[1] * 8, 8)
        );
        $low = $bucket['low'];
        $high = $bucket['high'] - 1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            $record = unpack(
                'Vstart/Vend/Vlocation',
                $this->read($this->recordOffset + $middle * self::RECORD_SIZE, self::RECORD_SIZE)
            );
            if ($number < $record['start']) {
                $high = $middle - 1;
            } elseif ($number > $record['end']) {
                $low = $middle + 1;
            } else {
                return $this->readLocation($record['location']);
            }
        }
        return array('country' => '', 'region' => '', 'city' => '', 'isp' => '');
    }

    private function readLocation($id)
    {
        if ($id >= $this->locationCount) {
            throw new RuntimeException('IP 数据库地点索引越界');
        }
        $offsets = unpack(
            'Vstart/Vend',
            $this->read($this->locationIndexOffset + $id * 4, 8)
        );
        $raw = $this->read(
            $this->locationDataOffset + $offsets['start'],
            $offsets['end'] - $offsets['start']
        );
        $parts = array_pad(explode("\x1f", $raw), 4, '');
        return array(
            'country' => $parts[0],
            'region' => $parts[1],
            'city' => $parts[2],
            'isp' => $parts[3]
        );
    }

    private function read($offset, $length)
    {
        if (fseek($this->handle, $offset) !== 0) {
            throw new RuntimeException('IP 数据库定位失败');
        }
        $data = fread($this->handle, $length);
        if ($data === false || strlen($data) !== $length) {
            throw new RuntimeException('IP 数据库读取不完整');
        }
        return $data;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}

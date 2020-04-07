<?php

namespace InfluxDB2;


use InfluxDB2\Model\WritePrecision;

class WriteType
{
    const SYNCHRONOUS = 1;
    const BATCHING = 2;
}

class WriteOptions
{
    const DEFAULT_BATCH_SIZE = 10;
    const DEFAULT_FLUSH_INTERVAL = 1000;

    public $writeType;
    public $batchSize;
    public $flushInterval;

    /**
     * WriteOptions constructor.
     *      $writeOptions = [
     *          'writeType' => methods of write (WriteType::SYNCHRONOUS - default, WriteType::BATCHING)
     *          'batchSize' => the number of data point to collect in batch
     *          'flushInterval' => flush data at least in this interval
     *      ]
     * @param array $writeOptions Array containing the write parameters (See above)
     */
    public function __construct(array $writeOptions = null)
    {
        //initialize with default values
        $this->writeType =  $writeOptions["writeType"] ?: WriteType::SYNCHRONOUS;
        $this->batchSize = $writeOptions["batchSize"] ?:  self::DEFAULT_BATCH_SIZE;
        $this->flushInterval = $writeOptions["flushInterval"] ?:  self::DEFAULT_FLUSH_INTERVAL;
    }
}

class PointSettings
{
    private $defaultTags;

    public function __construct(array $defaultTags = null)
    {
        $this->defaultTags = array();

        if (!empty($defaultTags)) {
            foreach (array_keys($defaultTags) as $key) {
                $this->addDefaultTag($key, $defaultTags[$key]);
            }
        }
    }

    public function addDefaultTag(string $key, string $value)
    {
        $this->defaultTags[$key] = $this->getValue($value);
    }

    public static function getValue(string $value): string
    {
        if (substr( $value, 0, 6 ) === '${env.')
        {
            return getenv(substr( $value, 6, strlen($value) - 7));
        }

        return $value;
    }

    public function getDefaultTags()
    {
        return $this->defaultTags;
    }
}

/**
 * Write time series data into InfluxDB.
 * @package InfluxDB2
 */
class WriteApi extends DefaultApi
{
    public $writeOptions;
    public $pointSettings;

    /** @var Worker */
    private $worker;
    public $closed = false;

    /**
     * WriteApi constructor.
     * @param $options
     * @param array $writeOptions
     * @param array|null $pointSettings
     */
    public function __construct($options, array $writeOptions = null, array $pointSettings = null)
    {
        parent::__construct($options);
        $this->writeOptions = new WriteOptions($writeOptions) ?: new WriteOptions();
        $this->pointSettings = new PointSettings($pointSettings) ?: new PointSettings();

        if (array_key_exists('tags', $options))
        {
            foreach (array_keys($options['tags']) as $key)
            {
                $this->pointSettings->addDefaultTag($key, $options['tags'][$key]);
            }
        }
    }

    /**
     * Write data into specified bucket
     *
     * Example write data in array
     *      $writeApi->write([
     *          ['name' => 'cpu','tags' => ['host' => 'server_nl', 'region' => 'us'],
     *              'fields' => ['internal' => 5, 'external' => 6],
     *              'time' => 1422568543702900257],
     *          ['name' => 'gpu', 'fields' => ['value' => 0.9999]]],
     *      WritePrecision::NS,
     *      'my-bucket',
     *      'my-org'
     *      )
     *
     * Example write data in line protocol
     *      $writeApi->write('h2o,location=west value=33i 15')
     *
     * Example write data using Point structure
     *      $point = new Point("h2o).
     *
     *
     * @param string|Point|array $data DataPoints to write into InfluxDB. The data could be represent by
     * array, Point, string
     * @param string|null $precision The precision for the unix timestamps within the body line-protocol @see \InfluxDB2\Model\WritePrecision
     * @param string|null $bucket specifies the destination bucket for writes
     * @param string|null $org specifies the destination organization for writes
     * @throws ApiException
     */
    public function write($data, string $precision = null, string $bucket = null, string $org = null)
    {
        $precisionParam = $this->getOption("precision", $precision);
        $bucketParam = $this->getOption("bucket", $bucket);
        $orgParam = $this->getOption("org", $org);

        $this->check("precision", $precisionParam);
        $this->check("bucket", $bucketParam);
        $this->check("org", $orgParam);

        $this->addDefaultTags($data);

        $payload = $this->generatePayload($data, $precisionParam, $bucketParam, $orgParam);

        if ($payload == null) {
            return;
        }

        if (WriteType::BATCHING == $this->writeOptions->writeType)
        {
            $this->worker()->push($payload);
        } else {
            $this->writeRaw($payload, $precisionParam, $bucketParam, $orgParam);
        }
    }

    private function addDefaultTags(&$data)
    {
        $defaultTags = $this->pointSettings->getDefaultTags();

        if (is_array($data))
        {
            if (array_key_exists('name', $data))
            {
                foreach (array_keys($defaultTags) as $key)
                {
                    $data['tags'][$key] = PointSettings::getValue($defaultTags[$key]);
                }
            }
            else
            {
                foreach ($data as &$item)
                {
                    $this->addDefaultTags($item);
                }
            }
        }
        elseif ($data instanceof Point)
        {
            foreach (array_keys($defaultTags) as $key)
            {
                $data->addTag($key, PointSettings::getValue($defaultTags[$key]));
            }
        }
    }

    /**
     * Writes data using line protocol.
     *
     * @param string $data payload data as string (in line protocol format)
     * @param string|null $precision The precision for the unix timestamps within the body line-protocol
     * @param string|null $bucket specifies the destination bucket for writes
     * @param string|null $org specifies the destination organization for writes
     * @throws ApiException
     *
     * @see \InfluxDB2\Model\WritePrecision
     */
    public function writeRaw(string $data, string $precision = null, string $bucket = null, string $org = null)
    {
        $precisionParam = $this->getOption("precision", $precision);
        $bucketParam = $this->getOption("bucket", $bucket);
        $orgParam = $this->getOption("org", $org);

        $this->check("precision", $precisionParam);
        $this->check("bucket", $bucketParam);
        $this->check("org", $orgParam);

        $queryParams = ["org" => $orgParam, "bucket" => $bucketParam, "precision" => $precisionParam];

        $this->post($data, "/api/v2/write", $queryParams);
    }

    public function close()
    {
        $this->closed = true;

        $this->worker()->flush();
    }

    private function worker(): Worker
    {
        if (!isset($this->worker))
        {
            $this->worker = new Worker($this);
        }

        return $this->worker;
    }

    private function generatePayload($data, string $precision = null, string $bucket = null, string $org = null)
    {
        if ($data == null || empty($data)) {
            return null;
        }
        if (is_string($data)) {

            if (WriteType::BATCHING == $this->writeOptions->writeType) {
                return new BatchItem(new BatchItemKey($bucket, $org, $precision), $data);
            } else {
                return $data;
            }
        }
        if ($data instanceof Point) {
            return $this->generatePayload($data->toLineProtocol(), $data->getPrecision() !== null ?
                $data->getPrecision() : $precision, $bucket, $org);
        }
        if (is_array($data))
        {
            if (array_key_exists('name', $data))
            {
                return $this->generatePayload(Point::fromArray($data), $precision, $bucket, $org);
            }

            $payload = '';

            foreach ($data as $item)
            {
                if (isset($item)) {
                    $payload .= $this->generatePayload($item, $precision, $bucket, $org) . "\n";
                }
            }

            // remove last new line
            if (isset($payload) && trim($payload) !== '')
            {
                $payload = rtrim($payload, "\n");
            }

            return $payload;
        }

        return null;
    }

    private function getOption(string $optionName, string $precision = null): string
    {
        return isset($precision) ? $precision : $this->options["$optionName"];
    }
}

/**
 * Item for batching queue
 */
class BatchItem
{
    /** @var BatchItemKey */
    public $key;
    /** @var string */
    public $data;

    public function __construct($key, $data)
    {
        $this->key = $key;
        $this->data = $data;
    }
}

/**
 * Key for batch item
 */
class BatchItemKey
{
    /** @var string */
    public $bucket;
    /** @var string */
    public $org;
    /** @var WritePrecision */
    public $precision;

    public function __construct($bucket, $org, $precision)
    {
        $this->bucket = $bucket;
        $this->org = $org;
        $this->precision = $precision;
    }
}

<?php

// A child process of BucketStoreContractTest::S9: 500 increments of 1 on one key of one bucket.
// argv: <autoload> <json config>   config: {"store":"file"|"phpredis"|"predis","directory":…,"host":…,"prefix":…}
declare(strict_types=1);

require $argv[1];

$config = json_decode($argv[2], true);
switch ($config['store']) {
    case 'file':
        $store = new WatchTower15\Store\FileStore($config['directory'], $config['prefix']);
        break;
    case 'phpredis':
        $redis = new Redis();
        $redis->connect($config['host']);
        $store = new WatchTower15\Store\RedisStore($redis, $config['prefix']);
        break;
    default:
        $store = new WatchTower15\Store\RedisStore(new Predis\Client(['host' => $config['host']]), $config['prefix']);
}
for ($i = 0; $i < 500; $i++) {
    $store->increment(1, 'k', 1, 7200);
}

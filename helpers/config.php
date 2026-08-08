<?php

function getConfig(string $key): mixed {
    static $config = null;
    if ($config === null) {
        $config = json_decode(file_get_contents(__DIR__ . '/../config/config.json'), true);
    }
    if (!array_key_exists($key, $config)) {
        throw new \RuntimeException("Config key '{$key}' not found");
    }
    return $config[$key];
}

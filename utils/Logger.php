<?php

namespace utils;

class Logger
{
    private $logFile;

    public function __construct($logFile = null)
    {
        $this->logFile = $logFile ?? __DIR__ . '/../logs/app.log';
    }

    public function log($message, $type = 'INFO')
    {
        $date = date('Y-m-d H:i:s');
        $logMessage = "[$date][$type] $message" . PHP_EOL;

        // Asegurarse que el directorio existe
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    public function info($message)
    {
        $this->log($message, 'INFO');
    }

    public function error($message)
    {
        $this->log($message, 'ERROR');
    }

    public function warning($message)
    {
        $this->log($message, 'WARNING');
    }

    public function debug($message)
    {
        if ($_ENV['APP_DEBUG'] ?? false) {
            $this->log($message, 'DEBUG');
        }
    }
}

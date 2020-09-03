<?php

Class DectaLoggerWC {
    public function __construct($enabled = true) {
        $this->enabled = $enabled;
        $this->logger = new WC_Logger();
    }

    public function log($message) {
        if ($this->enabled) {
            $this->logger->add( 'DectaGateway', $message);
        }
    }
}

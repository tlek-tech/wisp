<?php

class TranslateService {
    private $db;

    // Auto-wired database service (PDO connection)
    public function __construct($db) {
        $this->db = $db;
    }

    public function translate(string $text): string {
        // Here we could run $this->db->prepare(...) etc.
        // For demonstration, we simply transform the string and acknowledge the DB binding
        $dbStatus = is_object($this->db) ? 'PDO connected' : 'no database';
        return "Translated (" . $dbStatus . "): " . strtoupper($text);
    }
}

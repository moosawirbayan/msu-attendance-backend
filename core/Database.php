<?php
class Database {
    private $host = "bgdjd7pnoftx1p4yq4bj-mysql.services.clever-cloud.com";
    private $database_name = "bgdjd7pnoftx1p4yq4bj";
    private $username = "u3miutjfjda1dnby";
    private $password = "mZgVKFLZ31Dm4i7GbWQS";
    private $port = "3306";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->database_name . ";port=" . $this->port,
                $this->username,
                $this->password
            );
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            return null;
        }
        return $this->conn;
    }
}
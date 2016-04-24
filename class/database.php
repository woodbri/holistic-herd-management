<?php
 
class Database {

  protected $dbh;
  private $exitOnException = true;
  private $schema = 'data';
 
  public function __construct() {
    $this->dbh = null;
  }

  public function __destruct() {
    if ($this->dbh !== null)
      pg_close($this->dbh);
  }

  private function croak($msg) {
    if ($exitOnException) {
        echo "{\"status\": \"ERROR\", \"error\": \"$msg\"}";
        exit;
    }
  }

  private function dbconnect() {
    $dsn = "dbname=" . DB_NAME . " user=" . DB_USER . " host=" . DB_HOST .
        " port=" . DB_PORT . " password=" . DB_PASS;
    $this->dbh = pg_connect( $dsn )
        or $this->croak("Could not connect to database server");
  }

  public function getExitOnException() {
    return $exitOnException;
  }

  public function setExitOnException($b) {
    $exitOnException = $b;
  }

  public function query($sql, $params = array()) {
    if ($this->dbh === null)
      $this->dbconnect();

    $res = pg_query_params($this->dbh, $sql, $params);

    if ($res) {
      if (stripos($sql,'SELECT') === false) {
        pg_get_result($this->dbh);
        return true;
      }
    }
    else {
      if (stripos($sql,'SELECT') === false) {
        return false;
      }
      else {
        return null;
      }
    }
 
    $results = array();
 
    while ($row = pg_fetch_assoc($res)) {
      $results[] = $row;
    }
    pg_free_result($res);

    return $results;
  }

  public function delete($table, $keyval) {
    if ($this->dbh === null)
      $this->dbconnect();

    return pg_delete($this->dbh, $table, $keyval);
  }

  public function deleteIds($table, $id, $vals) {
    if ($this->dbh === null)
      $this->dbconnect();

    $this->query("begin");
    foreach ($vals as $v) {
        $ret = pg_delete($this->dbh, $table, array($id => $v));
        if ($ret === false) {
            $this->query("rollback");
            return $ret;
        }
    }
    return $this->query("commit");
  }

  public function insert($table, $keyval) {
    if ($this->dbh === null)
        $this->dbconnect();

    return pg_insert($this->dbh, $table, $keyval);
  }

  public function insertArray($table, $array) {
    if ($this->dbh === null)
        $this->dbconnect();

    $this->query("begin");
    foreach ( $array as $kv ) {
        $ret = $this->insert( $table, $kv );
        if ($ret === false) {
            $this->query("rollback");
            return $ret;
        }
    }
    return $this->query("commit");
  }

  public function update($table, $keyval, $wherekv) {
    if ($this->dbh === null)
      $this->dbconnect();

    return pg_update($this->dbh, $table, $keyval, $wherekv);
  }

}

?>

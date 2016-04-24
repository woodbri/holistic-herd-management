<?php
/*



*/

require "holistic-database.php";

// parse commandline arguments
if (isset($argv))
    parse_str(implode('&', array_slice($argv, 1)), $_REQUEST);
$_REQUEST = array_change_key_case($_REQUEST);
if (! isset($_REQUEST['mode'])) $_REQUEST['mode'] = '';

if (isset($argv))
    print_r($_REQUEST);

$dbh = pg_connect($dsn);
if (!$dbh)
    returnError("Database connection failed!");

switch (strtolower($_REQUEST['mode'])) {
case "schema":
    createSchema();
    break;
case "add":
    if (isset($_REQUEST['id']))
        returnError("id must not be set for mode=add");
    addPaddockExc();
    break;
case "list":
case "":
    if (isset($_REQUEST['id']))
        listPaddockExc();
    else
        listPaddockExcs();
    break;
case "update":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=update");
    updatePaddockExc();
    break;
case "delete":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=delete");
    deletePaddockExc();
    break;
default:
    returnError("invalid mode: '" . $_REQUEST['mode'] . "'");
    break;
}
pg_close($dbh);
exit(0);


function returnError($str) {
    global $dbh;
    pg_close($dbh);
    echo "{\"status\": \"ERROR\", \"error\": \"$str\"}";
    exit(1);
}

function createSchema() {
    $sql = '
create table paddock_exclusions(
    id serial not null primary key,
    plan integer not null,
    padid integer not null,
    exc_type text not null,
    exc_start timestamp without time zone,
    exc_end timestamp without time zone,
    reason text
)';
    $r = pg_query($sql);

    exit(0);
}


function addPaddockExc() {
    if (! strlen($_REQUEST['plan']))
        returnError("plan is a required field");
    if (! strlen($_REQUEST['padid']))
        returnError("padid is a required field");
    if (! strlen($_REQUEST['exc_type']))
        returnError("exc_type is a required field");
    if (! strlen($_REQUEST['exc_start']))
        returnError("exc_start is a required field");
    if (! strlen($_REQUEST['exc_end']))
        returnError("exc_end is a required field");

    $data = array(
        $_REQUEST['plan'],
        $_REQUEST['padid'],
        $_REQUEST['exc_type'],
        $_REQUEST['exc_start'],
        $_REQUEST['exc_end'],
        strlen($_REQUEST['reason']) ? $_REQUEST['reason']  : null
    );

    $r = pg_query_params("insert into paddock_exclusions (plan, padid, exc_type, exc_start, exc_end, reason) values ($1, $2, $3, $4, $5, $6)", $data);

    listPaddockExc();
}

function listPaddockExc() {
    if (isset($_REQUEST['id']))
        $id = intval($_REQUEST['id']);
    else
        $id = "lastval()";

    $sql = "select a.id, a.plan, a.padid, a.exc_type,
        date(a.exc_start) as exc_start,
        date(a.exc_end) as exc_end, a.reason, b.name
        from paddock_exclusions a,
        paddocks b where a.padid=b.id and a.id={$id}";
    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r)) {
        echo json_encode($row);
        pg_free_result($r);
    }
    else {
        pg_free_result($r);
        returnError("Problem fetching paddock_exclusions from database!");
    }
}

function listPaddockExcs() {
    $sql = "select a.id, a.plan, a.padid, a.exc_type,
        date(a.exc_start) as exc_start,
        date(a.exc_end) as exc_end, a.reason, b.name
        from paddock_exclusions a, paddocks b 
        where  a.padid=b.id order by id";
    $result = array();
    $r = pg_query($sql);
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }
    pg_free_result($r);
    echo json_encode(array( 'data' => $result));
}

function updatePaddockExc() {
    if (! isset($_REQUEST['id']) or ! strlen($_REQUEST['id']))
        returnError("id must be set for updates!");
    if (! strlen($_REQUEST['plan']))
         returnError("plan is a required field");

    $data = array();
    $term = array();
    if (isset($_REQUEST['padid']) && strlen($_REQUEST['padid']) &&
            $_REQUEST['padid'] != 'null') {
        array_push($data, $_REQUEST['padid']);
        array_push($term, 'padid=$' . count($data));
    }
    if (isset($_REQUEST['exc_type']) && strlen($_REQUEST['exc_type']) &&
            $_REQUEST['exc_type'] != 'null') {
        array_push($data, $_REQUEST['exc_type']);
        array_push($term, 'exc_type=$' . count($data));
    }
    if (isset($_REQUEST['exc_start']) && strlen($_REQUEST['exc_start']) &&
            $_REQUEST['exc_start'] != 'null') {
        array_push($data, $_REQUEST['exc_start']);
        array_push($term, 'exc_start=$' . count($data));
    }
    if (isset($_REQUEST['exc_end']) && strlen($_REQUEST['exc_end']) &&
            $_REQUEST['exc_end'] != 'null') {
        array_push($data, $_REQUEST['exc_end']);
        array_push($term, 'exc_end=$' . count($data));
    }
    if (isset($_REQUEST['reason'])) {
        if ($_REQUEST['reason'] == 'null' or
                ! strlen($_REQUEST['reason'])) {
            array_push($term, 'reason=null');
        }
        else {
            array_push($data, $_REQUEST['reason']);
            array_push($term, 'reason=$' . count($data));
        }
    }
    array_push($data, $_REQUEST['id']);
    array_push($data, $_REQUEST['plan']);
    $sql = 'update paddock_exclusions set ' . implode(", ", $term) . ' where id=$' . (count($data)-1) . ' and plan=$' . count($data);
    $r = pg_query_params($sql, $data);

    listPaddockExc();
}

function deletePaddockExc() {
    $r = pg_query_params("delete from paddock_exclusions where id=$1",
        array($_REQUEST['id']));
    echo '{"status": "OK", "mode": "delete", "id": ' . $_REQUEST['id'] . '}';
}


?>

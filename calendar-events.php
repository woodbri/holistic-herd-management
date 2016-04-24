<?php
/*
 * mode=schema|add|list|update|delete
 * id=(integer)
 * title=(string)
 * start=(string iso8601)
 * end=(string iso8601)
 * url=(string)
 * allday=0|1
 * classname=(string)
 *      evt-unknown
 *      evt-social
 *      evt-livestock
 *      evt-paddock
 *      evt-monitor
 *      evt-planning
 * description=(string)
 * refid=(string) can be a comma separated list of refids
 *      H<num> - reference to a record in herds table
 *      P<num> - reference to a record in the paddock table
 *      E<num> - reference to a record in the paddockevents table
 *
 *  ## reserved for future implementation
 *      R<num> - reference to a record in herd_rotations table
 *      X<num> - reference to a record in grazing_exclusions table
*/

require "holistic-database.php";


// parse commandline arguments 
if (isset($argv))
    parse_str(implode('&', array_slice($argv, 1)), $_REQUEST);
$_REQUEST = array_change_key_case($_REQUEST);
if (! isset($_REQUEST['mode'])) $_REQUEST['mode'] = '';

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
    addEvent();
    break;
case "list":
case "":
    if (isset($_REQUEST['id']))
        listEvent();
    else
        listEvents();
    break;
case "moveend":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=moveend");
    moveEvent(false);
    break;
case "move":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=move");
    moveEvent(true);
    break;
case "update":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=update");
    updateEvent();
    break;
case "delete":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=delete");
    deleteEvent();
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
create table calendar(
    id serial not null primary key,
    title text not null,
    start timestamp without time zone,
    "end" timestamp without time zone,
    classname text,
    allday boolean,
    description text,
    refid text
)';
    $r = pg_query($sql);
    exit(0);
}

function addEvent() {
    $data = array(
        $_REQUEST['title'],
        $_REQUEST['start'],
        $_REQUEST['end'],
        $_REQUEST['classname'],
        is_null($_REQUEST['allday']) ? null : ($_REQUEST['allday'] ? 't' : 'f'),
        $_REQUEST['description']
        );
    $r = pg_query_params("insert into calendar (title, start, \"end\", classname, allday, description) values ($1, $2, $3, $4, $5, $6)", $data);

    listEvent();
}

function listEvents() {
    if (isset($_REQUEST['start']) && isset($_REQUEST['end'])) {
        $sql = "select id, title,
            to_char(start, 'YYYY-MM-DD HH24:MI:SS') as start,
            to_char(\"end\", 'YYYY-MM-DD HH24:MI:SS') as \"end\",
            classname as \"className\", case when allday then 'true' else 'false' end as \"allDay\", description, refid
            from calendar
            where start between $1 and $2 or \"end\" between $1 and $2
            order by id";
        $r = pg_query_params($sql, array($_REQUEST['start'], $_REQUEST['end']));
    }
    else {
        $sql = "select id, title, to_char(start, 'YYYY-MM-DD HH24:MI:SS') as start,
            to_char(\"end\", 'YYYY-MM-DD HH24:MI:SS') as \"end\",
            classname as \"className\", case when allday then 'true' else 'false' end as \"allDay\", description, refid
            from calendar order by id";
        $r = pg_query($sql);
    }
    $result = array();
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }
    pg_free_result($r);
    echo json_encode($result);
}

function listEvent() {
    if (isset($_REQUEST['id']))
        $id = intval($_REQUEST['id']);
    else
        $id = "lastval()";

    $sql = "select id, title, to_char(start, 'YYYY-MM-DD HH24:MI:SS') as start,
        to_char(\"end\", 'YYYY-MM-DD HH24:MI:SS') as \"end\",
        classname as \"className\", case when allday then 'true' else 'false' end as \"allDay\", description, refid
        from calendar where id={$id} ";
    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r)) {
        echo json_encode($row);
    }
    else {
        returnError("Problem fetching event from database!");
    }
    pg_free_result($r);
}

function moveEvent($both) {
    $id = $_REQUEST['id'];
    if (! isset($id))
        returnError("id was not set");
    $y  = intval(isset($_REQUEST['y'])  ? $_REQUEST['y']:  0) . ' years';
    $mo = intval(isset($_REQUEST['mo']) ? $_REQUEST['mo']: 0) . ' months';
    $d  = intval(isset($_REQUEST['d'])  ? $_REQUEST['d']:  0) . ' days';
    $h  = intval(isset($_REQUEST['h'])  ? $_REQUEST['h']:  0) . ' hours';
    $m  = intval(isset($_REQUEST['m'])  ? $_REQUEST['m']:  0) . ' minutes';
    $s  = intval(isset($_REQUEST['s'])  ? $_REQUEST['s']:  0) . ' seconds';
    $id = intval($id);
    $sql = 'update calendar set ';
    if ($both)
        $sql .= "start = start + interval '$y' + interval '$mo' + interval '$d' + interval '$h' + interval '$m' + interval '$s', ";
    $sql .= "\"end\" = \"end\" + interval '$y' + interval '$mo' + interval '$d' + interval '$h' + interval '$m' + interval '$s' where id=$id";
    //echo "$sql\n";
    $r = pg_query($sql);

    listEvent();
}


function updateEvent() {
    $data = array();
    $term = array();
    if (isset($_REQUEST['title'])) {
        if ($_REQUEST['title'] == 'null') {
            array_push($term, 'title=null');
        }
        else {
            array_push($data, $_REQUEST['title']);
            array_push($term, 'title=$' . count($data));
        }
    }
    if (isset($_REQUEST['start'])) {
        if ($_REQUEST['start'] == 'null') {
            array_push($term, 'start=null');
        }
        else {
            array_push($data, $_REQUEST['start']);
            array_push($term, 'start=$' . count($data));
        }
    }
    if (isset($_REQUEST['end'])) {
        if ($_REQUEST['end'] == 'null') {
            array_push($term, '"end"=null');
        }
        else {
            array_push($data, $_REQUEST['end']);
            array_push($term, '"end"=$' . count($data));
        }
    }
    if (isset($_REQUEST['classname'])) {
        if ($_REQUEST['classname'] == 'null') {
            array_push($term, 'classname=null');
        }
        else {
            array_push($data, $_REQUEST['classname']);
            array_push($term, 'classname=$' . count($data));
        }
    }
    if (isset($_REQUEST['allday'])) {
        if ($_REQUEST['allday'] == 'null') {
            array_push($term, 'allday=null');
        }
        else {
            array_push($data, $_REQUEST['allday'] ? 't' : 'f');
            array_push($term, 'allday=$' . count($data));
        }
    }
    if (isset($_REQUEST['description'])) {
        if ($_REQUEST['description'] == 'null') {
            array_push($term, 'description=null');
        }
        else {
            array_push($data, $_REQUEST['description']);
            array_push($term, 'description=$' . count($data));
        }
    }
    array_push($data, $_REQUEST['id']);
    $sql = 'update calendar set ' . implode(", ", $term) . ' where id=$' . count($data);
    //echo "$sql\n";
    $r = pg_query_params($sql, $data);

    listEvent();
}

function deleteEvent() {
    $r = pg_query_params("delete from calendar where id=$1", array($_REQUEST['id']));
    echo '{"status": "OK", "mode": "delete", "id": ' . $_REQUEST['id'] . '}';
}

?>

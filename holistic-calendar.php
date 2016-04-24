<?php
/*
 * mode=schema|add|list|update|delete
 * id=(integer)
 * title=(string)
 * start=(string)
 * end=(string)
 * url=(string)
 * allday=0|1
 * classname=(string)
 *      evt-unknown
 *      evt-social
 *      evt-livestock
 *      evt-paddock
 *      evt-monitor
 *      evt-planning
 *      evt-rotation
 * description=(string)
 * refid=(string) can be a comma separated list of refids
 *      H<num> - reference to a record in herds table
 *      P<num> - reference to a record in the paddock table
 *      E<num> - reference to a record in the paddockevents table
 *      M<num> - reference to a record in the monitor table
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
            classname as \"className\",
            case when allday then 'true' else 'false' end as \"allDay\",
            description, refid, 'S' as type
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

    # fetch events for plan, herds, animals, paddock_exclusions,
    # herd_rotations, and monitoring

    if ( isset($_REQUEST['plan']) && strlen($_REQUEST['plan']) ) {

        # plan ===========================================================

        $params = array();
        if ( isset($_REQUEST['start']) && strlen($_REQUEST['start']) &&
             isset($_REQUEST['end']) && strlen($_REQUEST['start']) ) {
            $where = ' where $1 between start_date and end_date or
                $2 between start_date and end_date and id=$3';
            array_push($params, $_REQUEST['start']);
            array_push($params, $_REQUEST['end']);
        }
        else {
            $where = ' where id=$1';
        }
        array_push($params, $_REQUEST['plan']);

        $sql = 'select id, name, year, start_date as start, end_date as "end"
            from plan' . $where;
        $r = pg_query_params($sql, $params);

        while ($row = pg_fetch_assoc($r)) {
            array_push($result, array(
                'id' => $row['id'],
                'title' => 'Plan: ' . $row['name'] . ' for ' . $row['year'],
                'className' => 'evt-planning',
                'allDay' => 'true',
                'editable' => 'f',
                'type' => 'P',
                'start' => $row['start'],
                'end' => $row['end'],
                'description' => ''
            ));

            // if start or end are not set in the request
            // then set them to the plan start and end dates
            if ( ! isset($_REQUEST['start']) || ! strlen($_REQUEST['start']) ||
                 ! isset($_REQUEST['end']) || ! strlen($_REQUEST['start']) ) {
                $_REQUEST['start'] = $row['start'];
                $_REQUEST['end']   = $row['end'];
            }
        }
        pg_free_result($r);

        # herds ===========================================================

        $sql = "select a.id, a.name,
                to_char(min(b.arrival),  'YYYY-MM-DD HH24:MI:SS') as start,
                to_char(max(b.est_ship), 'YYYY-MM-DD HH24:MI:SS') as \"end\"
            from herds a left outer join animals b on a.id=b.herdid
            where a.plan=$1 group by a.id";
        $r = pg_query_params($sql, array($_REQUEST['plan']));

        while ($row = pg_fetch_assoc($r)) {
            array_push($result, array(
                'id' => $row['id'],
                'title' => 'Herd: ' . $row['name'],
                'className' => 'evt-livestock',
                'allDay' => 'true',
                'editable' => 'f',
                'type' => 'H',
                'start' => $row['start'],
                'end' => $row['end'],
                'description' => 'Herd: ' . $row['name']
            ));
        }
        pg_free_result($r);

        # rotations =======================================================

        $sql = "
        select rid,
               padid,
               name,
               'Herd in paddock \"' || name || '\"' as title,
               case when a.exc_start is not null and
                 (exc_start, exc_end) overlaps (start_date, end_date)
                 then 'evt-exclusion' else 'evt-paddock' end as \"className\",
               true as \"allDay\",
               'f' as editable,
               'R' as type,
               locked,
               start_date as start,
               end_date as \"end\",
               'Herd in paddock \"' || name || '\"' as description
          from paddockPlanningData($1) a
         order by start
        ";
        $r = pg_query_params($sql, array($_REQUEST['plan']));

        $last = '';
        $lastrow = array();
        while ($row = pg_fetch_assoc($r)) {
            array_push($result, $row);

            // create move events
            if ($last == '') {
                $title = 'Move herd into paddock "' . $row['name'] . '"';
                $id2 = -1;
            }
            else {
                $title = 'Move herd from paddock "' . $last . '" to "' .
                    $row['name'] . '"';
                $id2 = $lastrow['rid'];
            }

            $event = array(
                'id' => $row['rid'],
                'id2' => $id2,
                'title' => $title,
                'className' => 'evt-rotation',
                'allDay' => true,
                'editable' => 'f',
                'locked' => $row['locked'],
                'type' => 'R',
                'start' => $row['start'],
                'end' => $row['start'],
                'description' => $title
            );
            array_push($result, $event);
            $last = $row['name'];
            $lastrow = $row;
        }

        // add the final move event
        if ($last != '') {
            $event = array(
                'rid' => $lastrow['rid'],
                'title' => 'Remove herd from paddock "' . $lastrow['name'] . '"',
                'className' => 'evt-rotation',
                'allDay' => true,
                'editable' => 'f',
                'locked' => 'f',
                'type' => 'R',
                'start' => $lastrow['end'],
                'end' => $row['end'],
                'description' => 'Remove herd from paddock "' . $lastrow['name'] . '"'
                );
            array_push($result, $event);
        }

        pg_free_result($r);

        # animals =========================================================

        $sql = "select a.id, a.type, a.qty, 'Animal Arrival: ' as title,
            to_char(arrival, 'YYYY-MM-DD HH24:MI:SS') as s,
            to_char(arrival::timestamp + interval '1 day', 'YYYY-MM-DD HH24:MI:SS') as e
            from animals a, herds b
            where a.herdid=b.id and b.plan=$1 and arrival between $2 and $3
            union all
            select a.id, a.type, a.qty,'Animal Ship: ' as title,
            to_char(est_ship, 'YYYY-MM-DD HH24:MI:SS') as s,
            to_char(est_ship::timestamp + interval '1 day', 'YYYY-MM-DD HH24:MI:SS') as e
            from animals a, herds b
            where a.herdid=b.id and b.plan=$1 and arrival between $2 and $3";
        $r = pg_query_params($sql, array($_REQUEST['plan'], $_REQUEST['start'], $_REQUEST['end']));

        while ($row = pg_fetch_assoc($r)) {
            array_push($result, array(
                'id' => $row['id'],
                'title' => $row['title'] . $row['type'] . '; qty: ' . $row['qty'],
                'className' => 'evt-livestock',
                'allDay' => 'true',
                'editable' => 'f',
                'type' => 'A',
                'start' => $row['s'],
                'end' => $row['e'],
                'description' => $row['title'] . $row['type'] . '; qty: ' . $row['qty']
            ));
        }
        pg_free_result($r);

        # paddock_exclusions ==============================================

        $sql = 'select a.id, b.name, a.reason,
                exc_start as start, exc_end as "end"
            from paddock_exclusions a, paddocks b
            where a.padid=b.id and a.plan=$1 and (
                exc_start between $2 and $3 or
                exc_end between $2 and $3 )';
        $r = pg_query_params($sql, array($_REQUEST['plan'], $_REQUEST['start'], $_REQUEST['end']));

        while ($row = pg_fetch_assoc($r)) {
            array_push($result, array(
                'id' => $row['id'],
                'title' => $row['name'] . " exclusion for " . $row['reason'],
                'className' => 'evt-exclusion',
                'allDay' => 'true',
                'editable' => 'f',
                'type' => 'E',
                'start' => $row['start'],
                'end' => $row['end'],
                'description' => $row['name'] . " exclusion for " . $row['reason']
            ));
        }
        pg_free_result($r);

        # monitoring ======================================================

        $sql = "select p.name, m.id, to_char(mdate, 'YYYY-MM-DD') as mdate,
                        moisture, growth, ada, who
                from monitor m left outer join paddocks p on m.padid=p.id
                where mdate between $1 and $2";
        $r = pg_query_params($sql, array($_REQUEST['start'], $_REQUEST['end']));

        $moisture = array('Unknown','Dusty','Dry','Moist','Wet','Soggy');
        $growth = array('Unknown','None','Slow','Medium','Fast');
        while ($row = pg_fetch_assoc($r)) {
            $idxm = intVal($row['moisture']);
            $idxm = ($idxm<0 || $idxm>5)? 0 : $idxm;
            $idxg = intVal($row['growth']);
            $idxg = ($idxg<0 || $idxg>3)? 0 : $idxg+1;
            array_push($result, array(
                'id' => $row['id'],
                'title' => $row['name'] . " Monitored",
                'className' => 'evt-monitor',
                'allDay' => 'true',
                'editable' => 'f',
                'type' => 'M',
                'start' => $row['mdate'],
                'end' => $row['mdate'],
                'description' => $row['name'] . " monitored by " . $row['who'] .
                    ". Est. ADA: " . $row['ada'] .
                    ", Moisture: " . $moisture[$idxm] .
                    ", Growth: " . $growth[$idxg]
            ));
        }
        pg_free_result($r);

    }

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

    # check if we need to update other tables

    $refids = explode(',', $_REQUEST['refid']);
    $table = '';
    $tid = '';
    foreach ($refids as $x) {
        $matches = array();
        if (preg_match('/^([HE])(\d+)$/', $x, $matches)) {
            if ($table == '' or ($table == 'H' and $matches[1] == 'E')) {
                $table = $matches[1];
                $tid   = intval($matches[2]);
            }
        }
    }

    $r = pg_query( "begin" );

    $sql = 'update calendar set ';
    if ($both)
        $sql .= "start = start + interval '$y' + interval '$mo' + interval '$d' + interval '$h' + interval '$m' + interval '$s', ";
    $sql .= "\"end\" = \"end\" + interval '$y' + interval '$mo' + interval '$d' + interval '$h' + interval '$m' + interval '$s' where id=$id";
    $r = pg_query($sql);

    switch ($table) {
        case "H":
            $sql = "update herds set ";
            if ($both)
                $sql .= "arrival = arrival + interval '$y' + interval '$mo' + interval '$d' + interval '$h' + interval '$m' + interval '$s', ";
            $sql .= "est_ship = est_ship + interval '$y' + interval '$mo' + interval '$d' + interval '$h' + interval '$m' + interval '$s' where id={$tid}";
            $r = pg_query($sql);
            break;
        case "E":
            $sql = "update paddockevents set ";
            if ($both)
                $sql .= "start_date = start_date + interval '$y' + interval '$mo' + interval '$d' + interval '$h' + interval '$m' + interval '$s', ";
            $sql .= "end_date = end_date + interval '$y' + interval '$mo' + interval '$d' + interval '$h' + interval '$m' + interval '$s' where id={$tid}";
            $r = pg_query($sql);
            break;
    }

    $r = pg_query( "commit" );

    listEvent();
}


function updateEvent() {
    $data = array();
    $term = array();
    $data2 = array();
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
            array_push($data2, array( 'start' => 'null' ));
        }
        else {
            array_push($data, $_REQUEST['start']);
            array_push($term, 'start=$' . count($data));
            array_push($data2, array( 'start' => $_REQUEST['start']));
        }
    }
    if (isset($_REQUEST['end'])) {
        if ($_REQUEST['end'] == 'null') {
            array_push($term, '"end"=null');
            array_push($data2, array( 'start' => 'null' ));
        }
        else {
            array_push($data, $_REQUEST['end']);
            array_push($term, '"end"=$' . count($data));
            array_push($data2, array( 'end' => $_REQUEST['end']));
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

    # check if we need to update other tables
    $refids = explode(',', $_REQUEST['refid']);
    $table = '';
    $tid = '';
    foreach ($refids as $x) {
        $matches = array();
        if (preg_match('/^([HE])(\d+)$/', $x, $matches)) {
            if ($table == '' or ($table == 'H' and $matches[1] == 'E')) {
                $table = $matches[1];
                $tid   = intval($matches[2]);
            }
        }
    }

    $r = pg_query( "begin" );

    $r = pg_query_params($sql, $data);

    $data3 = array();
    $term3 = array();
    switch ($table) {
        case "H":
            if (isset($data2['start'])) {
                if ($data2['start'] == 'null')
                    array_push($term3, "arrival=null");
                else {
                    array_push($data3, $data2['start']);
                    array_push($term3, 'arrival=$' . count($data3));
                }
            }
            if (isset($data2['end'])) {
                if ($data2['end'] == 'null')
                    array_push($term3, "est_ship=null");
                else {
                    array_push($data3, $data2['end']);
                    array_push($term3, 'est_ship=$' . count($data3));
                }
            }
            array_push($data3, $tid);
            $sql = "update herds set " . implode(", ", $term3) . ' where id=$' . count($data3);
            $r = pg_query($sql);
            break;
        case "E":
            if (isset($data2['start'])) {
                if ($data2['start'] == 'null')
                    array_push($term3, "start_date=null");
                else {
                    array_push($data3, $data2['start']);
                    array_push($term3, 'start_date=$' . count($data3));
                }
            }
            if (isset($data2['end'])) {
                if ($data2['end'] == 'null')
                    array_push($term3, "end_date=null");
                else {
                    array_push($data3, $data2['end']);
                    array_push($term3, 'end_date=$' . count($data3));
                }
            }
            array_push($data3, $tid);
            $sql = "update paddockevents set " . implode(", ", $term3) . ' where id=$' . count($data3);
            $r = pg_query($sql);
            break;
    }

    $r = pg_query( "commit" );

    listEvent();
}

function deleteEvent() {
    $r = pg_query_params("select refid from calendar where id=$1", array($_REQUEST['id']));
    $row = pg_fetch_row($r);
    if ($row and strlen($row[0])) {
        pg_free_result($r);
        returnError("This event was added to the calendar via another tab. Please delete it from that tab.");
    }
    $r = pg_query_params("delete from calendar where id=$1", array($_REQUEST['id']));
    echo '{"status": "OK", "mode": "delete", "id": ' . $_REQUEST['id'] . '}';
}

?>

<?php
/*



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
    addPlan();
    break;
case "list":
case "":
    if (isset($_REQUEST['id']))
        listPlan();
    else
        listPlans();
    break;
case "update":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=update");
    updatePlan();
    break;
case "delete":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=delete");
    deletePlan();
    break;
case "check":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=check");
    checkPlan();
    break;
case "setdefgd":
    if (! isset($_REQUEST['plan']))
        returnError("plan must be set for mode=setdefgd");
    if (! isset($_REQUEST['defgd']))
        returnError("defgd must be set for mode=setdefgd");
    setdefgdPlan();
    break;
case "bugs":
    bugsPlan();
    break;
case "cal":
    if (! isset($_REQUEST['plan'])) {
        echo json_encode(array());
        exit;
    }
    calendar();
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
create table plan (
    id serial not null primary key,
    name text not null,
    year integer not null,
    ptype integer not null,
    start_date timestamp without time zone not null,
    end_date timestamp without time zone not null,
    factors text,
    steps integer[]
)';
    $r = pg_query($sql);

    $sql = 'create table bugs(
        id integer not null primary key,
        data text )';
    $r = pg_query($sql);
    $r = pg_query("insert into bugs values(1,''::text)");

    exit(0);
}


function addPlan() {
    $errs = "";
    if (! strlen($_REQUEST['name']))  $errs .= "name is a required field\\n";
    if (! strlen($_REQUEST['year']))  $errs .= "year is a required field\\n";
    if (! strlen($_REQUEST['ptype'])) $errs .= "ptype is a required field\\n";
    if (! strlen($_REQUEST['start'])) $errs .= "start is a required field\\n";
    if (! strlen($_REQUEST['end']))   $errs .= "end is a required field\\n";
    if (strlen($errs)) returnError($errs);

    $data = array(
        $_REQUEST['name'],
        $_REQUEST['year'],
        $_REQUEST['ptype'],
        $_REQUEST['start'],
        $_REQUEST['end'],
        $_REQUEST['factors']
    );

    $sql = "insert into plan (name, year, ptype, start_date, end_date, factors) values ($1, $2, $3, $4, $5, nullif($6, ''))";

    $r = pg_query_params($sql, $data);

    $r = pg_query("select lastval() as id");
    $row = pg_fetch_row($r);
    if (!$row) {
        pg_free_result($r);
        returnError("Problem adding plan to database!");
    }
    $_REQUEST['id'] = $row[0];
    pg_free_result($r);

    listPlan();
}


function listPlan() {
    if (isset($_REQUEST['id']))
        $id = intval($_REQUEST['id']);
    else
        $id = "lastval()";

    # if id == 0 then fetch the newest plan
    if ($id == 0)
        $sql = "select * from plan order by id desc limit 1";
    else
        $sql = "select * from plan where id={$id}";

    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r)) {
        echo json_encode($row);
    }
    else {
        if ($id == 0) {
            echo json_encode(array('data' => array()));
        }
        else {
            pg_free_result($r);
            returnError("Problem fetching plan from database!");
        }
    }
    pg_free_result($r);
}


function listPlans() {
    $sql = "select * from plan order by id desc";
    $r = pg_query($sql);
    $result = array();
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }
    echo json_encode(array( 'data' => $result));
    pg_free_result($r);
}


function updatePlan() {
    $data = array();
    $term = array();
    if (isset($_REQUEST['name'])) {
        array_push($data, $_REQUEST['name']);
        array_push($term, 'name=$' . count($data));
    }
    if (isset($_REQUEST['year'])) {
        array_push($data, $_REQUEST['year']);
        array_push($term, 'year=$' . count($data));
    }
    if (isset($_REQUEST['ptype'])) {
        array_push($data, $_REQUEST['ptype']);
        array_push($term, 'ptype=$' . count($data));
    }
    if (isset($_REQUEST['start'])) {
        array_push($data, $_REQUEST['start']);
        array_push($term, 'start_date=$' . count($data));
    }
    if (isset($_REQUEST['end'])) {
        array_push($data, $_REQUEST['end']);
        array_push($term, 'end_date=$' . count($data));
    }
    if (isset($_REQUEST['factors'])) {
        if ($_REQUEST['factors'] == 'null') {
            array_push($term, 'factors=null');
        }
        else {
            array_push($data, $_REQUEST['factors']);
            array_push($term, 'factors=$' . count($data));
        }
    }
    if (isset($_REQUEST['steps'])) {
        if ($_REQUEST['steps'] == 'null') {
            array_push($term, 'steps=null');
        }
        else {
            $steps = explode(',', $_REQUEST['steps']);
            for($i=0; $i<count($steps); $i++) {
                $steps[$i] = intval($steps[$i]);
            }
            array_push($term, 'steps=array[' . implode(',', $steps) . ']');
        }
    }

    array_push($data, $_REQUEST['id']);
    $sql = 'update plan set ' . implode(", ", $term) . ' where id=$' . count($data);
    $r = pg_query_params($sql, $data);

    listPlan();
}


function deletePlan() {

    returnError("Delete Plan is not implemented yet.");

    $r = pg_query("begin");
    $r = pg_query_params("delete from plan where id=$1",
        array($_REQUEST['id']));
    $r = pg_query("commit");
    echo '{"status": "OK", "mode": "delete", "id": ' . $_REQUEST['id'] . '}';
}


function checkPlan() {
    $sql = '
select coalesce(array_agg(b.id)::integer[], array[]::integer[]) as ids
  from plan a, plan b
 where a.id=$1 and b.id!=$1 and (
     b.start_date between a.start_date and a.end_date
     or b.end_date between a.start_date and a.end_date)
';
    $r = pg_query_params($sql, array($_REQUEST['id']));
    if ($row = pg_fetch_assoc($r)) {
        echo json_encode($row);
    }
    else {
        pg_free_result($r);
        returnError("Problem fetching plan from database!");
    }
    pg_free_result($r);
}


function bugsPlan() {
    if (! isset($_REQUEST['text']) || ! strlen($_REQUEST['text'])) {
        $r = pg_query("select data from bugs where id=1");
        if ($row = pg_fetch_assoc($r)) {
            echo json_encode(array('bugs' => $row['data']));
            pg_free_result($r);
        }
        else {
            echo json_encode(array('bugs' => ''));
        }
    }
    else {
        $r = pg_query_params("update bugs set data=$1 where id=1",
            array($_REQUEST['text']));
        echo json_encode(array('bugs' => $_REQUEST['text']));
    }
}

function calendar() {
    $params = array();
    if ( isset($_REQUEST['start']) && strlen($_REQUEST['start']) &&
         isset($_REQUEST['end']) && strlen($_REQUEST['end']) ) {
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

    $result = array();
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, array(
            'id' => $row['id'],
            'title' => 'Plan: ' . $row['name'] . ' for ' . $row['year'],
            'className' => 'evt-planning',
            'allDay' => 'true',
            'type' => 'P',
            'start' => $row['start'],
            'end' => $row['end'],
            'description' => ''
        ));
    }
    echo json_encode($result);
    pg_free_result($r);
}

function setdefgdPlan() {
    $plan   = intVal($_REQUEST['plan']);
    $defgd  = intVal($_REQUEST['defgd']);
    $params = array($plan, $defgd);
    $sql = "update plan set defgd=$2 where id=$1";
    $r = pg_query_params($sql, $params);
    echo '{"status": "OK", "mode": "setdefgd"}';
}


?>

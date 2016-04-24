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
    addHerd();
    break;
case "list":
case "":
    if (isset($_REQUEST['id']))
        listHerd();
    else
        listHerds();
    break;
case "update":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=update");
    updateHerd();
    break;
case "delete":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=delete");
    deleteHerd();
    break;
case "monthly":
    if (! isset($_REQUEST['plan']) || ! strlen($_REQUEST['plan'])) {
        echo json_encode(array( 'data' => array()));
        exit;
    }
    monthlyHerd();
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
create table herds(
    id serial not null primary key,
    plan integer not null,
    name text not null
)';
    $r = pg_query($sql);
    exit(0);
}

function addHerd() {
    if (! strlen($_REQUEST['name'])) {
        returnError("name is a required field");
    }
    if (! strlen($_REQUEST['plan'])) {
        returnError("plan is a required field");
    }
    $data = array(
        $_REQUEST['name'],
        $_REQUEST['plan']
    );

    $r = pg_query("begin");

    $r = pg_query_params("insert into herds (name, plan) values ($1, $2)", $data);

    $r = pg_query("select lastval() as id");
    $row = pg_fetch_row($r);
    if (!$row) {
        $r = pg_query("rollback");
        returnError("Problem adding herd to database!");
    }
    $_REQUEST['id'] = $row[0];
    pg_free_result($r);

/*
    $data = array(
        "Herd: '" . $_REQUEST['name'] . "'",
        $_REQUEST['arrival'],
        $_REQUEST['est_ship'],
        "evt-livestock",
        't',
        "Herd: '" . $_REQUEST['name'] . "' entered into system.",
        "H".$_REQUEST['id']
    );

    $r = pg_query_params("insert into calendar (title, start, \"end\", classname, allday, description, refid) values ($1, $2, $3, $4, $5, $6, $7)", $data);
*/
    $r = pg_query("commit");

    listHerd();
}

function listHerd() {
    if (isset($_REQUEST['id']))
        $id = intval($_REQUEST['id']);
    else
        $id = "lastval()";

    $sql = "select a.id, a.name,
        coalesce(sum(b.qty*b.sau), 0.0) as sau,
        coalesce(sum(b.qty*b.weight*b.forage/100.0), 0.0) as intake,
        to_char(min(b.arrival),  'YYYY-MM-DD HH24:MI:SS') as arrival,
        to_char(max(b.est_ship), 'YYYY-MM-DD HH24:MI:SS') as est_ship
        from herds a left outer join animals b on a.id=b.herdid where a.id={$id}
        group by a.id";
    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r)) {
        echo json_encode($row);
    }
    else {
        returnError("Problem fetching herd from database!");
    }
    pg_free_result($r);
}

function listHerds() {
    if ($_REQUEST['plan'] == "null") {
        echo json_encode(array( 'data' => array()));
        return;
    }
    $sql = "select a.id, a.name,
        coalesce(sum(b.qty*b.sau), 0.0) as sau,
        coalesce(sum(b.qty*b.weight*b.forage/100.0), 0.0) as intake,
        to_char(min(b.arrival),  'YYYY-MM-DD HH24:MI:SS') as arrival,
        to_char(max(b.est_ship), 'YYYY-MM-DD HH24:MI:SS') as est_ship
        from herds a left outer join animals b on a.id=b.herdid
        where a.plan=$1
        group by a.id
        order by a.id ";
    $result = array();
    $r = pg_query_params($sql, array($_REQUEST['plan']));
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }
    echo json_encode(array( 'data' => $result));
    pg_free_result($r);
}

function updateHerd() {
    $data = array();
    $term = array();
    if (isset($_REQUEST['name'])) {
        if ($_REQUEST['name'] == 'null') {
            array_push($term, 'name=null');
        }
        else {
            array_push($data, $_REQUEST['name']);
            array_push($term, 'name=$' . count($data));
        }
    }
    array_push($data, $_REQUEST['id']);
    $sql = 'update herds set ' . implode(", ", $term) . ' where id=$' . count($data);
    //echo "$sql\n";
    $r = pg_query_params($sql, $data);

    listHerd();

}

function deleteHerd() {
    $r = pg_query("begin");
    $r = pg_query_params("delete from herds where id=$1",
         array($_REQUEST['id']));
    $refid = "H" . $_REQUEST['id'];
    $r = pg_query_params("delete from calendar where refid=$1", array($refid));
    $r = pg_query_params("delete from animals where herdid=$1", array($id));
    $r = pg_query("commit");
    echo '{"status": "OK", "mode": "delete", "id": ' . $_REQUEST['id'] . '}';
}


function monthlyHerd() {
    $mm = array('jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec');
    $sql = "select * from monthlySAU($1::integer)";
    $r = pg_query_params($sql, array(intval($_REQUEST['plan'])));
    $result = array();
    while ($row = pg_fetch_assoc($r)) {
        $rr = array();
        $rr['herdid'] = $row['herdid'];
        $rr['name']  = $row['name'];
        $m = preg_replace('/[{}]/', '', $row['totsau']);
        $m = preg_split("/,/", $m);
        for ($i=0; $i<12; $i++)
            $rr[$mm[$i]] = $m[$i];
        array_push($result, $rr);
    }
    echo json_encode(array( 'data' => $result));
    pg_free_result($r);
}

?>

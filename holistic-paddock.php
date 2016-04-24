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
    addPaddock();
    break;

case "list":
case "":
    if (isset($_REQUEST['id']))
        listPaddock();
    else
        listPaddocks();
    break;

case "list2":
    listPaddocks2();
    break;

case "list3":
    listPaddocks3();
    break;

case "update":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=update");
    updatePaddock();
    break;

case "delete":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=delete");
    deletePaddock();
    break;

case "avail":
    if (! isset($_REQUEST['plan']) || ! strlen($_REQUEST['plan'])) {
        echo json_encode(array( 'data' => array()));
        exit;
    }
    availablePaddocks();
    break;

case "summary":
    summarizePaddocks();
    break;

#------------------- plan_paddock editing ----------------
case "ppset":
    if (! isset($_REQUEST['plan']))
        returnError("plan must be set for mode=ppset");
    if (! isset($_REQUEST['ids']))
        returnError("ids must be set for mode=ppset");
    ppSet2();
    break;

case "pplist";
    if (! isset($_REQUEST['plan']) || ! strlen($_REQUEST['plan'])
            || $_REQUEST['plan'] == 'null') {
        echo json_encode(array( 'data' => array()));
        exit;
    }
    ppList();
    break;

case "ppupdate":
    if (! isset($_REQUEST['plan']))
        returnError("plan must be set for mode=ppupdate");
    ppUpdate();
    break;

#------------------ plan_recovery table ---------------------

case "prlist":
    if (! isset($_REQUEST['plan']) || ! strlen($_REQUEST['plan'])
            || $_REQUEST['plan'] == 'null') {
        echo json_encode(array( 'data' => array()));
        exit;
    }
    prList();
    break;

case "prupdate":
    if (! isset($_REQUEST['plan']))
        returnError("plan must be set for mode=ppupdate");
    prUpdate();
    break;

case "listgraz":
    if (! isset($_REQUEST['plan']))
        returnError("plan must be set for mode=ppupdate");
    listGrazing();
    break;

#-------------- paddock grazing-pattern table ----------------

case "gpattern":
    listGrazingPattern();
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
    $r = pg_query("begin");

    $sql = '
create table paddocks(
    id serial not null primary key,
    name text not null,
    area float,
    atype text,
    crop text,
    description text
)';
    $r = pg_query($sql);

    $sql = '
create table paddock_geom(
    id integer not null primary key,
    geom geometry(MultiPolygon,4326)
)';
    $r = pg_query($sql);

    $sql = '
create table plan_paddock (
    pid integer not null,
    pad integer not null,
    qual real not null default 0.0,
    primary key (pid, pad)
)';
    $r = pg_query($sql);

    $sql = '
create table plan_recovery(
    plan integer not null,
    month integer not null,
    minrecov integer not null default 0,
    maxrecov integer not null default 0,
    primary key (plan, month)
)';
    $r = pg_query($sql);

    $r = pg_query("commit");

    exit(0);
}


function addPaddock() {
    if (! strlen($_REQUEST['name'])) returnError("name is a required field");
    if (! strlen($_REQUEST['area'])) returnError("area is a required field");
    #if (! strlen($_REQUEST['ada'])) returnError("animal days per acre is a required field");
    #if (! strlen($_REQUEST['recoverymin'])) returnError("recoverymin days is a required field");
    #if (! strlen($_REQUEST['recoverymax'])) returnError("recoverymax days is a required field");

    $data = array(
        $_REQUEST['name'],
        $_REQUEST['area'],
        $_REQUEST['atype'],
     #   $_REQUEST['ada'],
     #   strlen($_REQUEST['recoverymin'])  ? $_REQUEST['recoverymin'] : null,
     #   strlen($_REQUEST['recoverymax']) ? $_REQUEST['recoverymax']  : null,
        strlen($_REQUEST['crop'])        ? $_REQUEST['crop']         : null,
        strlen($_REQUEST['description']) ? $_REQUEST['description']  : null
    );

    $r = pg_query_params("insert into paddocks (name, area, atype, crop, description) values ($1, $2, $3, $4, $5)", $data);

    listPaddock();
}

function listPaddock() {
    if (isset($_REQUEST['id']))
        $id = intval($_REQUEST['id']);
    else
        $id = "lastval()";

    $sql = "select id, name, round(area::numeric, 2) as area, atype, crop, description
        from paddocks where id={$id}";
    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r)) {
        echo json_encode($row);
        pg_free_result($r);
    }
    else {
        pg_free_result($r);
        returnError("Problem fetching paddock from database!");
    }
}

function listPaddocks() {
    $sql = "select id, name, round(area::numeric, 2) as area, atype, crop, description
        from paddocks order by id";
    $result = array();
    $r = pg_query($sql);
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }
    pg_free_result($r);
    echo json_encode(array( 'data' => $result));
}

function listPaddocks2() {
    $sql = "select a.id, a.name, round(a.area::numeric, 2) as area, c.name as planname, c.id as planid
    from paddocks a
         left outer join plan_paddock b on a.id=b.pad
         left outer join plan c on b.pid=c.id
    order by a.id";
    $result = array();
    $r = pg_query($sql);
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }
    pg_free_result($r);
    echo json_encode(array( 'data' => $result));
}

function listPaddocks3() {
    if (! isset($_REQUEST['plan']) or ! strlen($_REQUEST['plan']))
        returnError("plan must be set for list3!");
    else
        $plan = intval($_REQUEST['plan']);

    $sql = "select a.id as planid, a.name as planname, b.id,
                   b.name as name, round(b.area::numeric, 2) as area,
                   sum(case when c.plan is null then 0 else 1 end)::integer as cnt
             from plan a
                  join paddocks b on true
                  left outer join herd_rotations c on a.id=c.plan and c.padid=b.id
            where a.id=$1
            group by a.id, a.name, b.id, b.name, round(b.area::numeric, 2)
            order by a.name";
    $result = array();
    $r = pg_query_params($sql, array($plan));
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }
    pg_free_result($r);
    echo json_encode(array( 'data' => $result));
}


function updatePaddock() {
    if (! isset($_REQUEST['id']) or ! strlen($_REQUEST['id']))
        returnError("id must be set for updates!");

    $data = array();
    $term = array();
    if (isset($_REQUEST['name']) && strlen($_REQUEST['name']) &&
            $_REQUEST['name'] != 'null') {
        array_push($data, $_REQUEST['name']);
        array_push($term, 'name=$' . count($data));
    }
    if (isset($_REQUEST['area']) && strlen($_REQUEST['area']) &&
            $_REQUEST['area'] != 'null') {
        array_push($data, $_REQUEST['area']);
        array_push($term, 'area=$' . count($data));
    }
    if (isset($_REQUEST['atype']) && strlen($_REQUEST['atype']) &&
            $_REQUEST['atype'] != 'null') {
        array_push($data, $_REQUEST['atype']);
        array_push($term, 'atype=$' . count($data));
    }
    if (isset($_REQUEST['crop'])) {
        if ( $_REQUEST['crop'] == 'null' or ! strlen($_REQUEST['crop'])) {
            array_push($term, 'crop=null');
        }
        else {
            array_push($data, $_REQUEST['crop']);
            array_push($term, 'crop=$' . count($data));
        }
    }
    if (isset($_REQUEST['description'])) {
        if ($_REQUEST['description'] == 'null' or
                ! strlen($_REQUEST['description'])) {
            array_push($term, 'description=null');
        }
        else {
            array_push($data, $_REQUEST['description']);
            array_push($term, 'description=$' . count($data));
        }
    }
    array_push($data, $_REQUEST['id']);
    $sql = 'update paddocks set ' . implode(", ", $term) . ' where id=$' . count($data);
    $r = pg_query_params($sql, $data);

    listPaddock();
}

function deletePaddock() {
    $r = pg_query_params("delete from paddocks where id=$1",
        array($_REQUEST['id']));
    echo '{"status": "OK", "mode": "delete", "id": ' . $_REQUEST['id'] . '}';
}

function availablePaddocks() {
    $sql = "select * from paddockAvailability($1::integer)";
    $mm = array('jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec');
    $r = pg_query_params($sql, array(intval($_REQUEST['plan'])));
    $result = array();
    while ($row = pg_fetch_assoc($r)) {
        $rr = array();
        $rr['padid'] = $row['padid'];
        $rr['name']  = $row['name'];
        $rr['productivity'] = 0;
        $rr['forage'] = 0;
        $m = preg_replace('/[{}]/', '', $row['omonths']);
        $m = preg_split("/,/", $m);
        for ($i=0; $i<12; $i++)
            $rr[$mm[$i]] = $m[$i];
        array_push($result, $rr);
    }
    echo json_encode(array( 'data' => $result));
    pg_free_result($r);
}


function summarizePaddocks() {
    $sql = "select sum(area) as area from paddocks";
    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r)) {
        echo json_encode($row);
        pg_free_result($r);
    }
    else {
        pg_free_result($r);
        returnError("Problem fetching paddocks from database!");
    }
}


#-------------------------------------------------------------------------

function ppSet() {
    $ids = $_REQUEST['ids'];
    if (preg_match('/[^\d,]/', $ids)) {
        returnError("invalid characters passed ids for mode=ppset");
        exit;
    }

    $r = pg_query("begin");

    $sql = "
delete from plan_paddock where pid=$1 and pad in (
select pad from plan_paddock
except
select unnest('{".$ids."}'::integer[]) 
)";
    $r = pg_query_params($sql, array($_REQUEST['plan']));

    $sql = "
insert into plan_paddock (pid, pad, qual)
select $1, pad, 0.0 from (
select unnest('{".$ids."}'::integer[]) as pad
except
select pad from plan_paddock where pid=$1
) as foo";
    $r = pg_query_params($sql, array($_REQUEST['plan']));

    $r = pg_query("commit");

    echo '{"status": "OK", "mode": "ppset", "plan": ' . $_REQUEST['plan'] . '}';
}


function ppSet2() {
    $ids = $_REQUEST['ids'];
    if (preg_match('/[^\d,@]/', $ids)) {
        returnError("invalid characters passed ids for mode=ppset");
        exit;
    }

    $plan = intval($_REQUEST['plan']);

    $r = pg_query("begin");

    $ids = explode(',', $ids);
    $allids = array();
    for ($i=0; $i<count($ids); $i++) {
        list($pad, $cnt) = explode('@', $ids[$i]);
        array_push($allids, intVal($pad));

        $sql = "select id from herd_rotations where plan=$1 and padid=$2 order by id desc";
        $r = pg_query_params($sql, array($plan, $pad));
        $rids = array();
        while ($row = pg_fetch_assoc($r)) {
            array_push($rids, $row['id']);
        }
        pg_free_result($r);

        if ($cnt > count($rids)) {
            $sql = "insert into herd_rotations (plan, padid, plan_quality) values($1, $2, 0.0)";
            while ($cnt > count($rids)) {
                $r = pg_query_params($sql, array($plan, $pad));
                array_push($rids, -1);
            }
        }
        else if ($cnt < count($rids)) {
            $sql = "delete from herd_rotations where id=$1";
            for ($j=0; $j<count($rids)-$cnt; $j++) {
                $r = pg_query_params($sql, array($rids[$j]));
            }
        }
    }

    if (count($allids)) {
        $sql = "delete from herd_rotations where padid not in (" .
                implode(',', $allids) . ") and plan={$plan} ";
    }
    else {
        $sql = "delete from herd_rotations where plan={$plan} ";
    }
    $r = pg_query($sql);


    $r = pg_query("commit");

    echo '{"status": "OK", "mode": "ppset", "plan": ' . $_REQUEST['plan'] . '}';
}


function ppList() {
    $sql = "select a.id, a.padid, a.plan_quality as qual,
                   round((a.plan_quality*b.area)::numeric, 1) as days, b.name
              from herd_rotations a, paddocks b 
             where plan=$1 and a.padid=b.id
             order by a.id";
    $r = pg_query_params($sql, array($_REQUEST['plan']));
    $results = array();
    while ($row = pg_fetch_assoc($r)) {
        array_push($results, $row);
    }

    echo json_encode(array( 'data' => $results));
    pg_free_result($r);
}


function ppUpdate() {
    $keys = preg_grep('/^pqr_/', array_keys($_REQUEST));
    $r = pg_query("begin");

    $sql = 'update herd_rotations set plan_quality=$1 where id=$2';

    reset($keys);
    foreach($keys as $idx => $key) {
        $qual = $_REQUEST[$key];
        $id = preg_replace('/^pqr_/', '', $key);
        $r = pg_query_params($sql, array($qual, $id));
    }

    $r = pg_query("commit");

    echo '{"status": "OK", "mode": "ppupdate", "plan": ' . $_REQUEST['plan'] . '}';
}


function prList() {
    $sql = "select a.plan, a.month, a.mname, b.minrecov, b.maxrecov
        from (select $1 as plan, * from monthsInPlan($1)) a
        left outer join plan_recovery b on a.month=b.month and a.plan=b.plan";

    $results = array();
    $r = pg_query_params($sql, array($_REQUEST['plan']));
    while ($row = pg_fetch_assoc($r)) {
        array_push($results, $row);
    }

    echo json_encode(array( 'data' => $results));
    pg_free_result($r);
}

#
#  $data = array(
#       'month' => array(minrecov, maxrecov, month, plan),
#       ...
#  );

function prUpdate() {
    $keys = preg_grep('/^rm.._/', array_keys($_REQUEST));
    $data = array();
    reset($keys);
    foreach($keys as $idx => $key) {
        $val = $_REQUEST[$key];
        $mon = preg_replace('/^rm.._/', '', $key);
        if (! isset($data[$mon]))
            $data[$mon] = array(0,0,$mon, $_REQUEST['plan']);
        if (preg_match('/^rmin/', $key))
            $data[$mon][0] = $val;
        else
            $data[$mon][1] = $val;
    }

    $sql = "insert into plan_recovery (minrecov, maxrecov, month, plan) values ($1, $2, $3, $4)";

    $r = pg_query("begin");

    # delete the old records
    $r = pg_query_params("delete from plan_recovery where plan=$1",
            array($_REQUEST['plan']));

    # add the new records
    reset($data);
    foreach($data as $mon => $d) {
        $r = pg_query_params($sql, $d);
    }

    $r = pg_query("commit");
    echo '{"status": "OK", "mode": "prupdate", "plan": ' . $_REQUEST['plan'] . '}';
}


function listGrazing() {
    $sql = 'select * from actGrazingDays($1)';
    $plan = intval($_REQUEST['plan']);

    $results = array();
    $r = pg_query_params($sql, array($plan));

    while ($row = pg_fetch_assoc($r)) {
        array_push($results, $row);
    }

    echo json_encode(array( 'data' => $results));
    pg_free_result($r);
}

#-------------- paddock grazing-pattern table ----------------

function listGrazingPattern() {
    $mm = array('jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec');
    $params = array();
    if (isset($_REQUEST['years']) && strlen($_REQUEST['years']))
        $yrs = intval($_REQUEST['years']);
    else
        $yrs = 3;
    array_push($params, $yrs);

    if (isset($_REQUEST['plan']) && strlen($_REQUEST['plan'])) {
        $sql = 'select a.* from countUsageByMonth($1) a, plan_paddock b
         where a.padid=b.pad and pid=$2';
        array_push($params, intval($_REQUEST['plan']));
    }
    else
        $sql = 'select a.* from countUsageByMonth($1) a';

    $results = array();
    $r = pg_query_params($sql, $params);

    while ($row = pg_fetch_assoc($r)) {
        $rr = array();
        $rr['padid'] = $row['padid'];
        $rr['name']  = $row['name'];
        $rr['total']  = $row['total'];
        $m = preg_replace('/[{}]/', '', $row['counts']);
        $m = preg_split("/,/", $m);
        for ($i=0; $i<12; $i++)
            $rr[$mm[$i]] = $m[$i] ? ''.$m[$i] : '';
        array_push($results, $rr);
    }

    echo json_encode(array( 'data' => $results));
    pg_free_result($r);
}



?>

<?php
/*



*/

require_once "class/config.php";

function returnError($str) {
    echo "{\"status\": \"ERROR\", \"error\": \"$str\"}";
    exit(1);
}

function debugPrint($head, $data) {
    global $argv;
    if (isset($argv)) {
        echo "$head\n";
        print_r($data);
    }
}


// parse commandline arguments
if (isset($argv))
    parse_str(implode('&', array_slice($argv, 1)), $_REQUEST);
$_REQUEST = array_change_key_case($_REQUEST);
if (! isset($_REQUEST['mode'])) $_REQUEST['mode'] = '';

if (isset($argv)) {
    echo "------- args ---------\n";
    print_r($_REQUEST);
}

$db = new Database();

switch (strtolower($_REQUEST['mode'])) {

#case "schema":
#    createSchema();
#    break;

case "list2":
    newlistPaddock();
    break;

case "list":
case "":
    if (! isset($_REQUEST['plan']))
        returnError("plan must be set for mode=update");
    listPaddock();
    break;

case "update":
    if (! isset($_REQUEST['plan']))
        returnError("plan must be set for mode=update");
    updatePaddock();
    break;

case "actual":
    setActual();
    break;

case "updategd":
    if (! isset($_REQUEST['rid']) || ! strlen($_REQUEST['rid']))
        returnError("rid must be set for mode=updategd");

    updateGD();
    break;

default:
    returnError("invalid mode: '" . $_REQUEST['mode'] . "'");
    break;
}
exit(0);


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

    $r = pg_query("commit");

    exit(0);
}

function checkPlanRotations($plan, $rot) {
    global $db;



    return false; // no issues
}

function setActual() {
    global $db;

    if (isset($_REQUEST['plan']) && strlen($_REQUEST['plan']))
        $plan = intval($_REQUEST['plan']);
    else
        returnError("plan must be set for mode actual");

    if (isset($_REQUEST['rid']) && strlen($_REQUEST['rid']))
        $rid = intval($_REQUEST['rid']);
    else
        returnError("rid must be set for mode actual");

    if (isset($_REQUEST['rid2']) && strlen($_REQUEST['rid2']))
        $rid2 = intval($_REQUEST['rid2']);
    else
        $rid2 = -1;

    $data = array(
        $_REQUEST['adate'],
        $_REQUEST['comments'],
        $_REQUEST['error'] == 'true'?'t':'f',
        $_REQUEST['forage'],
        $_REQUEST['growth'],
        $plan,
        $rid
        );

    $db->query('begin');

    $sql = "update herd_rotations set
        act_start = nullif($1, '')::timestamp,
        notes = $2,
        act_error = $3,
        act_forage_taken = $4,
        act_growth_rate = $5
    where plan = $6 and id = $7";

    $db->query($sql, $data);

    if ($rid2 > 0) {

        $data = array(
            $_REQUEST['adate'],
            $plan,
            $rid2
            );

        $sql = "update herd_rotations set act_end = nullif($1, '')::timestamp
            where plan = $2 and id = $3";

        $db->query($sql, $data);
    }

    $db->query('commit');

    echo '{"status": "OK", "mode": "actual", "rid": ' . $_REQUEST['rid'] . '}';
}

function listPaddock() {
    global $db;

    # fetch the plan
    if (isset($_REQUEST['plan']) && strlen($_REQUEST['plan']))
        $plan = intval($_REQUEST['plan']);

#    $tsp = false;
#    if (isset($_REQUEST['tsp'])) $tsp = true;

    $plandata = $db->query("select * from plan where id=$1", array($plan));
    if ($plandata === false)
        returnError("plan does not exist");
    $plandata = $plandata[0];
    $rot = $plandata['rotations'];

    debugPrint("--------- plan data --------", $plandata);

    # conflicts:
    #   0 - none
    #   1 - exclusions
    #   2 - multiple paddocks
    #

    # list plan

    $sql = "
select *,
       case when exc_start is not null and 
         (exc_start, exc_end) overlaps (start_date, end_date) 
         then 1 else 0 end as conflicts
  from ( select * from paddockplanningdata($1)) g
  order by seq
    ";
    $results = $db->query($sql, array($plan));

    debugPrint("------- list results ---------", $results);

    # check for paddocks used multiple times
    $data = array();
    for ($i=0; $i<count($results); $i++) {
        if (isset($data[$results[$i]['padid']]) &&
            is_array($data[$results[$i]['padid']]))
            array_push($data[$results[$i]['padid']], $i);
        else
            $data[$results[$i]['padid']] = array($i);
    }

    # add a conflict flag to all related records
    foreach($data as $k => $v) {
        if ( count($v) == 1 ) continue;
        foreach($v as $idx) {
            $results[$idx]['conflicts'] = 2;
        }
    }


    echo json_encode(array("data" => $results));

}


function updatePaddock() {
    global $db;

    if (! isset($_REQUEST['plan']) || ! strlen($_REQUEST['plan']))
        returnError("plan must be set for update!");

    if (! isset($_REQUEST['plan-rotations']) ||
            ! is_array($_REQUEST['plan-rotations']))
        returnError("plan-rotations is not set or not an array");
    
    $str = "{" . implode(',', $_REQUEST['plan-rotations']) . "}";

    $sql = "
update herd_rotations a
       set plan_start=bb.start_date,
           plan_end=bb.end_date
 from ( select rid, start_date, end_date from paddockplanningdata($1, '$str'::integer[]) ) bb
where a.id=bb.rid and a.act_start is null
    ";
    $db->query($sql, array($_REQUEST['plan']));

    listPaddock();
}


function updateGD() {
    global $db;

    $rid = intVal($_REQUEST['rid']);
    $gd  = intVal($_REQUEST['gd']);

    $sql = "update herd_rotations set grazing_days=nullif($1, 0) where id=$2";
    $db->query($sql, array($gd, $rid));

    listPaddock();
}


/*

function old2listPaddock() {
    global $db;

    # fetch the plan
    if (isset($_REQUEST['plan']) && strlen($_REQUEST['plan']))
        $plan = intval($_REQUEST['plan']);

    $tsp = false;
    if (isset($_REQUEST['tsp'])) $tsp = true;

    $plandata = $db->query("select * from plan where id=$1", array($plan));
    if ($plandata === false)
        returnError("plan does not exist");
    $plandata = $plandata[0];
    $rot = $plandata['rotations'];

    debugPrint("--------- plan data --------", $plandata);

    # if rotations is null
    if (! isset($rot) || $rot === null) {
        #   get paddock list
        if ($tsp) {
            $sql = "select min(pad) as startid from plan_paddock where pid=$1";
            $data = $db->query($sql, array($plan));
            if ($data === false)
                returnError("Failed to get a starting paddock id");

            debugPrint("----- data -----", $data);

            $startid = $data[0]['startid'];

            $sql = "select array_agg(id2) as rot from
                pgr_tsp('select b.id,
                                st_x(st_centroid(geom)) as x,
                                st_y(st_centroid(geom)) as y
                           from plan_paddock a,
                                paddock_geom b
                          where a.pid={$plan} and a.pad=b.id', $1)";
            $data = $db->query($sql, array($startid));

            debugPrint("-------- tsp (rot) -----------", $data);

            $rot = $data[0]['rot'];
        }
        else {
            $sql = "select array_agg(pad) as rot from plan_paddock where pid=$1";
            $data = $db->query($sql, array($plan));

            debugPrint("-------- plan_paddock (rot) -----------", $data);

            $rot = $data[0]['rot'];
        }

        #   save in rotations
        $sql = "update plan set rotations=$1 where id=$2";
        $db->query($sql, array($rot, $plan));
    }

    # check if rotations matches plan
    if (checkPlanRotations($plan, $plandata['rotations'])) {
        #   do something if not
    }

    # list plan
    $sql = "
select e.seq, e.id, e.name, e.actmingd, e.actmaxgd, e.cum_days,
       to_char(start_date::timestamp, 'YYYY-MM-DD'::text) as start_date,
       to_char(end_date::timestamp, 'YYYY-MM-DD'::text) as end_date,
       sum(case when f.plan is not null and
           (f.exc_start, f.exc_end) overlaps (e.start_date, e.end_date)
           then 1 else 0 end) as conflicts
  from (
        select c.*,
               d.start_date + (c.cum_days-actmaxgd) * interval '1 day' as start_date,
               d.start_date + c.cum_days * interval '1 day' as end_date
          from (
            select seq, id, name, actmingd, actmaxgd,
                   sum(actmaxgd) over (order by seq) as cum_days
              from paddockPlanningData($1) a,
                   (select row_number() over() as seq, id2 from (
                       select unnest('$rot'::integer[]) as id2) as foo
                   ) b
             where a.id=b.id2
             order by b.seq
         ) c,
         (select start_date from plan where id=$1) d
      ) e
      left outer join paddock_exclusions f on e.id=f.padid and plan=$1
 group by e.seq, e.id, e.name, e.actmingd, e.actmaxgd,
          e.cum_days, e.start_date, e.end_date
 order by seq
";
    $results = $db->query($sql, array($plan));
    echo json_encode(array("data" => $results));

}

function oldlistPaddock() {
    global $db;

    if (isset($_REQUEST['plan']) && strlen($_REQUEST['plan']))
        $plan = intval($_REQUEST['plan']);
    else
        returnError("plan required for mode=list");

    if (isset($_REQUEST['startid']) && strlen($_REQUEST['startid'])) {
        $startid = intval($_REQUEST['startid']);
    }
    else 
        $startid = 0;
        
    if ($startid == 0) {
        $sql = "select min(pad) as startid from plan_paddock where pid=$1";
        $data = $db->query($sql, array($plan));
        if ($data === false)
            returnError("Failed to get a starting paddock id");

        debugPrint("----- data -----", $data);

        $startid = $data[0]['startid'];
    }

    $sql = "
select e.seq, e.id, e.name, e.actmingd, e.actmaxgd, e.cum_days,
       to_char(start_date::timestamp, 'YYYY-MM-DD'::text) as start_date,
       to_char(end_date::timestamp, 'YYYY-MM-DD'::text) as end_date,
       sum(case when f.plan is not null and
           (f.exc_start, exc_end) overlaps (e.start_date, e.end_date)
           then 1 else 0 end) as conflicts
  from (
        select c.*, 
               d.start_date + (c.cum_days-actmaxgd) * interval '1 day' as start_date,
               d.start_date + c.cum_days * interval '1 day' as end_date
          from (
                select seq, id, name, actmingd, actmaxgd,
                       sum(actmaxgd) over (order by seq) as cum_days
                  from paddockPlanningData($1) a,
                       pgr_tsp('select b.id,
                                       st_x(st_centroid(geom)) as x,
                                       st_y(st_centroid(geom)) as y 
                                  from plan_paddock a, paddock_geom b
                                 where a.pid={$plan} and a.pad=b.id', $2
                               ) b
                 where a.id=b.id2
                 order by b.seq
               ) c,
               (
                select start_date from plan where id=$1
               ) d
       ) e
       left outer join paddock_exclusions f on e.id=f.padid and plan=$1
 group by e.seq, e.id, e.name, e.actmingd, e.actmaxgd,
          e.cum_days, e.start_date, e.end_date
 order by seq
";
    $results = $db->query($sql, array($plan, $startid));
    echo json_encode(array("data" => $results));
}

*/
?>

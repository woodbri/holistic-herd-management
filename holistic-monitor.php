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
    if (isset($argv))
        createSchema();
    break;

case "add":
    if (isset($_REQUEST['id']))
        returnError("id must not be set for mode=add");
    addEvent();
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

case "plist":
    listPaddocks();
    break;

case "olist":
    listMonitorOverview();
    break;

case "dlist":
    listMonitorDetails();
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

    $r = pg_query("drop table if exists monitor cascade");

    $sql = '
CREATE TABLE monitor
(
  id serial NOT NULL,
  padid integer NOT NULL,
  mdate timestamp without time zone NOT NULL,
  moisture integer NOT NULL DEFAULT (-1),
  growth integer NOT NULL DEFAULT (-1),
  ada real NOT NULL DEFAULT (-1.0),
  who text,
  notes text,
  CONSTRAINT monitor_pkey PRIMARY KEY (id)
)
WITH (
  OIDS=FALSE
)
';
    $r = pg_query($sql);

    $r = pg_query("commit");

    exit(0);
}

function addEvent() {
    if ( ! strlen($_REQUEST['padid']) || $_REQUEST['padid'] == '-1' )
        returnError("paddock is a required field");
    if ( ! strlen($_REQUEST['date']) )
        returnError("date is a required field");
    if ( ! strlen($_REQUEST['moisture']) || $_REQUEST['moisture'] == '-1' )
        returnError("moisture is a required field");
    if ( ! strlen($_REQUEST['growth']) || $_REQUEST['growth'] == '-1' )
        returnError("growth is a required field");
    if ( ! strlen($_REQUEST['ada']) )
        returnError("ada is a required field");

    $data = array(
        $_REQUEST['padid'],
        $_REQUEST['date'],
        $_REQUEST['moisture'],
        $_REQUEST['growth'],
        $_REQUEST['ada'],
        strlen($_REQUEST['who'])    ? $_REQUEST['who']   : null,
        strlen($_REQUEST['notes'])  ? $_REQUEST['notes'] : null
    );

    $r = pg_query_params("insert into monitor (padid, mdate, moisture, growth, ada, who, notes) values ($1, $2, $3, $4, $5, $6, $7)", $data);

    echo '{"status": "OK", "mode": "add"}';
}


function updateEvent() {
    if ( ! strlen($_REQUEST['padid']) || $_REQUEST['padid'] == '-1' )
        returnError("paddock is a required field");
    if ( ! strlen($_REQUEST['date']) )
        returnError("date is a required field");
    if ( ! strlen($_REQUEST['moisture']) || $_REQUEST['moisture'] == '-1' )
        returnError("moisture is a required field");
    if ( ! strlen($_REQUEST['growth']) || $_REQUEST['growth'] == '-1' )
        returnError("growth is a required field");
    if ( ! strlen($_REQUEST['ada']) )
        returnError("ada is a required field");

    $data = array(
        $_REQUEST['padid'],
        $_REQUEST['date'],
        $_REQUEST['moisture'],
        $_REQUEST['growth'],
        $_REQUEST['ada'],
        strlen($_REQUEST['who'])    ? $_REQUEST['who']   : null,
        strlen($_REQUEST['notes'])  ? $_REQUEST['notes'] : null,
        $_REQUEST['id']
    );

    $r = pg_query_params("update monitor set padid=$1, mdate=$2, moisture=$3, growth=$4, ada=$5, who=$6, notes=$7 where id=$8", $data);

    echo '{"status": "OK", "mode": "update"}';
}


function deleteEvent() {
    $r = pg_query_params("delete from monitor where id=$1",
        array($_REQUEST['id']));
    echo '{"status": "OK", "mode": "delete", "id": ' . $_REQUEST['id'] . '}';
}


function listPaddocks() {
    $sql = "select name, id from paddocks order by name";
    $results = array();
    $r = pg_query($sql);
    while ($row = pg_fetch_assoc($r)) {
        array_push($results, $row);
    }
    pg_free_result($r);
    echo json_encode(array( 'data' => $results));
}


function listMonitorOverview() {
    $plan = 0;
    if ( isset($_REQUEST['plan']) && intval($_REQUEST['plan'])>0 )
        $plan = intval($_REQUEST['plan']);

    $sql = "select coalesce(bb.seq,999999) as seq, bb.padid as padid, name, id as mid, mdate::date,
                   moisture, growth, aa.ada as mada, bb.ada, who, dayssince,
                   daystilherdin, daysherdin, daystilherdout, daysherdout
  from (
         select p.id as pid, p.name, m.*, now()::date-mdate::date as dayssince
           from paddocks p
                left outer join
                (
                  select b.*
                    from
                         ( select padid, max(mdate) as mdate from monitor group by padid ) a,
                         monitor b
                   where a.padid=b.padid and a.mdate=b.mdate
                ) m
                on p.id=m.padid
       ) aa left outer join
       (
         select a.seq, a.padid, start_date, end_date,
                c.qual as ada,
                case when now()::date < start_date then start_date-now()::date else null end as daystilherdin,
                case when now()::date between start_date and end_date then now()::date-start_date else null end as daysherdin,
                case when now()::date between start_date and end_date then end_date-now()::date else null end as daystilherdout,
                case when now()::date > end_date then now()::date-end_date else null end as daysherdout
           from ( select * from paddockplanningdata($1)) a
           join paddocks b on a.padid=b.id
           left outer join plan_paddock c on c.pid=$1 and c.pad=a.padid
       ) bb
       on aa.pid=bb.padid
 order by seq, name
";
    $results = array();
    $r = pg_query_params( $sql, array($plan) );
    while ($row = pg_fetch_assoc($r)) {
    /*
        foreach($row as $k => $v) {
            if ($v === null) $row[$k] = '';
        }
    */
        array_push($results, $row);
    }
    pg_free_result($r);
    echo json_encode(array( 'data' => $results));
}


function listMonitorDetails() {
    $days = 90;
    if ( isset($_REQUEST['days']) && strlen($_REQUEST['days']) &&
            intval($_REQUEST['days'])>0 )
        $days = intval($_REQUEST['days']);
    
    $sql = "select p.name, m.id, m.padid, to_char(mdate, 'YYYY-MM-DD') as mdate,
                moisture, growth,ada, who, notes
            from monitor m left outer join paddocks p on m.padid=p.id
            where mdate > now()-$1*interval '1 day'
            order by p.name asc, mdate desc";
    $results = array();
    $r = pg_query_params( $sql, array($days) );
    while ($row = pg_fetch_assoc($r)) {
        array_push($results, $row);
    }
    pg_free_result($r);
    echo json_encode(array( 'data' => $results));
}

?>

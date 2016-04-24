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
    addAnimal();
    break;
case "list":
case "":
    if (isset($_REQUEST['id']))
        listAnimal();
    else
        listAnimals();
    break;
case "update":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=update");
    updateAnimal();
    break;
case "delete":
    if (! isset($_REQUEST['id']))
        returnError("id must be set for mode=delete");
    deleteAnimal();
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
create table animals(
    id serial not null primary key,
    herdid integer not null,
    "type" text not null,
    qty integer,
    sau float,
    weight float,
    forage float,
    tag text,
    arrival timestamp without time zone,
    est_ship timestamp without time zone,
    act_ship timestamp without time zone,
    notes text
)';
    $r = pg_query($sql);
    exit(0);
}

function addAnimal() {
    $err = '';
    if (! strlen($_REQUEST['type']))
        $err .= "type is a required field, ";
    if (! strlen($_REQUEST['qty']))
        $err .= "qty is a required field, ";
    if (! strlen($_REQUEST['weight']))
        $err .= "weight is a required field, ";
    if (! strlen($_REQUEST['forage']))
        $err .= "forage is a required field, ";
    if (! strlen($_REQUEST['herdid']))
        $err .= "herdid is a required field";
    if (strlen($err) != 0)
        returnError($err);
    
    $data = array(
        strlen($_REQUEST['type'])     ? $_REQUEST['type']:null,
        strlen($_REQUEST['qty'])      ? $_REQUEST['qty']:null,
        strlen($_REQUEST['sau'])      ? $_REQUEST['sau']:null,
        strlen($_REQUEST['weight'])   ? $_REQUEST['weight']:null,
        strlen($_REQUEST['forage'])   ? $_REQUEST['forage']:null,
        strlen($_REQUEST['tag'])      ? $_REQUEST['tag']:null,
        strlen($_REQUEST['arrival'])  ? $_REQUEST['arrival']:null,
        strlen($_REQUEST['est_ship']) ? $_REQUEST['est_ship']:null,
        strlen($_REQUEST['act_ship']) ? $_REQUEST['act_ship']:null,
        strlen($_REQUEST['notes'])    ? $_REQUEST['notes']:null,
        strlen($_REQUEST['herdid'])   ? $_REQUEST['herdid']:null
    );

    $r = pg_query_params("insert into animals (\"type\", qty, sau, weight, forage, tag, arrival, est_ship, act_ship, notes, herdid) values ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)", $data);

    listAnimal();
}

function listAnimal() {
    if (isset($_REQUEST['id']))
        $id = intval($_REQUEST['id']);
    else
        $id = "lastval()";

    if (isset($_REQUEST['herdid']) && strlen($_REQUEST['herdid'])>0)
        $hid = " and herdid=" . intval($_REQUEST['herdid']);
    else
        $hid = " and herdid is null";

    $sql = "select id, \"type\", qty, sau, weight, forage,
            coalesce(tag,'') as tag,
            arrival,
            est_ship,
            act_ship,
            coalesce(notes,'') as notes,
            herdid
        from animals where id={$id} {$hid}";
    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r)) {
        echo json_encode($row);
    }
    else {
        returnError("Problem fetching animal from database!");
    }
    pg_free_result($r);
}

function listAnimals() {
    if (isset($_REQUEST['herdid']) && strlen($_REQUEST['herdid'])>0)
        $hid = " herdid=" . intval($_REQUEST['herdid']);
    else
        $hid = " herdid is null";

    $sql = "select id, \"type\", qty, sau, weight, forage,
            coalesce(tag,'') as tag,
            arrival,
            est_ship,
            act_ship,
            coalesce(notes,'') as notes,
            herdid
        from animals where {$hid} order by id ";

    $result = array();
    $r = pg_query($sql);
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }
    pg_free_result($r);
    echo json_encode(array( 'data' => $result));
}

function updateAnimal() {
    if (! strlen($_REQUEST['id']))
        returnError("id is a required field");

    $data = array();
    $term = array();
    if (isset($_REQUEST['type'])) {
        if ($_REQUEST['type'] != 'null' && strlen($_REQUEST['type'])) {
            array_push($data, $_REQUEST['type']);
            array_push($term, '"type"=$' . count($data));
        }
    }
    if (isset($_REQUEST['qty'])) {
        if ($_REQUEST['qty'] != 'null' && strlen($_REQUEST['qty'])) {
            array_push($data, $_REQUEST['qty']);
            array_push($term, 'qty=$' . count($data));
        }
    }
    if (isset($_REQUEST['sau'])) {
        if ($_REQUEST['sau'] != 'null' && strlen($_REQUEST['sau'])) {
            array_push($data, $_REQUEST['sau']);
            array_push($term, 'sau=$' . count($data));
        }
    }
    if (isset($_REQUEST['weight'])) {
        if ($_REQUEST['weight'] != 'null' && strlen($_REQUEST['weight'])) {
            array_push($data, $_REQUEST['weight']);
            array_push($term, 'weight=$' . count($data));
        }
    }
    if (isset($_REQUEST['forage'])) {
        if ($_REQUEST['forage'] != 'null' && strlen($_REQUEST['forage'])) {
            array_push($data, $_REQUEST['forage']);
            array_push($term, 'forage=$' . count($data));
        }
    }
    if (isset($_REQUEST['tag'])) {
        if ($_REQUEST['tag'] == 'null' or ! strlen($_REQUEST['tag'])) {
            array_push($term, 'tag=null');
        }
        else {
            array_push($data, $_REQUEST['tag']);
            array_push($term, 'tag=$' . count($data));
        }
    }
    if (isset($_REQUEST['arrival'])) {
        if ($_REQUEST['arrival'] == 'null' or ! strlen($_REQUEST['arrival'])) {
            array_push($term, 'arrival=null');
        }
        else {
            array_push($data, $_REQUEST['arrival']);
            array_push($term, 'arrival=$' . count($data));
        }
    }
    if (isset($_REQUEST['est_ship'])) {
        if ($_REQUEST['est_ship'] == 'null' or ! strlen($_REQUEST['est_ship'])) {
            array_push($term, 'est_ship=null');
        }
        else {
            array_push($data, $_REQUEST['est_ship']);
            array_push($term, 'est_ship=$' . count($data));
        }
    }
    if (isset($_REQUEST['act_ship'])) {
        if ($_REQUEST['act_ship'] == 'null' or ! strlen($_REQUEST['act_ship'])) {
            array_push($term, 'act_ship=null');
        }
        else {
            array_push($data, $_REQUEST['act_ship']);
            array_push($term, 'act_ship=$' . count($data));
        }
    }
    if (isset($_REQUEST['notes'])) {
        if ($_REQUEST['notes'] == 'null' or ! strlen($_REQUEST['notes'])) {
            array_push($term, 'notes=null');
        }
        else {
            array_push($data, $_REQUEST['notes']);
            array_push($term, 'notes=$' . count($data));
        }
    }
    array_push($data, $_REQUEST['id']);
    $sql = 'update animals set ' . implode(", ", $term) . ' where id=$' . count($data);
    //echo "$sql\n";
    $r = pg_query_params($sql, $data);

    listAnimal();

}

function deleteAnimal() {
    $r = pg_query_params("delete from animals where id=$1",
         array($_REQUEST['id']));
    echo '{"status": "OK", "mode": "delete", "id": ' . $_REQUEST['id'] . '}';
}

?>

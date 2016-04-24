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
case "list":
case "":
    if (isset($_REQUEST['id']))
        listPadId();
    else
        listPad();
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
    header($_SERVER["SERVER_PROTOCOL"]." 500 $str"); 
    exit(1);
}

# data is an array of arrays with keys "id", "name", "kml"
# data2 is kml for a line string

function kml_encode($data, $data2) {
    header('Content-type: text/xml');
    #header('Content-type: application/vnd.google-earth.kml+xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
      <Style id="transYellowPoly">
        <LineStyle>
          <width>1.5</width>
        </LineStyle>
        <PolyStyle>
          <color>7d00ffff</color>
        </PolyStyle>
      </Style>
      <Style id="transGreenPoly">
        <LineStyle>
          <width>1.5</width>
        </LineStyle>
        <PolyStyle>
          <color>7d00ff00</color>
        </PolyStyle>
      </Style>
      <Style id="transPurpleLine">
        <LineStyle>
          <color>7dff00ff</color>
          <width>2.0</width>
        </LineStyle>
      </Style>
    ';

    for ($i=0; $i<count($data); $i++) {
        echo "<Placemark>\n  <name>".$data[$i]['name']."</name>\n";
        if ($data[$i]['sel'])
            echo "  <styleUrl>#transGreenPoly</styleUrl>\n";
        else
            echo "  <styleUrl>#transYellowPoly</styleUrl>\n";
        echo $data[$i]['kml']."\n";
        echo "</Placemark>\n";
    }

    if (isset($data2) && strlen($data2)) {
        echo "<Placemark>\n  <name>Rotation Order</name>\n";
        echo "  <styleUrl>#transPurpleLine</styleUrl>\n";
        echo $data2 . "\n";
        echo "</Placemark>\n";
    }
    echo "  </Document>\n</kml>\n";
}


function listPadId() {
    if (isset($_REQUEST['id']))
        $id = intval($_REQUEST['id']);

    $sql = "select a.id, b.name, 0 as sel, st_askml(geom, 6) as kml from paddock_geom a, paddocks b where a.id=b.id and a.id={$id}";

    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r)) {
        echo kml_encode(array($row), '');
        pg_free_result($r);
    }
}


function listPad() {
    if (isset($_REQUEST['plan']) && strlen($_REQUEST['plan']))
        $plan = intval($_REQUEST['plan']);
    else
        $plan = -1;

    $sql = "select a.id, b.name,
        case when c.pid is null then 0 else 1 end as sel,
        st_askml(geom, 6) as kml
      from paddock_geom a, paddocks b
        left outer join plan_paddock c on b.id=c.pad and c.pid={$plan}
      where a.id=b.id ";
    $r = pg_query($sql);
    $result = array();
    while ($row = pg_fetch_assoc($r)) {
        array_push($result, $row);
    }

    $sql = "select st_askml(st_makeline(st_centroid(b.geom))) as kml
              from ( select * from paddockplanningdata({$plan})) a
              join paddock_geom b on a.padid=b.id";
    $r = pg_query($sql);
    if ($row = pg_fetch_assoc($r))
        $data = $row['kml'];
    else
        $data = '';


    echo kml_encode($result, $data);
    pg_free_result($r);
}


?>

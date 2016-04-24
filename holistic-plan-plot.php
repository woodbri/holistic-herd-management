<?php
/*


*/

require_once "holistic-config.php";

// parse commandline arguments
if (isset($argv))
    parse_str(implode('&', array_slice($argv, 1)), $_REQUEST);
$_REQUEST = array_change_key_case($_REQUEST);

if (isset($argv)) {
    echo "------- args ---------\n";
    print_r($_REQUEST);
    $filename = "junk.png";
}
else
    $filename = null;

function returnError($msg) {
    die($msg);
}

function debugPrint($head, $data) {
    global $argv;
    if (isset($argv)) {
        echo "$head\n";
        print_r($data);
    }
}

$header = false;
if (isset($_REQUEST['mode']) && $_REQUEST['mode'] == 'header')
    $header = true;

if (! isset($_REQUEST['plan']))  returnError("plan is not set");
$plan  = $_REQUEST['plan'];

if (! $header) {
    if (! isset($_REQUEST['padid'])) returnError("padid is not set");
    $padid = $_REQUEST['padid'];
    if (! isset($_REQUEST['start'])) returnError("start is not set");
    $start = $_REQUEST['start'];
    if (! isset($_REQUEST['end']))   returnError("end is not set");
    $end   = $_REQUEST['end'];
}
if (! isset($_REQUEST['w'])) $w = 550; else $w = $_REQUEST['w'];
if (! isset($_REQUEST['h'])) $h = 16;  else $h = $_REQUEST['h'];

$db = new Database();

$plandata = $db->query(
    "select *, extract(day from end_date - start_date) as days,
            extract(day from $1 - start_date) as pstart,
            extract(day from $2 - start_date) as pend
       from plan where id=$3", array($start, $end, $plan));
$plandata = $plandata[0];

debugPrint( "------- plandata --------", $plandata );

$daywidth = round($w / ($plandata['days']+1));
$w = $plandata['days'] * $daywidth;

debugPrint( "------ image size -------", array( 'h' => $h, 'w' => $w, 'daywidth' => $daywidth ) );

if (! $header) {

    $exclusions = $db->query(
        "select extract(day from exc_start - start_date) as sday,
                extract(day from exc_end - start_date) as eday
           from plan a, paddock_exclusions b
          where b.plan=a.id and a.id=$1 and b.padid=$2", array($plan, $padid));

    debugPrint( "------- exclusions ----------", $exclusions );

    $social = $db->query(
        "select extract(day from a.start - b.start_date) as sday,
                extract(day from a.end - b.start_date) as eday
           from calendar a, plan b
          where b.id=$1 and 
                (b.start_date, b.end_date) overlaps (a.start, a.end)",
        array($plan));

    debugPrint( "------- social ----------", $social );

}

$daybymonth = $db->query(
    "select unnest(daysavailablebymonth(year, start_date, end_date)) as days
      from plan where id=$1", array($plan));

debugPrint( "------- daybymonth ----------", $daybymonth );

$im = @imagecreatetruecolor( $w, $h )
      or returnError('Cannont Initialize new GD image stream');

// set the background
$white = imagecolorallocate($im, 255, 255, 255);
imagefill($im, 0, 0, $white);

if (! $header) {

    // add social events
    $purple = imagecolorallocate($im, 255, 0, 255);
    for( $i=0; $i<count($social); $i++) {
        $x1 = $social[$i]['sday']*$daywidth;
        $y1 = 0;
        $x2 = $social[$i]['eday']*$daywidth;
        $y2 = $h;
        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $purple);
    }

    // add exclusions
    $red = imagecolorallocate($im, 255, 0, 0);
    for( $i=0; $i<count($exclusions); $i++) {
        $x1 = $exclusions[$i]['sday']*$daywidth;
        $y1 = 3;
        $x2 = $exclusions[$i]['eday']*$daywidth;
        $y2 = $h-3;
        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $red);
    }

    // add the paddock for the dates given
    $green = imagecolorallocate($im, 0, 255, 0);
    $x1 = $plandata['pstart']*$daywidth;
    $y1 = 6;
    $x2 = $plandata['pend']*$daywidth;
    $y2 = $h-6;
    imagefilledrectangle($im, $x1, $y1, $x2, $y2, $green);

    debugPrint( "------- paddock x coords --------", array( 'x1' => $x1, 'x2' => $x2 ));

}

// draw the month boundaries
$month = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
               'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
$black = imagecolorallocate($im, 0, 0, 0);
$days = 0;
for( $i=0; $i<count($daybymonth); $i++ ) {
    if ($daybymonth[$i]['days']+0 == 0)
        continue;
    else
        $days += $daybymonth[$i]['days'];
    $x1 = $days*$daywidth;
    $y1 = 0;
    $x2 = $days*$daywidth;
    $y2 = $h;
    imageline($im, $x1, $y1, $x2, $y2, $black);
    if ($header)
        imagestring($im, 2, $x1+10, $y1+3, $month[$i+1], $black);
}

header('Content-Type: image/png');
imagepng($im, $filename);
imagedestroy($im);

?>

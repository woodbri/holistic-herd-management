<?php

require_once("./config.php");

$db = new Database();

$r = $db->query("drop table if exists php_test cascade");
echo "drop table: {$r}\n";
echo "-------------------------------------------------\n";

$r = $db->query("create table php_test (
    id serial not null primary key,
    f_int integer,
    f_real real,
    f_text text
)");
echo "create table: {$r}\n";
echo "-------------------------------------------------\n";

$data = array(
    'f_int' => 101,
    'f_real' => 101.01,
    'f_text' => 'one hundred and one point oh one'
);
$r = $db->insert("data.php_test", $data);
echo "insert: {$r}\n";
echo "-------------------------------------------------\n";

$result = $db->query("select * from php_test order by id");
echo "query: select * from php_test order by id\n";
print_r($result);
echo "-------------------------------------------------\n";

$array = array();
for($i=0; $i<5; $i++) {
    $array[] = array(
        'f_int' => 101 + $i + 1,
        'f_real' => 101.01 + $i + 1 + ($i + 1)/100.,
        'f_text' => 'testing: ' . (101.01 + $i + 1 + ($i + 1)/100.)
    );
}
$r = $db->insertArray("data.php_test", $array);
echo "insertArray: {$r}\n";
echo "-------------------------------------------------\n";

$result = $db->query("select * from php_test order by id");
echo "query: select * from php_test order by id\n";
print_r($result);
echo "-------------------------------------------------\n";

$r = $db->update("data.php_test", $array[count($array)-1], array('id'=>1));
echo "update: {$r}\n";
echo "-------------------------------------------------\n";

$r = $db->delete("data.php_test", array('id'=>2));
echo "delete: {$r}\n";
echo "-------------------------------------------------\n";

$result = $db->query("select * from php_test order by id");
echo "query: select * from php_test order by id\n";
print_r($result);
echo "-------------------------------------------------\n";

$r = $db->deleteIds("data.php_test", 'id', array(3,5,6));
echo "deleteIds: {$r}\n";
echo "-------------------------------------------------\n";

$result = $db->query("select * from php_test order by id");
echo "query: select * from php_test order by id\n";
print_r($result);
echo "-------------------------------------------------\n";

$r = $db->query("drop table if exists php_test cascade");
echo "drop table: {$r}\n";
echo "-------------------------------------------------\n";



?>

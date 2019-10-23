<pre>
<?php

$test_result = array();

//---------------------------- PHP Checks ------------------------------------------------//

$test_result["php"] = array();
$test_result["php"]["version"] = phpversion();

//---------------------------- File Checks ------------------------------------------------//

$test_result["file"] = array();
$test_result["file"]["class"] = md5_file(getcwd()."/paysafecash_gateway.php");
$test_result["file"]["lib"] = md5_file(getcwd()."/libs/PaymentClass.php");

//---------------------------- Network Checks ------------------------------------------------//

$test_result["network"] = array();
$test_result["network"]["resolve_test"] = gethostbyname("apitest.paysafecard.com");
$test_result["network"]["resolve_production"] = gethostbyname("api.paysafecard.com");
$test_result["network"]["curl"] = curl_version();


//---------------------------- SSL Checks ------------------------------------------------//

$test_result["ssl"] = array();

$sc = stream_context_create (array("ssl" => array("capture_peer_cert" => true)));
$r = stream_socket_client("ssl://api.paysafecard.com:443", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $sc);
$context = stream_context_get_params($r);
$certinfo = openssl_x509_parse($context["options"]["ssl"]["peer_certificate"]);

array_pop($certinfo);
$test_result["ssl"] = $certinfo;
//---------------------------- END SSL Checks ------------------------------------------------//


//---------------------------- Curl Checks ------------------------------------------------//
$test_result["curl"] = array();

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://apitest.paysafecard.com/v1/payments/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

$test_result["curl"]["request"] = curl_getinfo($ch);
curl_close($ch);

//---------------------------- Ausgabe Checks ------------------------------------------------//

echo json_encode($test_result);

?>
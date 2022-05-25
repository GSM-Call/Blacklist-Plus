<?php

define("DB_SERVER", "localhost");
define("DB_USER", "root");
define("DB_PASS", "passw0rd");

$dbh = new mysqli(DB_SERVER, DB_USER, DB_PASS);   
$sql="GRANT ALL PRIVILEGES ON gsmcall.* TO 'freepbxuser'@'localhost';";
$dbh->query($sql);

out(_("Creating Application Database - if it doesn't Already Exist"));
$sql = "CREATE DATABASE IF NOT EXISTS gsmcall;";
$dbh->query($sql);


out(_("Creating BlackList Plus Table if Doesn't Exist"));
$sql = "CREATE TABLE IF NOT EXISTS `gsmcall`.`blacklist` ( `tn` VARCHAR(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL , `de` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL , `ts` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL , `ab` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL , `mu` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL , PRIMARY KEY (`tn`)) ENGINE = InnoDB;";
$dbh->query($sql);

out(_("Updating htaccess file to permit running GSMcall PHP script."));
$update = 0;
// Caching the .htaccess file content
$fh = fopen(__DIR__."/../../.htaccess",'r');
while ($line = fgets($fh)) {
	if ( strstr( $line, '<FilesMatch "(^$' ) ) {
		if (!strstr($line, 'mail_gui')) {
			$line = substr_replace($line, '<FilesMatch "(^$|mail_gui\.php|maildial\.php', 1, 16);
			$update = 1;
		}
	}
	$lines[] = $line;
}
fclose($fh);
if ($update === 1) {
	// this empty the file so as i can load the modified lines again
	$f = fopen(__DIR__."/../../.htaccess", "r+");
	if ($f !== false) {
		ftruncate($f, 0);
		fclose($f);
	}
	$fh1 = fopen(__DIR__."/../../.htaccess",'w');
	foreach($lines as $key => $value ){
		fwrite($fh1, $value);
	}
}

// Testing cURL -- https://gist.github.com/pixelbrackets/86513e10c590b25fd673bc30b50288d9  & 

if(function_exists('curl_init')) {
    $url = $_SERVER['HTTP_ORIGIN'] . '/admin/modules/blacklist/agi-bin/mail_gui.php'; // you need to authorize mail_gui.php inside .htaccess file under /admin folder
		// Storing the PBX name in the array
	$info = parse_url($url);
	$host = $info['host'];
	$host = explode(".", $info['host']);
		// fields to be posted via cURL
	$fields ['host'] = ucfirst($host[0]);
	$fields ['toMail'] = 'support@gsmcall.com';

		// build the urlencoded data
	$postvars = http_build_query($fields);
		// open connection
	$ch = curl_init();
		// set the url, number of POST vars, POST data
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_FAILONERROR, true);
	curl_setopt($ch, CURLOPT_POST, count($fields));
	curl_setopt($ch, CURLOPT_POSTFIELDS, $postvars);
		// execute post
	$result = curl_exec($ch);
		//trap Curl Errors...
	if (curl_errno($ch)) {
		$error_msg = curl_error($ch);
	}

	if (isset($error_msg)) {
		$fp = fopen('cUrlErrorSetup.txt', 'w');
		fwrite($fp, print_r($error_msg, TRUE));
		fclose($fp);
		out(_("Testing cURL failed!"));	
		out(_("This Module uses cURL for PHP to send emails out. Please check the file called [cUrlErrorSetup.txt] under the [html..admin] path"));
	}
	// close connection
	curl_close($ch);
} else {
	out(_("cURL extension for PHP is not available! Please Install before using this BLK Plus"));
}
<?php
/**
 * Send MySQL runtime info to a Graylog Server via UDP socket.
 * PHP code "ported" from shell script at
 * https://github.com/arikogan/mysql-gelf
 * Please adjust "conf/settings.ini" with MySQL and Graylog data and
 * "conf/variables-abs" / "conf/variables-diff" with the variables you'd like to send
 * @author  Stefan Moises <moises@shoptimax.de>
 */
$DIR = dirname(__FILE__);

// read settings directly into variables
$settings = parse_ini_file($DIR . '/conf/settings.ini');
foreach ($settings as $key => $setting) {
    // Notice the double $$, this tells php to create a variable with the same name as key
    $$key = $setting;
}

$VERSION = "1.0";
$MESSAGE = "MySQL Status";
$HOST = gethostname();
$TIMESTAMP = date(time());
$LEVEL = 1;

# global status query
$STATUS_CMD = "SHOW GLOBAL STATUS";

$VARFILE_ABS = $DIR . '/conf/variables-abs';
$VARFILE_DIFF = $DIR . '/conf/variables-diff';

/**
 * Read a file and return the lines as array
 *
 * @param string $filename
 * @return array
 */
function getFileAsArray($filename)
{
    $lines=array();
    $fp=fopen($filename, 'r');
    while (!feof($fp)) {
        $line=fgets($fp);
        $line=trim($line);
        $lines[]=$line;
    }
    fclose($fp);
    return $lines;
}
/**
 * Write content to file
 *
 * @param string $filename
 * @param string $content
 * @return void
 */
function writeToFile($filename, $content)
{
    if (!$handle = fopen($filename, "w")) {
        print "Cannot open $filename";
        exit;
    }
    if (!fwrite($handle, $content)) {
        print "Cannot write $filename";
        exit;
    }
    fclose($handle);
}
/**
 * Send data via UPD socket
 *
 * @param string $ip
 * @param int $port
 * @param string $msg
 * @return void
 */
function sendViaSocket($ip, $port, $msg)
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    $len = strlen($msg);
    socket_sendto($sock, $msg, $len, 0, $ip, $port);
    socket_close($sock);
}

// Open connection
try {
    $pdo = new PDO('mysql:host='.$MYSQL_HOST.';port='.$MYSQL_PORT.';charset=utf8mb4', "$MYSQL_USER", "$MYSQL_PASS");
} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage();
    exit();
}
$stmt = $pdo->prepare($STATUS_CMD);
$stmt->execute();
$GLOBAL_STATUS_RES = '';
$Innodb_buffer_pool_reads = 0;
$Innodb_buffer_pool_read_requests = 0;
while ($row = $stmt->fetch()) {
    $GLOBAL_STATUS_RES .= $row['Variable_name'] . " = \"" . $row['Value'] . "\"\n";
    if ($row['Variable_name'] == 'Innodb_buffer_pool_reads') {
        $Innodb_buffer_pool_reads = $row['Value'];
    }
    if ($row['Variable_name'] == 'Innodb_buffer_pool_read_requests') {
        $Innodb_buffer_pool_read_requests = $row['Value'];
    }
}
if ($Innodb_buffer_pool_read_requests > 0) {
    $Innodb_buffer_pool_efficiency = 100-(($Innodb_buffer_pool_reads / $Innodb_buffer_pool_read_requests) * 100);
    $GLOBAL_STATUS_RES .=  "Innodb_buffer_pool_efficiency = \"" . $Innodb_buffer_pool_efficiency . "\"\n";
} else {
    $GLOBAL_STATUS_RES .=  "Innodb_buffer_pool_efficiency = \"-1\"\n";
}

// Close connection
$pdo = null;

$filename1 = $DIR . '/status/status.last';
if (!file_exists($filename1)) {
    writeToFile($filename1, $GLOBAL_STATUS_RES);
} else {
    $filename2 = $DIR . '/status/status.current';
    writeToFile($filename2, $GLOBAL_STATUS_RES);

    $LAST_VALS = parse_ini_file($filename1);
    $CURRENT_VALS = parse_ini_file($filename2);

    $MSG = "{\"version\": \"$VERSION\"";
    $MSG .= ",\"host\":\"$HOST\"";
    $MSG .= ",\"short_message\":\"$MESSAGE\"";
    $MSG .= ",\"timestamp\":$TIMESTAMP";
    $MSG .= ",\"level\":$LEVEL";

    $UPTIME_LAST = $LAST_VALS['Uptime'];
    $UPTIME_CURR = $CURRENT_VALS['Uptime'];
    $SECONDS = $UPTIME_CURR - $UPTIME_LAST;
    if ($SECONDS < 1) {
        $SECONDS = 1;
    }

    $aDiff = getFileAsArray($VARFILE_DIFF);
    foreach ($aDiff as $variable) {
        $LAST = $LAST_VALS[$variable];
        $CURRENT = $CURRENT_VALS[$variable];

        $DIFF = $CURRENT - $LAST;
        $DIFF_PER_SECOND = round($DIFF/$SECONDS, 2);
        $MSG .= ",\"_${variable}_per_second\":$DIFF_PER_SECOND";
    }

    $aAbs = getFileAsArray($VARFILE_ABS);
    foreach ($aAbs as $variable) {
        $VALUE = $CURRENT_VALS[$variable];
        $MSG .= ",\"_$variable\":$VALUE";
    }

    $MSG .= "}";
    // send to Graylog
    if (!$OUTPUT_JSON) {
        sendViaSocket($GRAYLOG_SERVER, $GRAYLOG_PORT, $MSG);
    }
    if ($DEBUG || $OUTPUT_JSON) {
        header('Content-Type: application/json');
        echo $MSG;
    }
    // move files
    unlink($filename1);
    rename($filename2, $filename1);
}

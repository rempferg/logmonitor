<?php
    $db_login = array_map('rtrim', file('.ht_dblogin'), array_fill(0, 4, "\n"));
    $db = new mysqli($db_login[0], $db_login[1], $db_login[2], $db_login[3]);

    if($db->connect_errno)
    {
        printf('Connect failed: %s\n', $db->connect_error);
        exit();
    }

    if(!$stats = $db->query('SELECT logfiles.id as id, path, COUNT(offenders.id) AS num_offenders FROM offenders, logfiles WHERE logfiles.id = offenders.logfile_id AND timestampdiff(HOUR, NOW(), seen) <= 24 GROUP BY id ORDER BY num_offenders DESC'))
        die('ERROR: ' . $db->error);

    while($stat = $stats->fetch_assoc())
        echo "{$stat['num_offenders']}\t{$stat['path']}\n";

    $stats->close();
    $db->close();
?>

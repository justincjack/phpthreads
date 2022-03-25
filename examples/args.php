<?php 
require_once '../lib/phpthread.php';

function threadproc($thread, 
                    $arg1, 
                    $arg2)
{
    echo "Hi! I'm the PHPThread.  Here are the arguments you passed:\n";
    echo "arg1: " . print_r($arg1, true) . "\n";
    echo "arg2: " . print_r($arg2, true) . "\n";
    echo "------------------------------------------\n";
    for ($i = 0; $i < 5; $i++) {
        usleep(1000000);
        echo "\t* PHPThread - Tick.\n";
    }
    return $i;
}

$tid    = 0;
$thread = null;
$retval = null;

$tid = phpthread_create(
    $thread, 
    array(), 
    "threadproc", 
    null, 
    array('apple', 'banana'));

for ($i = 0; $i < 10; $i++) {
    usleep(1000000);
    echo "* MAIN - Tick.\n";
}

phpthread_join($tid, $retval);

echo "* MAIN - Done, PHPThread was active for \"" . $retval . "\" seconds.\n\n";
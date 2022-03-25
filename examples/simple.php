<?php 
require_once '../lib/phpthread.php';

/**
 * PHPthread code that will run in parallel with our main
 * code.
 */
function threadproc( PHPTHREAD $thread ) {

    $my_id = phpthread_get_id(); //  Also can use $thread->id();

    echo "I'm a PHPthread!  I'm going to count to 10!\n";

    for ($i = 0; $i < 10; $i++) {
        echo "\tCHILD (PID: $my_id) - " . ((string)($i+1)) . " / 10\n"; 
        usleep(1000000);
    }

    echo "\tCHILD (PID: $my_id) - I'm done counting.  I'll return the boolean value 'TRUE' to my parent process.\n";

    return true;
}




/**
 * Get the PHPthread ID (process ID) of our main process.
 */
$main_id = phpthread_get_id();

echo "MAIN (PID: $main_id) - Welcome to the simple PHPthread demo.  I'm going to spawn one PHPthread!\n";

$phpthread      = null;
$phpthread_id   = phpthread_create($phpthread, array(), "threadproc");

/**
 * Check to see if our PHPthread launched okay.
 */
if ($phpthread_id < PHPT_SUCCESS) {
    echo "MAIN (PID: $main_id) - ERROR: [".phpthread_create_errmsg($phpthread_id)."] Failed to launch PHPthread!\n";
    exit($phpthread_id);
}

echo "MAIN (PID: $main_id) - I successfully launched my child PHPthread.  I'm going to count to 5, then wait on it to get done!\n\n";

for ($i = 0; $i < 5; $i++) {
    echo "MAIN (PID: $main_id) - " . ((string)($i+1)) . " / 5\n"; 
    usleep(1000000);
}

echo "\nMAIN (PID: $main_id) - I'm done counting.  I'm just waiting on the CHILD PHPthread to finish up now before I terminate.\n\n";

$phpt_ret = null;

/**
 * Wait until CHILD PHPthread finishes - no timeout
 */
phpthread_join($phpthread_id, $phpt_ret);

echo "\n\n";
echo "MAIN (PID: $main_id) - CHILD PHPthread returned: ";

if ($phpt_ret === true) {
    echo "TRUE\n\n";
} else if ($phpt_ret === false) {
    echo "FALSE\n\n";
} else {
    echo "\"" . print_r($phpt_ret, true) . "\"\n\n";
}

echo "Done.\n";

exit(0);
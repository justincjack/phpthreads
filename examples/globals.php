<?php 
require_once '../lib/phpthread.php';

$GLOBAL_WORKER_STATUS   = "inactive";
$GLOBAL_MONITOR_STATUS  = "inactive";
$GLOBAL_RUN             = true;

/**
 * Note: PHPThread's usage and manipulation of global variables neither
 * uses shared address space, nor may be relied upon to be atomic.
 * 
 * That being said, the library DOES quickly synchronize global variables 
 * across all PHPThread processes and can be very handy!
 * 
 */

 define("WORKER_STATES",        array(
     "active",
     "busy",
     "super-busy",
     "chilling",
     "thinking",
     "exhausted",
     "wandering",
     "invigorated",
     "feisty",
     "done"
 ));

 /**
  * @return int
  * Returns the number of seconds the PHPThread was "working."
  */
function worker_thread($phpthread) {
    global  $GLOBAL_WORKER_STATUS,
            $GLOBAL_MONITOR_STATUS,
            $GLOBAL_RUN;

    $msg    = null;
    $n      = 0;

    phpthread_set_global("GLOBAL_WORKER_STATUS", "active");

    echo "\n[WORKER: " . phpthread_get_id() . "] - Hi! I'm simulating/representing a worker thread that has a long job to do!\n";

    while ( $GLOBAL_RUN &&
            $GLOBAL_WORKER_STATUS !== "done")
    {
        usleep(1000000);
        if (++$n%6===0) {
            /* Change our status every 6 seconds */
            phpthread_set_global("GLOBAL_WORKER_STATUS", WORKER_STATES[rand(1, (count(WORKER_STATES)-1))]);
        }
    }
    echo "\n[WORKER: " . phpthread_get_id() . "] - We've done all of our work.  Shutting on down...\n";
    return $n;
}

/**
 * @return int
 * Returns zero on success...which is about all it will return in this example.
 */
function monitor_thread($phpthread) {
    global  $GLOBAL_WORKER_STATUS,
            $GLOBAL_MONITOR_STATUS,
            $GLOBAL_RUN;

    $timer = ftimer();  /* Shortcut for our "FLEX_TIMER" class hidden in the code.  Check it
                         * out, easily one of the most joy-causing classes I've ever written!
                         **/ 

    $last_worker_value = $GLOBAL_WORKER_STATUS;

    phpthread_set_global("GLOBAL_MONITOR_STATUS", "ready");

    echo "\n[MONITOR: " . phpthread_get_id() . "] - I'm representing a monitoring thread that does this or that based on the worker's status.\n";

    usleep(1000000);

    echo "\n[MONITOR: " . phpthread_get_id() . "] - Okay, I've done all my setup.  Setting my global to \"active\"\n";

    phpthread_set_global("GLOBAL_MONITOR_STATUS", "active");

    echo "\n[MONITOR: " . phpthread_get_id() . "] - Done.  Entering my watchful loop!\n";

    while ( $GLOBAL_RUN ) {
        usleep(1000000);

        switch ($timer->secs()) {
            case 20:
                echo "\n[MONITOR: " . phpthread_get_id() . "] - Boy! This worker is taking a LONG time to wrap it up!!\n";
                break;
            case 30:
                echo "\n[MONITOR: " . phpthread_get_id() . "] - ** Okay, you get the point, the monitor won't pick \"done\" so we'll let you go :)\n";
                phpthread_set_global("GLOBAL_RUN", false);
                break;
            default:
                break;
        }

        if ($GLOBAL_WORKER_STATUS !== $last_worker_value) {
            echo "\n[MONITOR: " . phpthread_get_id() . "] - Change! Worker's status is now: \"$GLOBAL_WORKER_STATUS\"\n";
            $last_worker_value = $GLOBAL_WORKER_STATUS;
            if ($GLOBAL_WORKER_STATUS === "done") {

                phpthread_set_global("GLOBAL_MONITOR_STATUS", "shutting_down");

                echo "\n[MONITOR: " . phpthread_get_id() . "] - Telling everyone we're done.  Can't run anymore!\n";

                phpthread_set_global("GLOBAL_RUN", false);

                echo "\n[MONITOR: " . phpthread_get_id() . "] - I'm simulating the monitor straggling a bit here...\n";

                usleep(5100000);
                /* I could/would put a "break" here, but I want to demonstrate how the global works */
            }
        }
    }
    phpthread_set_global("GLOBAL_MONITOR_STATUS", "done");
    return 0;
}

function start() {
    global  $GLOBAL_WORKER_STATUS,
            $GLOBAL_MONITOR_STATUS,
            $GLOBAL_RUN;

    /*************************  PHPThread Variables *************************/
    $worker_thread  = null;
    $worker_tid     = 0;
    $worker_retval  = null;

    $monitor_thread = null;
    $monitor_tid    = 0;
    $monitor_retval = null;
    /************************************************************************/

    $timer          = ftimer(); /* Shortcut for our "FLEX_TIMER" class hidden in the code.  Check it out. */
    $n              = 0;

    srand(time());

    echo "\n[MAIN: " . phpthread_get_id() . "] - Launching our monitor thread.\n";

    $monitor_tid = phpthread_create($monitor_thread, array(), "monitor_thread");

    if ($monitor_tid <= PHPT_SUCCESS) {
        echo "** ERROR: Failed to launch monitor thread!\n";
        return -$monitor_tid;
    }

    echo "\n[MAIN: " . phpthread_get_id() . "] - Waiting for monitor to be ready.\n";

    while ($GLOBAL_MONITOR_STATUS !== "ready") {
        usleep(100);
        if ($timer->secs() > 3) {
            echo "** ERROR: Monitor thread failed to initialize!\n";
            return 1;
        }
    }
    echo "\n[MAIN: " . phpthread_get_id() . "] - Monitor is ready.  Now we'll start our worker.\n";
    

    $worker_tid = phpthread_create($worker_thread, array(), "worker_thread"); 

    if ($worker_tid <= PHPT_SUCCESS) {
        echo "** ERROR: Failed to launch monitor thread!\n";
        $monitor_thread->kill();
        return -$worker_tid;
    }


    echo "\n[MAIN: " . phpthread_get_id() . "] - Main thread entering our run loop.\n";


    $timer->reset();
    /**
     * Loop while $GLOBAL_RUN is set.  Maybe it's shut down by an incoming signal,
     * maybe some networking event.  In this case, the monitor is going to signal
     * that the program should shut down ( :) because we're playing with globals ).
     */
    while ($GLOBAL_RUN) {
        if ($timer->secs() > 30) {
            echo "**ERROR: Too long in main loop.\n";
            $monitor_thread->kill();
            $worker_thread->kill();
            return 1;
        }
        /* Do whatever */
        usleep(1000000);
        if (++$n%5===0) {
            echo "[MAIN: " . phpthread_get_id() . "] - *Tick* Monitor's status is: \"$GLOBAL_MONITOR_STATUS\"\n";
        }
    }
    echo "\n[MAIN: " . phpthread_get_id() . "] - Done with main loop because \$GLOBAL_RUN has been set to FALSE.\n";
    echo "\n[MAIN: " . phpthread_get_id() . "] - Waiting for my PHPThreads to wrap it up.\n";

    if (phpthread_join($monitor_tid, $monitor_retval, 10000) !== PHPT_SUCCESS) {
        /* The probably/shouldn't ever happen, but hey!  That's what makes 
         * awesome software - better safe than sorry!
         **/ 
        echo "** ERROR: Failed to join with monitor thread after 10 seconds!\n";
        $monitor_thread->kill();
        $worker_thread->kill();
        return 1;
    }

    if (phpthread_join($worker_tid, $worker_retval, 10000) !== PHPT_SUCCESS) {
        /* The probably/shouldn't ever happen, but hey!  That's what makes 
         * awesome software - better safe than sorry!
         **/ 
        echo "** ERROR: Failed to join with worker thread after 10 seconds!\n";
        $worker_thread->kill();
        return 1;
    }
    echo "\n[MAIN: " . phpthread_get_id() . "] - My work here is done!  Here is what the PHPThreads returned:\n";
    echo "Monitor Thread: " . print_r($monitor_retval, true) . "\n";
    echo " Worker Thread: " . print_r($worker_retval, true) . "\n\n";

    echo "\$GLOBAL_MONITOR_STATUS = " . print_r($GLOBAL_MONITOR_STATUS, true) . "\n";
    echo "\$GLOBAL_WORKER_STATUS  = " . print_r($GLOBAL_WORKER_STATUS, true) . "\n";
    echo "-------------------------------------------------\n\n";
    return 0;
    
}


exit(start());

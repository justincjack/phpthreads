<?php 
require_once '../lib/phpthread.php';


/**
 * The 'onexit' handler is called in the execution context/address space of 
 * the parent process.
 */
function thread_exit_callback(  $exiting_thread,
                                $return_value)
{
    $my_id = phpthread_get_id();

    echo "thread_exit_callback() - MAIN (ID: $my_id) - PHPthread ID: " . 
        $exiting_thread->id() . " has exited. From here, I can inspect its return value, which is: \"" . 
        print_r($return_value, true) . "\"\n\n";
}


function threadproc(PHPTHREAD   $thread,
                    int         $messages_to_send)
{
    echo "\tCHILD (ID: " . $thread->pid .") - Our parent wants $messages_to_send messages from us.\n";

    $seconds    = 0;
    $msent      = 0;

    $running = true;
    $thread->user_data = &$running;
    $timeout = time() + 25;
    while ($running) {
        if (time() > $timeout) {
            echo "\n\n**** THREAD TIMEOUT ****\n\n";
            break;
        }

        /**
         * If the thread was started with on "onmessage" attribute,
         * this code will never run, as messages will not be enqueued.
         * 
         * We could call get_message(1000) to wait "up to" one second,
         * but we're calling usleep() below to make sure we wait no-less
         * than one second.
         */
        if ( ($msg = $thread->get_message())) {
            if ($msg->message === "quit") {
                echo "---- QUIT IN " . getmypid() . "\n\n";
                break;
            }

            $from_phpthread = $msg->thread;
            $message = $msg->message;
            echo "\n---------------------------------------------------\n";
            echo "CHILD (PID: " . phpthread_get_id() . ") - Message from: ";
            
            if ($from_phpthread->id() === $thread->parent_id()) {
                if ($from_phpthread->id() === phpthread_get_main_proc_id()) {
                    echo "PARENT/MASTER (PHPthread ID: " . $from_phpthread->id() . ")\n";
                } else {
                    echo "PARENT (ID: " . $from_phpthread->id() . ")\n";
                }
            } else {
                echo "PHPthread ID: " . $from_phpthread->id() . "\n";
            }
            echo trim(print_r($message, true)) . "\n";
            echo "---------------------------------------------------\n\n";
        }
        usleep(1000000);
        echo "\tCHILD (ID: " . $thread->pid .") - Tick :)\n";
        $seconds++;
        if (($msent < $messages_to_send) &&
            ($seconds % 3) === 0) 
        {
            $msent++;
            $thread->send_message("($msent/$messages_to_send) Just checking in.  All's well!");
        }
    }
    return "Okay, Thread " . $thread->id() . " is done.  Here's my result!";
}

function thread_message_handler($from_phpthread, 
                                $message)
{
    $thisthread = phpthread_this();

    if ($message === "quit") {
        $thisthread->user_data = false;
        return;
    }

    echo "\n---------------------- thread_message_handler() ------------------------\n";
    echo "CHILD (PID: " . phpthread_get_id() . ") - Message from: ";
    
    if ($from_phpthread->id() === $thisthread->parent_id()) {
        if ($from_phpthread->id() === phpthread_get_main_proc_id()) {
            echo "PARENT/MASTER (PHPthread ID: " . $from_phpthread->id() . ")\n";
        } else {
            echo "PARENT (ID: " . $from_phpthread->id() . ")\n";
        }
    } else {
        echo "PHPthread ID: " . $from_phpthread->id() . "\n";
    }
    echo trim(print_r($message, true)) . "\n";
    echo "---------------------------------------------------\n\n";
}

srand(time());

$main_id        = phpthread_get_id();

/* Vars for PHPthread 1 */
$thread1        = null;
$thread1ret     = null;
$thread1id      = 0;

/* Vars for PHPthread 2 */
$thread2        = null;
$thread2ret     = null;
$thread2id      = 0;

/**
 * There IS a 'onthreadmessage' attribute specified.  Messages from
 * this PHPthread to its parent (the MAIN process in this case) will
 * be sent to the function "messages_from_thread1()"
 */
$thread1id =
    phpthread_create(
        $thread1, 
        array(
            'onexit'=>"thread_exit_callback",
        ), 
        "threadproc",
        null,           /* No class instance or name, it's not a method */
        array(3)        /* The 2nd parameter received in "threadproc()", after it's PHPTHREAD class instance
                         * will be the number "3".  Meaning this process wants THREE messages from it.
                         */ 
    );


if ($thread1id < PHPT_SUCCESS) {
    echo "MAIN (PID: $main_id) - ERROR: [".phpthread_create_errmsg($thread1id)."] Failed to launch PHPthread!\n";
    exit($thread1id);
}


/**
 * NO 'onthreadmessage' attribute.  Messages from this PHPthread will
 * be sent to the target's (the MAIN process in this case) default 
 * message handler.
 */
$thread2id =
    phpthread_create(
        $thread2, 
        array(
            'onexit'=>"thread_exit_callback",
            'onmessage'=>"thread_message_handler",
        ), 
        "threadproc",
        null,
        array(1)        /* We want ONE message from thread 2 */
    );


if ($thread2id < PHPT_SUCCESS) {
    if ($thread1) $thread1->kill();
    echo "MAIN (PID: $main_id) - ERROR: [".phpthread_create_errmsg($thread2id)."] Failed to launch PHPthread!\n";
    exit($thread2id);
}


echo "MAIN (PID: $main_id) - Running for 20 cycles.  I will send a message to the first PHPthread at 6 cycles, and the second at 12.  Then, I'll send them both 'quit' messages after my loop.\n\n";

for ($i = 0; $i < 20; $i++) {

    $msg = phpthread_get_message(1000); /* Wait up to 1 second for a message */

    if ($msg) {
        echo "\n##################################################\n";
        echo "In MAIN loop.  phpthread_get_message() received a message.\n";
        echo "MASTER (PID: " . phpthread_get_id() . ") - Message from: ";
        
        if ($msg->thread->id() === phpthread_get_parent_id()) {
            if ($phpthread->id() === $msg->thread->main_proc_id()) {
                echo "PARENT/MASTER (PHPthread ID: " . $msg->thread->id() . ")\n";
            } else {
                echo "PARENT (ID: " . $msg->thread->id() . ")\n";
            }
        } else {
            echo "PHPthread ID: " . $msg->thread->id() . "\n";
        }
        echo trim(print_r($msg->message, true)) . "\n";
        echo "##################################################\n\n";
    }

    switch ($i) {
        case 6:     /* After six seconds, send a message to the first PHPThread */
            $thread1->send_message("Hey there, thread 1!  Just saying 'hi!'");
            break;
        case 12:    /* After twelve seconds, send a message to the first PHPThread */
            $thread2->send_message("Hola, thread 2!  Just saying 'hi!' to my fav child!");
            break;
        default:
            break;
    }
}

echo "\nMAIN (PID: $main_id) - Sending 'quit' to child PHPthreads.\n";


$thread1->send_message("quit");
$thread2->send_message("quit");

echo "\nMAIN (PID: $main_id) - Waiting on child PHPthreads to finish up.\n";

$rv1 = null;
$rv2 = null;


$jresult = phpthread_join($thread1id, $rv1);
$jresult = phpthread_join($thread2id, $rv2, 3000);

if ($jresult !== 0) {
    echo "\n**ERROR: Child thread 2 failed to quit,  Killing it.\n\n";
    $thread2->kill();
}



echo "Thread 1 returned " . strlen($rv1) . " bytes.\n";
echo "Thread 2 returned " . strlen($rv2) . " bytes.\n";
echo "\n\n";
echo "Done.\n";
exit(0);
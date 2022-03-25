FINALLY!  A library that brings the best of threading functionality to PHP!  

With this library, you can easily utilize the power of multiple CPU
cores and parallel processing within your PHP code, and easily 
communicate the results (or other runtime values/variables) between
PHPthreads.  It's great for:

* Writing server applications
* Writing daemons
* Making shorter work of comprehensive tasks

Disclaimer/Note:  The library doesn't provide 100% TRUE threading 
functionality.  Each PHPthread is a separate process, but a ton of 
work has been put into making them behave more like threads than 
anything PHP has ever seen - allowing for tight, easy code 
integration and simple and fast transfer of data between PHPthreads.

* For more in-depth examples, please check the "examples" directory. *


Simple PHPthread example:
====================================================================================================

$RUNNING = false;


function phpthread( PHPTHREAD $thread ) {
    global $RUNNING;

    srand(time());
    while ($RUNNING) {
        usleep(1000000);
        echo "\tCHILD PHPthread running - tick.\n;
    }
    echo "\nCHILD PHPthread exiting.\n";
    return "I picked " . ((string)rand(10, 1000000));
}

$phpthread      = null;
$phpthread_id   = 0;
$retval         = null;

echo "MAIN thread (PID: " . ((string)getmypid()) . ") - Starting child thread.\n";

$phpthread_id = phpthread_create($phpthread, array(), "phpthread");

if ($phpthread_id < 0) {
    echo "Failed to spawn PHPthread.  Error: " . 
        phpthread_create_errmsg($phpthread_id) . "\n\n";
    exit($phpthread_id);
}

for ($i = 0; $i < 10; $i++) {
    usleep(1000000);
    echo "MAIN PHPthread - Tick.\n";
}

/* Set value for the global variable "$RUNNING" for all threads to FALSE */
phpthread_set_global("RUNNING", false);

/* Wait for thread to finish */
phpthread_join($phpthread_id, $retval);

echo "PHPthread (ID: $phpthread_id) finished and returned: \"" . print_r($retval, true) . "\"\n\n";


====================================================================================================

Good-to-know PHPTHREAD class methods - can be called on PHPTHREAD class instances received in callback
functions and when new PHPthreads are created.

NOTE: PHPthreads that communicate - that do not share a PARENT/CHILD relationship receive PHPTHREAD_PSEUDO
class objects in their callbacks.  These pseudo classes do not have access to all information of a full-
fledged PHPTHREAD class.  For example, if one PHPthread sends a message to another - and neither is the
PARENT/CHILD of the other, a call to PHPTHREAD::parent_id() on the PHPTHREAD instance received with the
message will return "-1".  


public static function on_message(  $function_name,
                                    $class_or_instance = null);

    * Use this to set up a message handler callback for the MAIN 
    * process.  Since it's not spawned by a call to "phpthread_create()",
    * this is how you can direct received messages to a certain function 
    * if you don't plan on using "phpthread_get_message()"


public function running(): bool
    * TRUE, if the referenced thread is running, FALSE otherwise.

public function id(): int
    * Get the referenced thread's ID

public function parent_id(): int
    * Get the ID of our parent - the PHPthread/process that spawned us.

public function context(): int
    * Determine the execution context of the calling thread.
    * The return value can be one of: 
    *
    *   - PHPT_CONTEXT_CHILD -  The PHPthread itself, the one
    *                           referenced by the object.
    *   - PHPT_CONTEXT_PARENT - The PHPthread (could be the master process) that
    *                           spawned the thread referenced by the object.
    *   - PHPT_CONTEXT_MASTER - The MAIN process - if this is returned, it can be
    *                           safely assumed that this process is NOT the PARENT
    *                           of the PHPthread referenced by the object, but it is
    *                           the MAIN/MASTER process.
    *   - PHPT_CONTEXT_OTHER -  Another PHPthread that has a PHPthread reference.


public function kill(): bool
    * Terminate a PHPthread.

public function get_message( int $ms_max_wait = PHPTHREAD_NO_WAIT): null|object

    * Retrieve an enqueued message sent from another PHPthread.
    * The method returns NULL, if no messages are available to
    * retrieve.
    *
    * By default, it returns immediately.  If an argument is given, it will
    * return after that timeout.  If no messages are available, NULL is returned.
    *


public function set_global( string  $name, 
                                    $value): bool
    * Sets a GLOBAL variable across all PHPthreads started from the
    * same master/MAIN process.

public function send_master_message( $message_object ): bool
    * Shortcut function to send a message/value directly to the MAIN/MASTER process.

public function send_message(   $message_object, 
                                $pid            = null): bool

    * Send a value or message to another PHPthread.  If the second argument
    * is OMITTED, the method behaves as such:
    *
    * When called from a CHILD PHPthread's execution context, the value/message passed in
    * argument 1 is delivered to the PARENT PHPthread, which may be retrieved by either 
    * an 'onthreadmessage' callback that was given in "phpthread_create()", or by a call to 
    * "phpthread_get_message()" in the PHPthread's loop.
    *
    * When called from a PARENT PHPthread's execution context, the value/message is
    * delivered to the CHILD PHPthread, which may be retrieved by either an 'onmessage'
    * callback that was given in "phpthread_create()", or by a call to 
    * "phpthread_get_message()" in the PHPthread's loop.
    *


public function main_proc_id(): int
    * Returns PHPthread ID of the MASTER process

public function child_count(): int
    * Returns the number of running CHILD PHPthreads spawned by
    * the calling PHPthread.
<?php 
require_once __DIR__.'/../src/phpthread-class.php';

/**
 * Create a new thread of execution that runs in parallel with the 
 * calling code.
 * 
 * @param ?PHPTHREAD &$phpthread
 * A reference to a variable that will receive a PHPTHREAD object
 * if the PHPthread successfully starts.
 * 
 * @param array $attribs
 * An array of optional attributes to modify the behavior of the
 * thread's execution.  There are currently three attributes that
 * may be set.
 * 
 * !! Case sensitive !!
 * 
 * array(
 *      'onexit'            =>"function_name",  // Called in parent context when thread exits
 *      'onthreadmessage'   =>"function_name",  // Message to calling process, from THIS thread
 *      'onmessage'         =>"function_name"   // Called when the thread receives a message
 * );
 * 
 * -- or, for class methods (make sure they're public) --
 * 
 * array(
 *      'onexit'            => array($this,        "method_name"),
 *      'onthreadmessage'   => array($classobj,    "method_name"),
 *      'onmessage'         => array("CLASS_NAME", "static_method_name")
 * );
 * 
 * 'onexit' - A function to be executed in the PARENT's execution context
 * when the spawned thread exits.  The "onexit" function takes TWO arguments.
 * The prototype would be:
 * 
 * function onexit_proc( PHPTHREAD  $thread, 
 *                                  $thread_return_value );
 * 
 * Argument 1 is the PHPTHREAD object instance, argument 2 is whatever the 
 * PHPthread returned.  
 * 
 * +---------------------------------------------------------------------------+
 * | NOTE: The return value of the PHPthread is accessible to the rest of the  |
 * | parent process until "phpthread_join()" is called.                        |
 * +---------------------------------------------------------------------------+
 * 
 * 'onthreadmessage' - A function (specific to THIS PHPthread) that will be called 
 * and executed in the parent's context, if/when the thread being spawned sends a 
 * message to its parent process.  The parent process might be receiving all its 
 * messages via the "phpthread_get_message()" function, but wants to easily separate 
 * out, and handle messages from this thread differently.  In this case, it should 
 * specify an 'onthreadmessage' property when creating the PHPthread.
 * 
 * 'onmessage' - A function that will be executed in the child PHPthread's context
 * upon receipt of a message from anywhere.  If the 'onmessage' attribute is given,
 * messages received by the PHPthread will not be enqueued to be retrieved by calls
 * to either "phpthread_get_message()", or "PHPTHREAD::get_message()".  They will 
 * always return null and messages will be delivered to the function specified by
 * 'onmessage'.
 * 
 * Message-related function/method prototypes should be as such:
 * 
 * function phpthread_message(  $phpthread, 
 *                              $message);
 * 
 * Argument 1 passed to any "ON MESSAGE"-style callback will be information about
 * the PHPThread SENDING the message.  You may obtain a handle to your own
 * PHPThread object during execution of a callback by calling: "phpthread_this()"
 * 
 * +---------------------------------------------------------------------------+
 * | NOTE: Do NOT specify the TYPE for argument 1 of attribute functions!      |
 * +---------------------------------------------------------------------------+
 * |                                                                           |
 * | It *might* be a "PHPTHREAD" instance, or it MIGHT be an instance of       |
 * | "PHPTHREAD_PSEUDO", which carries many of the same methods, but is        |
 * | fundamentally different in how it works!                                  |
 * |                                                                           |
 * | You don't want to crash because of a type error!                          |
 * |                                                                           |
 * +---------------------------------------------------------------------------+
 * 
 * 
 * 
 * @param string $entry_point
 * The name of a function or method to be the starting point of the PHPthread's 
 * execution.  
 * 
 * +---------------------------------------------------------------------------+
 * | NOTE: The prototype for a PHPthread's entry point function is as follows: |
 * +---------------------------------------------------------------------------+
 * |                                                                           |
 * | function phpthread_entry_point( PHPTHREAD $phpthread [, params...] );     |
 * |                                                                           |
 * +---------------------------------------------------------------------------+
 * 
 * @param object|string|null (Optional) $class_or_instance
 * If a class method is to be used as the PHPthread's entry point, an instance of
 * the class must be proviced here.
 * 
 * If the PHPthread's entry point is to be a static class method, then just give
 * the name of the class here in "quotes" - as a string.  If a function it to be
 * the entry point, pass NULL.  The default is NULL.
 * 
 * @param array $params
 * All arguments to be passed to the new PHPthread should be enclosed in an array
 * here for the fifth argument.
 * 
 * @param mixed $user_data
 * User data.  May be anything you'd like to be accessible via a reference to the
 * PHPTHREAD class instance.  The value is accessible as such:
 * 
 * function thread_entry_point( PHPTHREAD $phpthread ) {
 * 
 *      print_r($phpthread->user_data);
 * 
 * }
 * 
 * The PHPthread inherits a copy of the user data.  It DOES NOT share address space
 * with the parent process, so changes made to the data in the PHPthread are not
 * apparent to the parent process.
 * 
 * That being said, you THREE ways of sharing data between PHPthreads:
 * 
 * - phpthread_set_global() - Sets or changes global variables visible to all
 *   PHPthreads.
 * 
 * - phpthread_send_message() - Sends messages quickly and efficiently between PHPthreads.
 *   (Personally, I like sending JSON objects that contain whatever data I need to send
 *   It makes it SUPER easy to update existing data structures!)
 * 
 * - Thread return values.  Thread return values can be evaluated by the parent process
 *   in 'onexit' attribute functions, OR when the parent calls phpthread_join().
 * 
 * @return int
 * On success, the PHPthread ID (process ID) of the newly-launched PHPthread is returned.
 * If the return value is a NEGATIVE integer, an error occured.  
 * 
 * Errors can be one of the following:
 * 
 *  - PHPT_NOT_INITIALIZED  -   The library isn't properly initialized. Either 
 *                              "new PHPTHREAD()" wasn't called by the calling process
 *                              correctly, or there was an unexpected error initializing 
 *                              the library.
 * 
 *  - PHPT_SOCK_FAILED      -   A call to "unixlisten()" in src/phpthread-ipc.php failed and
 *                              a required UNIX socket could not be created.
 * 
 *  - PHPT_BAD_THREADPROC   -   The specified entry point for the PHPthread isn't a valid 
 *                              function or method.
 * 
 *  - PHPT_FORK_ERROR       -   A call to "fork()" failed and the PHPthread couldn't be 
 *                              started.
 * 
 *  - PHPT_START_TIMEOUT    -   A timeout occured trying to synchronize the start of the
 *                              PHPthread.  The PHPthread failed to let the parent process
 *                              know that it was initialized and ready to run.
 * 
 *  - PHPT_THREAD_GONE      -   The PHPthread encountered some kind of unexpected error and 
 *                              self-destructed.  It's not running anymore, nothing to clean
 *                              up, nothing to do....   nothing.                    noth-
 *                              ing.
 * 
 */
function phpthread_create(  ?PHPTHREAD  &$phpthread,
                            array       $attribs,
                            string      $entry_point,
                                        $class_or_instance  = null,
                            array       $params             = array(),
                                        $user_data          = null)
{
    $phpthread      = null;
    $start_routine  = PHPTHREAD::mkcallable($entry_point, $class_or_instance);
    $fail_ctr       = 0;

    if ($start_routine === null) {
        return PHPT_BAD_THREADPROC;
    }

    for (; $fail_ctr < 3; $fail_ctr++) {
        $new_thread = new PHPTHREAD( $phpthread, $attribs, $entry_point, $class_or_instance, $params, $user_data );
        if ($new_thread->pid > 0) {
            return $new_thread->pid;
        }
    }
    return $new_thread->errornum;
}

/**
 * Translates an error code from a call to "phpthread_create()" into
 * a readable string to compare to documentation for troubleshooting.
 * 
 * @return string
 * A string representation of an error code returned by phpthread_create();
 * 
 */
function phpthread_create_errmsg( int $errcode ) {
    $ec = -$errcode;
    if ($ec < 0 ||
        $ec >= count(PHPTHREAD_CREATE_ERRORS))
    {
        return "UNKNOWN ERROR CODE";
    }
    return PHPTHREAD_CREATE_ERRORS[$ec];
}

/**
 * Retrieve a message from the PHPthread's message-delivery queue.  Will
 * wait for number of milliseconds given in argument 1 if no messages
 * are available.
 * 
 * @param int $ms_wait
 * The number of milliseconds to wait for a message before returning.
 * 
 * @return object
 * Returns data passed to the calling PHPthread from another.
 * 
 * (object)array(
 *      'thread' => PHPTHREAD class object,
 *      'message' => [string|bool|object|array|int|float]
 * )
 * 
 * Example:
 * 
 * $msg = phpthread_get_message();
 * 
 * if ($msg) {
 *      echo "Message from thread: " . (string)$msg->thread->pid . "\n";
 *      print_r($msg->message);
 *      echo "\n--------------------------------------------------------\n\n";
 * }
 * 
 * 
 */
function phpthread_get_message( int $ms_wait = PHPTHREAD_NO_WAIT ) {
    $obj = phpt_this_thread_object();
    if (!$obj) return null;
    return $obj->get_message($ms_wait);
}

/**
 * Returns the execution context i.e. tells you whether the code
 * running is in the parent process, child process, or another 
 * PHPThread.  In some cases, it's necessary for both a master
 * process and its PHPThread(s) to call the same function.  Some-
 * times, in this scenario, the function may need to know whether
 * it's been called by the parent or the PHPThread.
 * 
 * @return int (enum)
 *      - PHPT_CONTEXT_CHILD    -   The PHPThread's context
 *      - PHPT_CONTEXT_PARENT   -   The spawning process of the calling
 *                                  PHPThread.
 *      - PHPT_CONTEXT_MASTER   -   The MASTER process.  If the MASTER 
 *                                  process is the PARENT process also,
 *                                  PHPT_CONTEXT_PARENT will be returned
 *                                  and MASTER status may be tested for
 *                                  with "phpthread_is_master_process()."
 * 
 */
function phpthread_get_context() {
    return phpthread_this()->context();
}

/**
 * Send a message to another PHPthread.
 * 
 * @param int $target_pid
 * The PHPthread (process) ID of the message's target.
 * 
 * @param string|bool|object|array|int|float $message
 * The value to send to the MASTER process.
 * 
 * @return bool
 * TRUE on success, FALSE on failure.
 * 
 */
function phpthread_send_message(int $target_pid,
                                $message)
{
    return phpt_send_message($target_pid, (object)array(
        'type'=>PHPT_MESSAGE_IPC,
        'data_type'=>phpt_data_type($message),
        'data'=>$message
    ));
}

/**
 * Send a message to the MAIN process.
 * 
 * @param string|bool|object|array|int|float $message
 * The value to send to the MASTER process.
 * 
 * @return bool
 * TRUE on success, FALSE on failure.
 * 
 */
function phpthread_send_master_message( $message ) {
    return phpthread_send_message(phpt_main_pid(), $message);
}

/**
 * Set the value of a GLOBAL variable, visible as a regular global variable
 * to all PHPthreads.
 * 
 * @param string $global_name
 * The name of the global variable to change or set.
 * 
 * @param string|bool|object|array|int|float $new_value
 * The value to set the global variable to.
 * 
 * @return bool
 * TRUE upon success, FALSE on failure.
 * 
 */
function phpthread_set_global( string   $global_name,
                                        $new_value)
{
    $obj = phpt_this_thread_object();
    if (!$obj) return null;
    return $obj->set_global($global_name, $new_value);
}

/**
 * Waits for all PHPthread created by the calling PHPthread (process) to
 * exit before continuing.
 * 
 * @return object
 * Each key of the object is the PHPthread ID of an exited PHPthread.  The
 * value is the return value of the PHPthread.
 * 
 * Example return value:
 * 
 * (object)array(
 *      '1445'=>"This is the return value for PHPthread ID 1445",
 *      '24438'=>245,
 *      '33001'=>(object)array(
 *                  'anykey'=>"anyval",
 *                  'anotherkey'=>"anotherval"
 *              )
 * )
 * 
 * 
 */
function phpthread_join_all() {
    $retobj = new stdClass();
    $wait_array = array();
    $thisobject = phpt_this_thread_object();

    if ($thisobject === null) {
        echo "ERROR: No PHPTHREAD object associated with this thread!\n";
        return null;
    }

    /** Clean out "exited_children" as well **/
    foreach ($thisobject->exited_children as $k=>$v) {
        $retobj->{$k} = $v->return_value;
    }

    foreach ($retobj as $k=>$v) {
        unset($thisobject->exited_children->{$k});
    }

    if ($thisobject->starting_thread) {
        array_push($wait_array, $thisobject->starting_thread->pid);
    }

    foreach ($thisobject->running_children as $k=>$v) {
        array_push($wait_array, $k);
    }

    foreach ($wait_array as $tid) {
        $retobj->{$tid} = null;
        phpthread_join($tid, $retobj->{$tid});
    }
    return $retobj;
}

/**
 * Waits for a PHPthread, referenced by param 1 "$thread_id" to complete its execution.
 * The return value of the referenced PHPthread will be placed into the variable 
 * referenced by param 2.  This function cannot be called willy-nilly with any PID, 
 * the PID given must be a child thread directly created by the calling PHPthread.
 * 
 * @param int $thread_id
 * The PHPthread ID of a PHPthread created by the calling PHPthread for which 
 * execution of the calling PHPthread will pause until the referenced child terminates.
 * 
 * @param mixed &$retval
 * A reference to a variable into which the return value of the PHPthread referenced
 * by param 1 will be placed.
 * 
 * @param int (Optional) $ms_timeout
 * This optional argument allows you to specify a number, in milliseconds, the function 
 * will wait before returning with PHPT_JOIN_TIMEOUT - indicating that the PHPthread didn't 
 * terminate in the time given.  If the argument is not specified, the default value is 
 * the constant PHPTHREAD_JOIN_WAIT_INFINITE - meaning the function will wait until the
 * PHPthread is done - however long that takes.
 * 
 * 
 * @return int
 * Returns one of the following values:
 * 
 *  PHPT_JOIN_SUCCESS               -   The PHPthread terminated normally.
 *  PHPT_JOIN_TIMEOUT               -   The given timeout has expired without the PHPthread having
 *                                      terminated, i.e. it's still running.
 *  PHPT_THREAD_NOT_FOUND           -   The PHPthread referenced by param 2 could not be found.
 * 
 */
function phpthread_join(int $thread_id, 
                            &$retval,
                        int $ms_timeout = PHPTHREAD_JOIN_WAIT_INFINITE)
{
    $tobj = phpt_this_thread_object();
    $wobj = null;
    $retval = null;
    $timer = ftimer();

    if ($tobj === null) {
        echo "ERROR: No PHPTHREAD object for this process.\n";
        return PHPT_NO_PHPTHREAD_CLASS;
    }

    if (property_exists($tobj->running_children, $thread_id)) {
        $wobj = $tobj->running_children->{$thread_id};
    } else if (property_exists($tobj->exited_children, $thread_id)) {
        $wobj = $tobj->exited_children->{$thread_id};
        unset($tobj->exited_children->{$thread_id});
        $retval = $wobj->return_value;
        return PHPT_JOIN_SUCCESS;
    } else if ($tobj->starting_thread &&
                $tobj->starting_thread->pid === $thread_id)
    {
        $wobj = $tobj->starting_thread;
    }

    if (!$wobj) {
        return PHPT_THREAD_NOT_FOUND;
    }

    $timer->reset();
    while (1) {
        if (property_exists($tobj->exited_children, $thread_id)) {
            $wobj = $tobj->exited_children->{$thread_id};
            unset($tobj->exited_children->{$thread_id});
            $tobj->nex_children--;
            $retval = $wobj->return_value;
            return PHPT_JOIN_SUCCESS;
        }
        if (!property_exists($tobj->running_children, $thread_id) &&
            ($tobj->starting_thread === null ||
            $tobj->starting_thread->pid !== $thread_id))
        {
            if (!property_exists($tobj->exited_children, $thread_id)) {
                return PHPT_THREAD_NOT_FOUND;
            }
        }
        if ($ms_timeout > -1) {
            if ($timer->ms() >= $ms_timeout) {
                return PHPT_JOIN_TIMEOUT;
            }
        }
        usleep(50);
    }
}

/**
 * phpthread/phpthreads.php
 * 
 * Trys to join to all the PHPthread whose IDs are given in 
 * the array "$pid_array" (param 1).
 * 
 * @param array &$pid_array
 * A REFERENCE to an array of integer PHPthread IDs for which we'll wait 
 * (up to $ms_timeout - if applicable) to finish.  PHPthread IDs in the
 * array of PHPthreads that have successfully terminated will be removed
 * from the array; ideally, if all PHPthreads exit (and on-time, if 
 * $ms_timeout is a positive integer), the given array would be empty
 * when control is passed back to the caller.
 * 
 * @param int $ms_timeout {Optional}
 * The max time (in milliseconds) this function is allowed to wait for
 * the PHPthreads referenced in the first parameter to finish up.  If the
 * time limit expires before all PHPthreads are done, this function will
 * return.  By default, the value "PHPTHREAD_JOIN_WAIT_INFINITE"
 * means no timeout; this function will wait until all PHPthreads have 
 * finished.
 * 
 * @return object
 * Each key of the object is the PHPthread ID of an exited therad.  The
 * value is the return value of the PHPthread.
 * 
 * Example return value:
 * 
 * (object)array(
 *      '1445'=>"This is the return value for PHPthread ID 1445",
 *      '24438'=>245,
 *      '33001'=>(object)array(
 *                  'anykey'=>"anyval",
 *                  'anotherkey'=>"anotherval"
 *              )
 * )
 * 
 */
function phpthread_join_pid_list(   array   &$pid_array,
                                    int     $ms_timeout = PHPTHREAD_JOIN_WAIT_INFINITE)
{
    $timer  = (($ms_timeout > 0)?ftimer():null);
    $rv     = null;
    $wait   = 0;
    $robj   = new stdClass();

    while (count($pid_array) > 0) {
        if ($timer) {
            if ( ($wait = $ms_timeout - $timer->ms()) < 0 ) {
                $wait = 0;
            }
        } else {
            $wait = PHPTHREAD_JOIN_WAIT_INFINITE;
        }
        if (phpthread_join((int)$pid_array[0], $rv, $wait) === PHPT_JOIN_SUCCESS) {
            $robj->{(int)$pid_array[0]} = $rv;
            array_splice($pid_array, 0, 1);
        }
    }
    return $robj;
}

/**
 * Get the PHPthread ID of the calling process.
 * 
 * @return int
 * On success, a positive integer is returned representing
 * the ID of the calling PHPthread.  On failure, -1 is returned
 * meaning the calling process wasn't created with a call to
 * "phpthread_create()."
 * 
 */
function phpthread_get_id() {
    $obj = phpt_this_thread_object();
    if (!$obj) return -1;
    return $obj->pid;
}

/**
 * Get the integer ID of the PHPthread or process that
 * spawned the calling PHPthread.  If this function is 
 * called by the MAIN process, ZERO will be returned.
 * 
 * @return int
 * The ID of our parent, or ZERO if called by the MAIN 
 * process.
 * 
 */
function phpthread_get_parent_id() {
    $obj = phpt_this_thread_object();
    if (!$obj) return -1;
    return $obj->parent_pid;
}

/**
 * Returns the PHPthread ID of the MASTER process.
 * 
 * @return int
 * The PHPthread ID (process ID) of the MASTER process.
 */
function phpthread_get_main_proc_id() {
    return phpt_main_pid();
}

/**
 * Get a reference to the current PHPthread's PHPTHREAD
 * class instance.
 * 
 * @return PHPTHREAD|null
 * Returns the PHPTHREAD instance for the calling process, or NULL
 * if the process wasn't spawned using this library.
 * 
 */
function phpthread_this() {
    return phpt_this_thread_object();
}

/**
 * Determine how many child PHPthreads launched by the calling PHPthread
 * are still running.  If this is called on a PHPTHREAD_PSEUDO object (i.e.
 * the PHPTHREAD object passed to a message callback that is from a 
 * PHPthread that is neither a PARENT or CHILD of the receiving PHPthread),
 * the result will be "-1."  Meaning "I don't know."
 * 
 * @return int
 * The number of children PHPthreads still running.
 * 
 */
function phpthread_thread_count() {
    $obj = phpt_this_thread_object();
    if (!$obj) return -1;
    return $obj->child_count();
}

/**
 * Quick helper in case you lose track playing with PHPThreads! 
 * 
 * @return bool
 * TRUE if the calling PHPThread is the MASTER process, FALSE
 * otherwise.
 * 
 */
function phpthread_is_master_process(): bool {
    return ((phpthread_get_main_proc_id()===getmypid())?true:false);
}

/**
 * Function call to immediately terminate the execution of a PHPThread
 * and return the given value.  Useful if you need to abort a PHPThread
 * from a function other than its "entry_point" function due to some
 * error, or whatever.
 * 
 * The function doesn't return.
 * 
 */
function phpthread_exit( $return_value ) {
    phpthread_this()->exit($return_value);
}
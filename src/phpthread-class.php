<?php 
require_once __DIR__."/phpthread-config.php";
require_once __DIR__."/phpthread-ipc.php";

if (PHP_OS_FAMILY === "Windows") {
    echo "\n** ERROR: The \"phpthread\" library has only been tested on Linux-like systems; major functionality depends on the sending of signals.  It has not been tested on Windows.  (Please feel free to change this code if you want to monkey around!)\n\n";
    exit(1);
}

$__THREAD_OBJECT__  = null;             /* A reference to the running thread's PHPTHREAD object */
$MAIN_PROC_PID      = @\getmypid();       /* The main process' PID */

class PHPTHREAD_PSEUDO {
    public $pid         = 0;
    public $parent_pid  = 0;

    public function id() {
        return $this->pid;
    }

    public function parent_id() {
        return $this->parent_pid;
    }

    public function main_proc_id() {
        return @\phpt_main_pid();
    }

    public function running() {
        return @\posix_kill($this->pid, SIGBEGIN);
    }

    public function child_count() {
        return -1;
    }

    public function kill() {
        return @\posix_kill($this->pid, SIGKILL);
    }

    public function set_global( string  $name, 
                                        $value,
                                int     $pid_to_skip = 0)
    {
        $obj = @\phpt_this_thread_object();
        if (!$obj) return null;
        return $obj->set_global($name, $value, $pid_to_skip);
    }

    public function __get( $name ) {
        return null;
    }

    public function context() {
        $thispid        = @\getmypid();
        $this_thread    = @\phpt_this_thread_object();

        if (!$this_thread) {
            return PHPT_CONTEXT_OTHER;
        }

        if ( $this_thread->get_child($this->pid) !== null ) {
            return PHPT_CONTEXT_PARENT;
        }

        if ($thispid === @\phpt_main_pid()) {
            return PHPT_CONTEXT_MASTER;
        }
        
        return PHPT_CONTEXT_OTHER;
    }

    public function send_master_message( $message_object ) {
        return @\phpt_send_message(@\phpt_main_pid(), (object)array(
            'type'=>PHPT_MESSAGE_IPC,
            'data_type'=>@\phpt_data_type($message_object),
            'data'=>$message_object
        ));
    }

    public function send_message( $message ) {
        /* This will only send a message to the thread */
        return @\phpt_send_message($this->pid, (object)array(
            'type'=>PHPT_MESSAGE_IPC,
            'data_type'=>@\phpt_data_type($message),
            'data'=>$message
        ));
    }


    public function __call( $name, 
                            $arguments)
    {
        return null;
    }

    public function __construct($pid, 
                                $parent_pid ) 
    {
        $this->pid = $pid;
        $this->parent_pid = $parent_pid;
    }
}

class PHPTHREAD {
    public static $main_thread  = null;
    public  $main_pid           = 0;
    public  $parent_pid         = 0;
    public  $pid                = 0;
    public  $onexit             = null;
    public  $onthreadmessage    = null;
    public  $onmessage          = null;
    public  $running_children   = null;
    public  $exited_children    = null;
    public  $nex_children       = 0;
    public  $listener_socket    = null;
    public  $return_value       = null;
    public  $return_memory      = null;
    public  $okay_to_run        = false;
    public  $user_data          = null;
    public  $messages           = array();
    public  $errornum           = PHPT_SUCCESS;
    /** @var PHPTHREAD */
    public  $starting_thread    = null;


    public static function on_message(  $function_name,
                                        $class_or_instance = null)
    {
        $tobj = @\phpt_this_thread_object();
        if ($tobj->pid !== @\phpt_main_pid()) {
            return false;
        }
        $onmessage = PHPTHREAD::mkcallable($function_name, $class_or_instance);
        if ($onmessage) {
            $tobj->onmessage = $onmessage;
            return true;
        }
        return false;
    }

    public static function mkcallable(  $function, 
                                        $class_or_instance = null )
    {    
        $entry_point = null;

        if (@\is_string($function)) {
            if ($class_or_instance === null) {
                $entry_point = $function;
            } else if (@\is_string($class_or_instance)) {
                $entry_point = array($class_or_instance, $function);
            } else if (@\is_object($class_or_instance)) {
                $entry_point = array($class_or_instance, $function);
            } else {
                return null;
            }
            return $entry_point;
        } else if (@\is_array($function)) {
            if (@\is_callable($function)) {
                return $function;
            }
        }
        return null;
    }

    public function id() {
        return $this->pid;
    }

    public function parent_id() {
        return $this->parent_pid;
    }

    public function main_proc_id() {
        return @\phpt_main_pid();
    }

    private function apply_attribs( $attrib_array ) {
        if (!@\is_array($attrib_array)) {
            return false;
        }
        foreach ($attrib_array as $key=>$val) {
            switch ($key) { 
                case "onexit":
                    /**
                     * The specified function runs in the parent's context and
                     * is called when this thread terminates.
                     */
                    $this->onexit = PHPTHREAD::mkcallable($val);
                    break;
                case "onthreadmessage":
                    /** 
                     * This is specifying a specific target function (that runs in 
                     * the parent context) to which messages from this child will
                     * be delivered.  This will run inside an interrupt, so no threads
                     * may be spawned.
                     **/
                    $this->onthreadmessage = PHPTHREAD::mkcallable($val);
                    break;
                case "onmessage":
                    /**
                     * This specifies a function to which messages sent TO this thread
                     * will be delivered.  The function will be executed inside an 
                     * interrupt, so no threads may be spawned.
                     */
                    $this->onmessage = PHPTHREAD::mkcallable($val);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Child function waits until parent is ready for it to run.
     */
    private function wait_for_exec_auth() {
        $timer = @\ftimer();
        while ($timer->ms() <= 500) {
            if ($this->okay_to_run === true) {
                return true;
            }
            @\usleep(50);
        }
        return false;
    }

    public function child_count() {
        return @\count((array)$this->running_children);
    }

    /**
     * SIGCHLD SHOULD ONLY RUN IF ALL SIGDATA IS HANDLED!
     * -------------------------------------------------
     * 
     */
    public function __process_interrupt__($signo) {
        global $__THREAD_OBJECT__;

        $pid = 0;
        $exc = null;

        while ($this->check_socket());

        if ($signo === SIGBEGIN) {
            $obj = $__THREAD_OBJECT__;
            if ($obj && 
                $obj->starting_thread) 
            {
                $obj->running_children->{$obj->starting_thread->pid} = $obj->starting_thread;
                $obj->starting_thread->okay_to_run = true;
                $obj->starting_thread = null;
            } else if ($this->context() === PHPT_CONTEXT_CHILD) {
                $this->okay_to_run = true;
            }
            return;
        }

        do {
            $status = 0;
            $pid = @\pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid > 0) {
                $exc = null;
                if (@\property_exists($this->running_children, $pid)) {
                    $exc = $this->running_children->{$pid};
                    unset($this->running_children->{$pid});
                } else if ( $this->starting_thread && 
                            $this->starting_thread->pid === $pid) 
                {
                    $exc = $this->starting_thread;
                    $this->starting_thread = null;
                } 
                if ($exc) {
                    if ($this->nex_children < 100) {
                        $this->exited_children->{$exc->pid} = $exc;
                        $this->nex_children++;
                    }
                    if ($exc->onexit !== null) {
                        @\call_user_func_array($exc->onexit, array($exc, $exc->return_value));
                    }
                    @\phpt_delete_socket_file($exc->pid);
                }
            }
        } while ($pid > 0);
    }

    public function context() {
        $thispid = @\getmypid();

        if ($thispid === $this->pid) {
            return PHPT_CONTEXT_CHILD;
        } else if ($thispid === $this->parent_pid) {
            return PHPT_CONTEXT_PARENT;
        } else if ($thispid === @\phpt_main_pid()) {
            return PHPT_CONTEXT_MASTER;
        } else {
            return PHPT_CONTEXT_OTHER;
        }
    }

    public function kill() {
        return @\posix_kill($this->pid, SIGKILL);
    }

    /**
     * Changed this to public so that PHPThreads have a
     * way to just quit and return a value if they want.
     */
    public function exit( $retval ) {
        switch ($this->context()) {
            case PHPT_CONTEXT_CHILD:
                $this->__send_return_val_($retval);
                $this->kill();
                break;
            case PHPT_CONTEXT_PARENT:
            case PHPT_CONTEXT_MASTER:
                break;
            default:
                return false;
        }
    }

    public function running() {
        return $this->okay_to_run;
    }

    public function get_child( $pid ) {
        $pid = (int)$pid;
        foreach ($this->running_children as $tid=>$obj) {
            if ((int)$tid === $pid) {
                return $obj;
            }
        }
        return null;
    }

    private function process_rx_buffer( string $buffer ) {
        $obj = @\json_decode($buffer);

        if (!$obj) {
            return false;
        }

        /**
         * This will only be populated if it's a child of ours.
         */
        $from_thread = null;

        if (@\property_exists($this->running_children, $obj->from_pid)) {
            $from_thread = $this->running_children->{$obj->from_pid};
        }

        switch ($obj->type) {
            case PHPT_MESSAGE_SET_GLOBAL:
                $this->set_global($obj->data_name, $obj->data, $obj->from_pid);
                break;
            case PHPT_MESSAGE_RETURN:
                if ($from_thread) {
                    if ($obj->data_type === "bool") {
                        if ((int)$obj->data > 0) {
                            $from_thread->return_value = true;
                        } else {
                            $from_thread->return_value = false;
                        }
                    } else {
                        $from_thread->return_value = $obj->data;
                    }
                }
                break;
            case PHPT_MESSAGE_IPC:
                $message_proc = null;
                if ($from_thread) {
                    if ($from_thread->onthreadmessage) {
                        $message_proc = $from_thread->onthreadmessage;
                    }
                }
                if ($message_proc === null) {
                    if ($this->onmessage !== null) {
                        $message_proc = $this->onmessage;
                    }
                }
                if ($message_proc) {
                    @\call_user_func_array(
                        $message_proc, 
                        array( 
                            (($from_thread)?$from_thread:new PHPTHREAD_PSEUDO($obj->from_pid, $obj->parent_pid)), 
                            $obj->data
                        )
                    );
                } else {
                    /* Save the message to the thread's queue */
                    @\array_push(
                        $this->messages,
                        array(
                            (($from_thread)?$from_thread:new PHPTHREAD_PSEUDO($obj->from_pid, $obj->parent_pid)), 
                            $obj->data
                        )
                    );
                }
                break;
            default:
                break;
        }

        return true;

    }

    public function get_message( int $ms_max_wait = PHPTHREAD_NO_WAIT) {
        static $object = null;

        $timer = @\ftimer();

        if ($object === null) {
            $object = (object)array(
                'thread'=>null,
                'message'=>null
            );
        }

        while ( @\count($this->messages) === 0 &&
                $ms_max_wait !== PHPTHREAD_NO_WAIT &&
                $timer->ms() < $ms_max_wait)
        {
            @\usleep(50);
        }

        if (@\count($this->messages) > 0) {
            $object->thread = ($this->messages[0])[0];
            $object->message = ($this->messages[0])[1];
            @\array_splice($this->messages, 0, 1);
            return $object;
        }
        return null;
    }

    public function set_global( string  $name, 
                                        $value,
                                int     $pid_to_skip = 0)
    {
        $GLOBALS[$name] = $value;

        if ($this->parent_pid > 0 && 
            $pid_to_skip !== $this->parent_pid)
        {
            @\phpt_send_message(
                $this->parent_pid, 
                (object)array(
                    'type'=>PHPT_MESSAGE_SET_GLOBAL,
                    'data_name'=>$name,
                    'data_type'=>@\phpt_data_type($value),
                    'data'=>$value
            ));
        }

        foreach ($this->running_children as $pid=>$obj) {
            if ((int)$pid === $pid_to_skip) {
                continue;
            }
            @\phpt_send_message(
                $pid, 
                (object)array(
                    'type'=>PHPT_MESSAGE_SET_GLOBAL,
                    'data_name'=>$name,
                    'data_type'=>@\phpt_data_type($value),
                    'data'=>$value
            ));
        }
        return true;
    }

    private function check_socket() {
        static $timer = null;

        if ($timer === null) {
            $timer = @\ftimer();
        }

        $rx_buffer  = "";
        $bread      = "";
        $action     = false;
        $timer->reset();

        if (!$this->listener_socket) {
            return;
        }

        $ar = array($this->listener_socket);
        $aw = array();
        $ae = array();
        $sv = @\stream_select($ar, $aw, $ae, 0, 0);
        if ($sv > 0) {
            $client = @\stream_socket_accept($this->listener_socket);
            if ($client) {
                $action = true;
                do {
                    $car = array($client);
                    $caw = array();
                    $cae = array();
                    $sv = @\stream_select($car, $caw, $cae, 0, 5000);
                    if ($sv) {
                        $bread = @\fread($client, 65535);
                        if ($bread !== "") {
                            $rx_buffer.=$bread;
                            $timer->reset();
                        } else {
                            break;
                        }
                    } else if ($sv === false) {
                        break;
                    } else if ($timer->secs() > 5) {
                        break;
                    }
                } while (1);
                @\phpt_close_socket($client);
                $this->process_rx_buffer($rx_buffer);
            }
        }
        return $action;
    }

    public function __destruct() {
        switch ($this->context()) {
            case PHPT_CONTEXT_CHILD:
                /**
                 * Shut down all my children!
                 */
                @\phpt_close_socket($this->listener_socket);
                @\phpt_delete_socket_file($this->pid);
                break;
            default:
                break;
        }
    }

    private function __alert_parent_running__() {
        return @\posix_kill($this->parent_pid, SIGBEGIN);
    }

    private function __send_return_val_( $retval ) {
        return @\phpt_send_message($this->parent_pid, (object)array(
            'type'=>PHPT_MESSAGE_RETURN,
            'data_type'=>@\phpt_data_type($retval),
            'data'=>$retval
        ));
    }

    private function __auth_child_exec__( $tid ) {
        return @\posix_kill($tid, SIGBEGIN);
    }

    private function wait_for_child_launch() {
        $timer = @\ftimer();

        if ($this->context() === PHPT_CONTEXT_CHILD ||
            $this->context() === PHPT_CONTEXT_OTHER)
        {
            return false;
        }

        while ($timer->ms() <= 500) {
            if ($this->okay_to_run === true) {
                return true;
            }
            usleep(50);
        }
        return false;
    }

    public function send_master_message( $message_object ) {
        return @\phpt_send_message(phpt_main_pid(), (object)array(
            'type'=>PHPT_MESSAGE_IPC,
            'data_type'=>@\phpt_data_type($message_object),
            'data'=>$message_object
        ));
    }

    public function send_message(   $message_object, 
                                    $pid = null)
    {
        $target_pid = null;

        if ($pid === null) {
            switch ($this->context()) {
                case PHPT_CONTEXT_CHILD:
                    $target_pid = $this->parent_pid;
                    break;
                case PHPT_CONTEXT_PARENT:
                case PHPT_CONTEXT_MASTER:
                case PHPT_CONTEXT_OTHER:
                    $target_pid = $this->pid;
                    break;
                default:
                    return false;
            }
        } else {
            $target_pid = $pid;
        }

        return @\phpt_send_message($target_pid, (object)array(
            'type'=>PHPT_MESSAGE_IPC,
            'data_type'=>@\phpt_data_type($message_object),
            'data'=>$message_object
        ));
    }

    public function __construct(            &$phpthread         = null,
                                array       $attribs            = null,
                                string      $entry_point        = null,
                                            $class_or_instance  = null,
                                array       $params             = array(),
                                            $user_data          = null)
    {
        global  $__THREAD_OBJECT__;

        $mypid  = @\getmypid();
        $errno  = 0;
        $errstr = "";
        $this_thread = @\phpt_this_thread_object();
        $pass_params = array();
        $start_proc  = PHPTHREAD::mkcallable($entry_point, $class_or_instance);

        $phpthread = $this;
        

        $this->running_children = new stdClass();
        $this->exited_children  = new stdClass();
        $this->main_pid         = @\phpt_main_pid();

        /* If this is the main process and this is first thread, set up everything. */
        if ($mypid === @\phpt_main_pid() &&
            PHPTHREAD::$main_thread === null)
        {
            $__THREAD_OBJECT__ = $this;
            PHPTHREAD::$main_thread = $this;

            pcntl_signal(SIGDATA,   PHPTHREAD::mkcallable("__process_interrupt__",  $this), true);
            pcntl_signal(SIGBEGIN,  PHPTHREAD::mkcallable("__process_interrupt__",  $this), true);
            pcntl_signal(SIGCHLD,   PHPTHREAD::mkcallable("__process_interrupt__",  $this), true);

            $this->pid = $mypid;
            $this->okay_to_run = true;
            $this->listener_socket = unixlisten($errno, $errstr);
            if ($this->listener_socket === null) {
                $this->errornum = PHPT_SOCK_FAILED;
                fatal("Failed to create IPC listener socket: [$errno] - \"$errstr\"");
            }
            return;
        }

        if ($this_thread === null) {
            $this->errornum = PHPT_NOT_INITIALIZED;
            echo "ERROR: Cannot spawn a thread - Failed to get the PHPIPC object for this thread!\n";
            $this->pid = 0;
            $this->okay_to_run = false;
            $phpthread = null;
            return;
        }

        if ($start_proc === null) {
            $this->errornum = PHPT_BAD_THREADPROC;
            echo "ERROR: PHPTHREAD - Invalid thread entry point.\n";
            $this->pid = 0;
            $this->okay_to_run = false;
            $phpthread = null;
            return;
        }

        $this->apply_attribs($attribs);

        $this->parent_pid = @\getmypid();
        $this->pid = @\pcntl_fork();
        @\fflush(STDOUT);

        $this_thread->starting_thread = $this;
        $this->user_data = $user_data;

        /* Here if we're in the child process */
        if ($this->pid === 0) {
            @\pcntl_signal(SIGDATA,   PHPTHREAD::mkcallable("__process_interrupt__", $this), true);
            @\pcntl_signal(SIGBEGIN,  PHPTHREAD::mkcallable("__process_interrupt__", $this), true);
            @\pcntl_signal(SIGCHLD,   PHPTHREAD::mkcallable("__process_interrupt__", $this), true);

            /* In child process */

            $this->pid = @\getmypid();
            $__THREAD_OBJECT__ = $this;
            $this->listener_socket = @\unixlisten($errno, $errstr);

            if ($this->listener_socket === null) {
                @\fatal("(PHPthread) Failed to create IPC listener socket: [$errno] - \"$errstr\"");
                $this->kill();
            }

            /* Send message to parent that we're running so it known we can receive signals */
            if ($this->__alert_parent_running__() !== true) {
                echo "(PHPthread) Failed to send message to PARENT!\n\n";
                $this->kill();
            }

            /* Wait for SIGBEGIN - parent telling us to run! */
            if ($this->wait_for_exec_auth() === false) {
                $this->kill();
            }

            @\array_push($pass_params, $this);

            foreach ($params as $p) {
                @\array_push($pass_params, $p);
            }
            
            $retval = @\call_user_func_array($start_proc, $pass_params);
            $this->exit($retval);

            /************************************************************************************************************************************/
            /*                                                  End CHILD THREAD EXECUTION                                                      */
            /************************************************************************************************************************************/

        } else if ($this->pid === -1) {
            $this->errornum = PHPT_FORK_ERROR;
            $this->pid = 0;
            $this->okay_to_run = false;
            $this_thread->starting_thread = null;
            $phpthread = null;
            return;
        }

        /* This is ONLY executed in the parent context of a thread */

        if ($this->wait_for_child_launch() === false) {
            $this_thread->starting_thread = null;
            $this->errornum = PHPT_START_TIMEOUT;
            $this->kill();
            $this->pid = 0;
            $this->okay_to_run = false;
            $phpthread = null;
            return;
        }

        if (!$this->__auth_child_exec__($this->pid)) {
            $this_thread->starting_thread = null;
            $this->errornum = PHPT_THREAD_GONE;
            $this->kill();
            $this->pid = 0;
            $this->okay_to_run = false;
            $phpthread = null;
            return;
        }
        $this->errornum = PHPT_SUCCESS;
    }
}

/**
 * @return \PHPTHREAD
 */
function phpt_this_thread_object() {
    global $__THREAD_OBJECT__;
    return $__THREAD_OBJECT__;
}

function phpt_main_pid() {
    global $MAIN_PROC_PID;
    return $MAIN_PROC_PID;
}

function phpt_shutdown_all() {
    $tobj = @\phpt_this_thread_object();
    if ($tobj === null) {
        return false;
    }

    @\phpt_close_socket($tobj->listener_socket);
    @\phpt_delete_socket_file();

    if (!$tobj->pid !== @\phpt_main_pid()) {
        return false;
    }

    if ($tobj->starting_thread) {
        @\posix_kill($tobj->starting_thread->pid, SIGKILL);
        $tobj->starting_thread = null;
    }

    foreach ($tobj->running_children as $pid=>$obj) {
        @\posix_kill($pid, SIGKILL);
    }
    return true;
}

$__THREAD_OBJECT__ = new PHPTHREAD();
@\register_shutdown_function("phpt_shutdown_all");
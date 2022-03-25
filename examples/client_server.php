<?php 
require_once '../lib/phpthread.php';

/* Change path if needed!!! */
define("TEST_SOCKPATH", "/tmp/phpt_cls.sock");

/* Global variable for signaling */
$_SERVER_READY = false;


/** 
 * Quick and easy, it only looks for a null char at the end, not anywhere else
 * to parse the messages thoroughly!
 * 
 * @return bool|null
 * TRUE on message to process.
 * FALSE for no message.
 * NULL for socket error.
 * 
 **/ 
function read_socket(   $socket, 
                        &$buffer)
{
    static $timer = null;

    $bytes_read = 0;
    if ($timer === null) {
        $timer = @\ftimer();
    }

    $car = array($socket);
    $caw = array();
    $cae = array();
    $sv = @\stream_select($car, $caw, $cae, 0, 5000);
    if ($sv) {
        $bread = @\fread($socket, 65535);
        if ($bread !== "") {
            $bytes_read += @\strlen($bread);
            $buffer.=$bread;
            if (substr($buffer, -1) === chr(0)) {
                /* Strip the null off since PHP counts it */
                $buffer = substr($buffer, 0, (strlen($buffer)-1));
                return true;
            }
        } else {
            return null;
        }
    } else if ($sv === false) {
        return null;
    }
    return false;
}

/**
 * Quickly send a message with a null char appended.
 * PHP treats them like any other char, but we can 
 * delimit messages with them.
 */
function send_socket(   $socket,
                        $buffer,
                        $length = null)
{

    $bytes_to_send  = (($length === null)?strlen($buffer):$length);
    $bytes_sent     = 0;
    $xmit           = 0;
    $timer          = @\ftimer();

    /**
     * Make sure socket connection doesn't break early.  Using timer... (could use select()) 
     */
    while ($bytes_to_send > 0) {
        $xmit = @\fwrite($socket, @\substr($buffer, $bytes_sent), $bytes_to_send);
        if ((int)$xmit > 0) {
            $timer->reset();
            $bytes_to_send-=$xmit;
            $bytes_sent+=$xmit;
        } else if ( $xmit === false ||
                    ($xmit === 0 && 
                    $timer->ms() > 750))
        {
            echo "[".getmypid()."] - BROKEN PIPE: Failed to transmit message to thread. ($bytes_sent bytes sent - $bytes_to_send bytes remaining)\n";
            return false;
        }
    }
    @\fwrite($socket, chr(0), 1);
    return true;
}

function close_socket( &$sock ) {
    if (!$sock) return false;
    @\stream_socket_shutdown($sock, 2);
    @\fclose($sock);
    $sock = null;
    return true;
}


function process_data(  string  $data_string,
                        int     $len) 
{
    echo "<------------------------------------------------>\n";
    echo "\t[SERVER: ".getmypid()."] - Received $len bytes.\n";
    if ($len < 100) echo $data_string."\n";
    echo "<------------------------------------------------>\n\n";
}


/**
 * We have to be careful, because $thread might
 * be null due to a possible non-async call
 * from "server_thread()"
 */
function client_server_thread(  $thread,
                                $client)
{
    global $_SERVER_READY;

    $read_buffer = "";
    $read_result = null;

    echo "\t\t[CLIENT SERVER: " . getmypid() . "] - Serving client from now on...\n";

    do {
        if ($thread) {
            $msg = $thread->get_message();
            if ($msg) {
                if ($msg->message === "quit") {
                    echo "\t\t[CLIENT SERVER: " . getmypid() . "] - Got message to shut down!\n";
                    break;
                }
            }
        }
        if ( ($read_result = read_socket($client, $read_buffer)) ) {
            send_socket(
                $client, 
                "<-------------- Server Rec'd -------------->\n" .
                "$read_buffer\n".
                "<------------------------------------------>\n\n");
            $read_buffer = "";
        }

        if ($_SERVER_READY === false) {
            echo "\t\t[CLIENT SERVER: " . getmypid() . "] - Our parent, the listening server, has shut down.  We will too!!\n";
            break;
        }

    } while ( $read_result !== null);
    close_socket($client);
    echo "\t\t[CLIENT SERVER: " . getmypid() . "] - Leaving client_server_thread()!\n";
    return 0;
}


/**
 * Messages sent to "server_thread()" will come here
 */
function server_msg_handler($sender_thread, 
                            $message)
{
    if ($message === "quit") {
        echo "\n\t[SERVER: ".getmypid()."] - QUIT message rec'd in listening server.\n\n";
        phpthread_this()->user_data = false;
    }
}

function server_thread($thread) {
    $errno  = 0;
    $errstr = "";
    $socket = null;
    $peern  = "";

    /* Client service thread variables */
    $cs_thread  = null;
    $cs_tid     = 0;

    $thread->user_data = true;  /* We'll run as long as this is true, if a "quit" message comes
                                 * in from the MAIN process, we'll set this to FALSE and quit.
                                 **/ 

    $sock_pathname = TEST_SOCKPATH;
    @\unlink($sock_pathname);

    $context = @\stream_context_create(
        array(
            'socket'=>array(
                'backlog'=>1000,
                'so_reuseport'=>true,
                'tcp_nodelay'=>true)));

    $socket = @\stream_socket_server(
        "unix://" . $sock_pathname,
        $errno,
        $errstr,
        STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
        $context);

    if (!$socket) {
        echo "\t[SERVER: ".getmypid()."] - ERROR: Failed to listen to UNIX socket: \"$sock_pathname\"\n\n";
        return -1;
    }

    if ($errno !== 0) {
        return -1;
    }

    @\chmod($sock_pathname, 0777);

    /* Let MASTER know we're ready. */
    phpthread_set_global("_SERVER_READY", true);

    echo "\t[SERVER: ".getmypid()."] - Listening for clients!\n";

    while ($thread->user_data === true) {
        $ar = array($socket);
        $aw = array();
        $ae = array();
        $sv = @\stream_select($ar, $aw, $ae, 1, 0);
        if ($sv) {
            $client = @\stream_socket_accept($socket, 1, $peern);
            if ($client) {
                $cs_tid =
                    @\phpthread_create(
                        $cs_thread,
                        array(),
                        "client_server_thread",
                        null,
                        array($client));

                if ($cs_tid <= 0) {
                    /* Failed to spawn thread, handle client ourself (yup, I'm like that) */
                    echo "\t[SERVER: ".getmypid()."] - Calling client_server_thread() to handle this connection.\n";
                    @\client_server_thread(null, $client);
                    @\close_socket($client);
                }
            }
        }
    }

    echo "\t[SERVER: ".getmypid()."] - Shutting down.\n";
    close_socket($socket);
    @\unlink(TEST_SOCKPATH);


    echo "\t[SERVER: ".getmypid()."] - Setting global \$_SERVER_READY to FALSE.\n";

    /* Let all PHPThreads know we've quit listening */
    phpthread_set_global("_SERVER_READY", false);

    echo "\t[SERVER: ".getmypid()."] - Waiting on children.\n";
    phpthread_join_all();

    echo "\t[SERVER: ".getmypid()."] - Exiting now.\n";
    return 0;
}


/**** Start Execution ****/

$thread = null;
$tid    = 0;
$retval = 0;
$errno  = 0;
$errstr = "";
$socket = null;
$rxbuff = "";
$rxres  = null;
$runtm  = ftimer();

$sendbuffer = "This is some data to send to the server.\n";
$length     = strlen($sendbuffer);


$tid = phpthread_create(
        $thread,
        array(
            'onmessage'=>"server_msg_handler"
        ),
        "server_thread");

if ($tid <= 0) {
    echo "** ERROR: Failed to launch server thread.\n\n";
    exit(-$tid);
}


if (phpthread_wait_for_variable($_SERVER_READY, true, 1000) === PHPT_WAIT_TIMEOUT) {
    echo "\n[MASTER: " . getmypid() ."] - ERROR: Timeout waiting for server to be ready.\n\n";
    goto cleanup;
}

echo "[MASTER: " . getmypid() ."] - Server is ready.\n";

$context = @\stream_context_create(
    array(
        'socket'=>array(
            'backlog'=>1000,
            'so_reuseport'=>true,
            'tcp_nodelay'=>true
        ),
    )
);

$socket = @\stream_socket_client(
    "unix://" . TEST_SOCKPATH,
    $errno,
    $errstr,
    1,
    STREAM_CLIENT_CONNECT|STREAM_CLIENT_ASYNC_CONNECT,
    $context);

if (!$socket ||
    $errno !== 0)
{
    echo "[MASTER: " . getmypid() ."] - ERROR: Failed to connect to UNIX socket: \"".TEST_SOCKPATH."\"\n\n";
    exit(1);
}

echo "\n[MASTER: " . getmypid() ."] - Sending $length bytes to server.\n";

if (!send_socket($socket, $sendbuffer, $length)) {
    echo "\n[MASTER: " . getmypid() ."] - ERROR: Failed to send $length bytes to server!\n";
} else {
    $second_sent = false;

    $runtm->reset(); /* Zero our timer */

    echo "\n[MASTER: " . getmypid() ."] - Waiting 8 seconds playing network connection.\n";

    while ($runtm->secs() < 8) {

        if ($runtm->secs() === 4 &&
            !$second_sent)
        {
            $second_sent = true;
            send_socket($socket, "Boom!  Another message from ya' homie!");
        }


        $rxres = read_socket($socket, $rxbuff);
        if ($rxres === null) {
            echo "\n[MASTER: " . getmypid() ."] - Lost connection to server!!\n";        
            break;
        } else if ($rxres === true) {
            echo $rxbuff;
            $rxbuff = "";
        }
    }
    echo "\n[MASTER: " . getmypid() ."] - Closing socket.\n";

    /**
     * v--- We can comment this out and see how client_server_thread() cleans
     * up on its own when it realizes that server_thread() has exited and is
     * waiting for it...
     * 
     * Otherwise, this line will trigger it to shut down immediately.
     * 
     */
    @\close_socket($socket);
}



cleanup:
echo "\n[MASTER: " . getmypid() ."] - Sending 'quit' message to listening server.\n";   
$thread->send_message("quit");

echo "\n[MASTER: " . getmypid() ."] - Waiting on thread to exit.\n";

if (phpthread_join($tid, $retval, 3000) !== PHPT_JOIN_SUCCESS) {
    echo "\n[MASTER: " . getmypid() ."] - ERROR: Server thread failed to shut down!\n\n";
    $thread->kill();
    exit(1);
}

echo "Server shut down gracefully.  Return value: " . print_r($retval, true) . "\n\n";
exit(0);
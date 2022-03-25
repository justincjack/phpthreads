<?php 

function __data_interrupt( $pid ) {
    return @\posix_kill($pid, SIGDATA);
}

function phpt_get_socket_path( $pid = null ) {
    if ($pid === null) {
        $pid = @\getmypid();
    }
    return PHPT_SOCKET_DIR . $pid . ".sock";
}

function phpt_wait_for_socket_ready( &$socket ) {
    $timer = @\ftimer();

    if ($socket === null) {
        return false;
    }

    while ($timer->ms() <= 250) {
        $ar = array();
        $aw = array($socket);
        $ae = array();
        $sv = @\stream_select($ar, $aw, $ae, 0, 2000);
        if ($sv === 1 &&
            @\count($aw) > 0)
        {
            return true;
        }
    }
    return false;
}

function phpt_close_socket( &$socket ) {
    if ($socket === null) {
        return true;
    }
    @\stream_socket_shutdown($socket, 2);
    @\fclose($socket);
    $socket = null;
    return true;
}

function phpt_delete_socket_file( $pid = null) {
    @\unlink(phpt_get_socket_path($pid));
}

function phpt_thread_connect(   $pid,
                                &$errno,
                                &$errstr)
{
    $errno  = 0;
    $errstr = "";
    $socket = null;
        
    $sock_pathname = @\phpt_get_socket_path($pid);

    if (@\getmypid() === $pid) {
        $errno = -1;
        $errstr = "Cannot connect to self!";
        return null;
    }

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
        "unix://" . $sock_pathname,
        $errno,
        $errstr,
        1,
        STREAM_CLIENT_CONNECT|STREAM_CLIENT_ASYNC_CONNECT,
        $context);

    if (!$socket ||
        $errno !== 0)
    {
        return null;
    }

    @\__data_interrupt($pid);

    if (!@\phpt_wait_for_socket_ready($socket)) {
        $errno = -1;
        $errstr = "Failed to connect to running thread";
        @\phpt_close_socket($socket);
        return null;
    }
    return $socket;
}


function unixlisten(int     &$errno,
                    string  &$errstr)
{
    $errno  = 0;
    $errstr = "";
    $socket = null;

    $sock_pathname = @\phpt_get_socket_path();
    @\unlink($sock_pathname);

    $context = @\stream_context_create(array(
        'socket'=>array(
            'backlog'=>1000,
            'so_reuseport'=>true,
            'tcp_nodelay'=>true
        )
    ));

    $socket = @\stream_socket_server(
        "unix://" . $sock_pathname,
        $errno,
        $errstr,
        STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
        $context);
    
    if ($errno !== 0) {
        return null;
    }

    if (@\stream_set_blocking($socket, false) === false) {
        echo "\n\n** ERROR: Failed to set socket as non-blocking.\n";
    }

    @\chmod($sock_pathname, 0777);
    return $socket;
}

function phpt_send_message( $target_pid,
                            $message)
{
    $phpt   = @\phpt_this_thread_object();
    $errno  = 0;
    $errstr = 0;

    if (!@\is_object($message)) {
        echo "ERROR: phpt_send_message() - Message was not a valid object.\n";
        var_dump($message);
        echo "------------------------------------------------------------\n";
        return false;
    }

    $message->from_pid = $phpt->pid;
    $message->parent_pid = $phpt->parent_pid;

    if ($phpt === null) {
        echo "ERROR: This thread does not have a PHPTHREAD object\n";
        return false;
    }

    if ($target_pid === $phpt->pid) {
        echo "ERROR: Cannot send a message to one's self\n";
        return false;
    }

    $message->compressed = false;

    if (@\property_exists($message, "data")) {
        $len_check = @\json_encode($message->data);
        if ($len_check &&
            @\strlen($len_check) >= 1024000)
        {
            $message->compressed = true;
            $compressed = @\gzcompress($len_check);
            $b64 = null;
            if ($compressed) {
                $b64 = @\base64_encode($compressed);
            }
            if ($b64) {
                $message->data = $b64;
            }
        }
    }

    $msg_to_send = @\json_encode($message);

    if (!$msg_to_send) {
        $try_to_send = false;
        if (@\property_exists($message, "data")) {
            $data_encoded = @\json_encode($message->data);
            if (!$data_encoded) {
                $message->data = "<ERROR: Encoding Failed>";
                $msg_to_send = @\json_encode($message);
                if ($msg_to_send) {
                    $try_to_send = true;
                }
            }
        }
        if ($try_to_send === false) {
            echo "ERROR: The given message could not be converted to JSON to send!\n";
            echo "[ phpt_send_message() ] Message:\n";
            echo "------------------------------------------------------------\n";
            print_r($message);
            echo "\n------------------------------------------------------------\n\n\n";
            return false;
        }
    }

    $cnx = @\phpt_thread_connect($target_pid, $errno, $errstr);

    if (!$cnx) {
        return false;
    }

    $bytes_to_send  = @\strlen($msg_to_send);
    $bytes_sent     = 0;
    $xmit           = 0;
    $timer          = @\ftimer();

    /**
     * Make sure socket connection doesn't break early.  Using timer... (could use select()) 
     */
    do {
        $xmit = @\fwrite($cnx, @\substr($msg_to_send, $bytes_sent), $bytes_to_send);
        if ((int)$xmit > 0) {
            $timer->reset();
            $bytes_to_send-=$xmit;
            $bytes_sent+=$xmit;
            @\__data_interrupt($target_pid);
        } else if ( $timer->ms() > 750) {
            echo "[".getmypid()."] - BROKEN PIPE: Failed to transmit message to thread. ($bytes_sent bytes sent - $bytes_to_send bytes remaining)\n";
            @\phpt_close_socket($cnx);
            return false;
        }
    } while ($bytes_to_send > 0);
    @\phpt_close_socket($cnx);
    return true;
}

function phpt_data_type( $var_to_type ) {
    if (@\is_string($var_to_type)) {
        return "string";
    } else if (@\is_bool($var_to_type)) {
        return "bool";
    } else if (@\is_numeric($var_to_type)) {
        if (@\is_integer($var_to_type)) {
            return "int";
        } else if (@\is_float($var_to_type)) {
            return "float";
        } else if (@\is_double($var_to_type)) {
            return "double";
        } else {
            return "number";
        }
    } else if (@\is_array($var_to_type)) {
        return "array";
    } else if (@\is_object($var_to_type)) {
        return "object";
    } else if (@\is_null($var_to_type)) {
        return "null";
    }
    return "unknown";    
}

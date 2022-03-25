<?php 
pcntl_async_signals(true);
define("PHPT_ROOT_DIR",             __DIR__."/../src/");
define("PHPT_SOCKET_DIR",           __DIR__."/../sockets/");

define("SIGDATA",                   SIGUSR1);
define("SIGBEGIN",                  SIGUSR2);

define("PHPT_SUCCESS",              0);
define("PHPT_NOT_INITIALIZED",      -1);        /* new PHPTHREAD() wasn't called by the lib correctly - The library hasn't been initialized */
define("PHPT_SOCK_FAILED",          -2);        /* A call to "unixlisten()" failed. */
define("PHPT_BAD_THREADPROC",       -3);        /* The given thread entry point function or method is invalid */
define("PHPT_FORK_ERROR",           -4);        /* fork() failed */
define("PHPT_START_TIMEOUT",        -5);        /* Timeout occured waiting on child thread to start */
define("PHPT_THREAD_GONE",          -6);        /* Child thread has terminated unexpectedly and couldn't receive SIGBEGIN */

define("PHPTHREAD_CREATE_ERRORS",   array(
    "PHPT_SUCCESS",
    "PHPT_NOT_INITIALIZED",
    "PHPT_SOCK_FAILED",
    "PHPT_BAD_THREADPROC",
    "PHPT_FORK_ERROR",
    "PHPT_START_TIMEOUT",
    "PHPT_THREAD_GONE",
    "",
    "",
));

define("PHPT_NO_PHPTHREAD_CLASS",   -1);

define("PHPT_CONTEXT_CHILD",        0);
define("PHPT_CONTEXT_PARENT",       1);
define("PHPT_CONTEXT_MASTER",       2);
define("PHPT_CONTEXT_OTHER",        3);


define("PHPT_MESSAGE_CHILD_BEGIN",  0);
define("PHPT_MESSAGE_SET_GLOBAL",   1);
define("PHPT_MESSAGE_RETURN",       2);
define("PHPT_MESSAGE_IPC",          3);


define("PHPT_JOIN_SUCCESS",          0);
define("PHPT_THREAD_NOT_FOUND",      1);
define("PHPT_JOIN_TIMEOUT",          2);

define("PHPTHREAD_JOIN_WAIT_INFINITE",  -1);
define("PHPTHREAD_NO_WAIT",             -2);

define("PHPT_WAIT_SUCCESS",          0);
define("PHPT_WAIT_TIMEOUT",          1);


define("PHPT_RX_MAX",               8192);

function fatal( $error_string ) {
    $error_string = @\trim($error_string);
    echo "\nphpthread - FATAL ERROR: $error_string\n\n";
    exit(-1);
}

@\umask(0);

if (!@\file_exists(PHPT_SOCKET_DIR)) {
    if (!@\mkdir(PHPT_SOCKET_DIR)) {
        @\fatal("Failed to create socket directory: \"".PHPT_SOCKET_DIR."\"");
    }
    if (!@\chmod(PHPT_SOCKET_DIR, 0777)) {
        @\fatal("chmod() failed for the PHPTHREAD socket directory: \"".PHPT_SOCKET_DIR."\"");
    }
}

/**
 * Check that required dependencies are installed
 */
if (!@\function_exists("stream_socket_client")) {
    @\fatal("A required dependency (e.g. \"stream_socket_client\") is missing.  phpthreads requires PHP's \"stream_socket_xxx()\" functionality.  Please install \"php-socket.\"");
}

if (!@\function_exists("pcntl_fork")) {
    @\fatal("A required dependency (e.g. \"pcntl_fork\") is missing.  phpthreads requires PHP's \"pcntl_fork()\" functionality.  Please install \"php-process.\"");
}

if (!@\function_exists("posix_kill")) {
    @\fatal("A required dependency (e.g. \"posix_kill\") is missing.  phpthreads requires PHP's \"posix_kill()\" functionality.  Please install \"php-posix.\"");
}

if (!@\function_exists("ftimer")) {

    class FLEX_TIMER {
        private $timeref = 0;

        public function us(): int {
            return @\round((@\microtime(true) - $this->timeref) * 1000000, 0);
        }

        public function ms(): int {
            return @\round((@\microtime(true) - $this->timeref) * 1000, 0);
        }

        public function secs(): int {
            return @\round((@\microtime(true) - $this->timeref), 0);
        }

        public function minutes(): int {
            return @\round($this->secs() / 60, 0);
        }

        public function hours(): int {
            return @\round($this->secs() / 3600, 0);
        }

        public function reset(): void {
            $this->timeref = @\microtime(true);
        }

        public function __construct() {
            $this->timeref = @\microtime(true);
        }
    }

    /**
     * 
     * @return FLEX_TIMER
     * A UTIMER object for measuring microseconds since it was created.
     */
    function ftimer(): FLEX_TIMER {
        return new FLEX_TIMER();
    }
}
/* To initialize the static variables in utimer() for faster (more accurate) execution next time */
ftimer();


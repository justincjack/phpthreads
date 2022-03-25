# PHPThreads - Powerful easy-to-use parallel processing library for PHP
## By Justin Jack

If you're like me, you **love** PHP!  You love the flexibility and power of the language - the freedom to write sloppy code (I don't advocate it, but I'll defend to the death the right to write sloppy code), or to create indisputably beautiful works of logical art!  Now, for all my C (and C++, I guess) programmers, you have a library to not only truly show off the power of PHP, but to ***supercharge your next project!***

This library allow you to very efficiently parallel process in a way the very, very closely resembles POSIX multithreading.  You can share ***global variables*** between all PHPThreads, pass values/objects/arrays to your PHPTHread at creation, share values/objects/arrays easily between running PHPThreads, and receive PHPThread exit values/objects/arrays when each PHPThread terminates via the `phpthread_join()` family of functions.

I designed this library because I wanted to write a high-performance WebSocket server to easily handle thousands of simultaneous connections through which large amounts of data could be transfered quickly (think images or video).  Yes, fundamentally, it's a `fork()`ing server, but the PHPThread layer on top makes it *super* easy to deploy while providing a mechanism for easy (and thread-like) IPC.  I could have used C and gotten better performance, but for me, this seemed more portable and easy to deploy as servers are spun up, so now here it is.

For you more technical folks, this library isn't temporally impeded by the need to use "ticks."  That's part of what makes it so fast.  The library uses signals (e.g. SIGUSR1 and SIGUSR2) internally to interrupt the execution and switch context very fluidly.  Yes, we're hogging SIGUSR1 and 2, so if your project is using those signals, consider using `phpthread_send_message()` to a designated message handler function (see: examples/messages1.php and examples/messages2.php).  It should completely suit your purpose.

There are examples you can run in the "examples" directory.  Feel free to place a shebang for easy execution, or run them by prefixing them with `php <filename>`.

### Dependencies
PHPThreads requires the following PHP extensions:
- posix
- sockets
- pcntl

## Quick and dirty example!
`
<?php 
require_once '../lib/phpthread.php'; /* Use correct path! */

function thread_proc(   $thread, 
                        $param)
{
    echo "\n\t[PHPTHREAD] - I've started and have a param: \"" . print_r($param, true) . "\"!\n";
    for ($i = 0; $i < 15; $i++) {
        usleep(1000000);
        echo "\t[PHPTHREAD] - Tick!\n";
    }
    $myobj = (object)array(
        'seconds_alive'=>$i,
        'lucky_number'=>rand(0, 10000)
    );

    echo "\n\t[PHPTHREAD] - I'm returning this:\n";
    print_r($myobj);
    echo "\n";
    echo "<--------------------------------------->\n\n";
    return $myobj;
}


$id = phpthread_create($phpthread, array(), "thread_proc", null, array("this awesome string!"));

for ($i = 0; $i < 5; $i++) {
    echo "\n[MAIN] - Doing my thing...\n";
    usleep(1000000);
}

echo "\n[MAIN] - Okay, I'm tired.  Waiting on child to quit.\n";
phpthread_join($id, $retval);
echo "\n[MAIN] - Child is done.  It returned:\n";
print_r($retval);`
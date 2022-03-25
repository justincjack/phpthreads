Common mistakes:
----------------------------------------------------------------------------

In message handler functions: 

1.  The first PHPThread parameter is the SENDER's PHPThread handle.  Not the 
    RECEIVER's.  If you want to get a reference to the RECEIVE's (i.e. your own)
    PHPThread handle to update the "user_data" property or something, 
    call "phpthread_this()"!

2.  Don't dilly dally.  Don't wait on things.  Message handlers should set evaluate,
    calculate, and set variables.  In and out.  That's the name of the game.


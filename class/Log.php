<?php

class Log
{
    private final function __construct ()
	{}

    static public function info ($message, $subject = '', $cc = [])
    {
        (Mail::singleton ())->send ('[SUCCESS] ' + $subject, $message, $cc);
    }

    static public function warning ()
    {
        (Mail::singleton ())->send ('[WARNING] ' + $subject, $message, $cc);
    }

    static public function critical ()
    {
        (Mail::singleton ())->send ('[CRITICAL] ' + $subject, $message, $cc);
    }
}

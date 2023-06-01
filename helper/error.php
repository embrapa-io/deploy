<?php

function handleError ($errno, $errstr, $errfile = '', $errline = '', $errcontext = '')
{
	if (error_reporting () === 0)
		return FALSE;

	throw new Exception ($errstr .' ['. $errno .' | '. $errfile .' | '. $errline .']');
}

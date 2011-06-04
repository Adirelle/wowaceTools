#!/usr/bin/php
<?php

$args = $_SERVER['argv'];

$file = $args[1];

// Execute the parsing
exec("luac -l -p ".escapeshellarg($file)." 2>&1", $lines, $retVal);

// In case of errors, output them so gedit can catch them
if($retVal != 0) {
	foreach($lines as $line) {
		$line = preg_replace('/^luac: /', '', $line);
		fputs(STDERR, "$line\n");
	}
	exit($retVal);
}

// Read the API globals, to ignore in main chunks
$api = array();
$APIGlobalsFile = dirname($args[0]).DIRECTORY_SEPARATOR."APIGlobals";
$fh = fopen($APIGlobalsFile, "rt");
while($word = fgets($fh)) {
	$word = trim($word);
	$api[$word] = true;
}
fclose($fh);

// Read the globals to ignore, from source comments
$ignore = array(
	'_G' => true,
	'LibStub' => true,
	'AdiDebug' => true
);
$warn = array('math' => true, 'string' => true, 'table' => true, 'bit' => true);

$functions = array();

$fh = fopen($file, "rt");
for($num = 1; $line = fgets($fh); $num++) {
	if(preg_match('/^\s*--\s*globals:\s*(.*)\s*$/i', $line, $parts)) {
		$words = preg_split('/[\s,]+/', $parts[1]);
		foreach($words as $word) {
			$ignore[$word] = true;
		}
	} elseif(preg_match('/function\s+((?:\w+\s*[\.:]\s*)?\w+)/', $line, $parts)) {
		$functions[$num] = $parts[1];
	}
}
fclose($fh);

$chunk = null;
$toLocals = array();

// Read all parsing lines
foreach($lines as $line) {

	if(substr($line,0,4) == "main") {
		// Starting main chunk
		$chunk = 'main chunk';

	} elseif(preg_match('/^function\s+<.*:(\d+),\d+>/', $line, $parts)) {
		// Stating a function
		$lineNum = intval($parts[1]);
		if(isset($functions[$lineNum])) {
			$chunk = 'function '.$functions[$lineNum];
		} else {
			$chunk = 'anonymous function';
		}
		
	} elseif(preg_match('/\[(\d+)]\\s+([GS]ET)GLOBAL.*;\s*(.*)\s*$/', $line, $parts)) {
		// Access to a global variabale
		
		list(, $line, $action, $word) = $parts;
			
		if(isset($ignore[$word])) {
			// Ignore globals to ignore
			continue;
		}
		
		if($action == 'GET' && isset($api[$word]) && !isset($warn[$word])) {
			// Getting known API
			
			if($chunk != 'main') {
				// Want it as an upvalue to prevent _G lookups
				$toLocals[$word] = true;
			}
			
		} else {
			// Setting a global or getting unknown globals: warning
			fprintf(STDERR, "%s:%d: %sting global '%s' in %s\n", $file, $line, strtolower($action), $word, $chunk);
		}
		
	}
}

if(!empty($toLocals)) {
	uksort($toLocals, "strnatcasecmp");
	echo "local _G = _G\n";
	foreach($toLocals as $word => $_) {
		printf("local %s = _G.%s\n", $word, $word);
	}
}



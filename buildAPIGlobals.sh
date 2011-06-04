#!/bin/sh

(
	# wowprogramming.com API
	curl -s http://wowprogramming.com/docs/api | perl -ne 'm@/docs/api/(\w+)@i and print "$1\n";'

	# Declared constants
	luac -p -l $(find FrameXML/ -name "*.lua") | perl -ne '/SETGLOBAL.*; (\w+)/ and print "$1\n";'

	# Frame names
	perl -ne '/name="([a-z]+)"/i && !/virtual="true"/ and print "$1\n";' $(find FrameXML/ -name "*.xml" -a \! -name "Bindings.xml")

) | sort -u -i >APIGlobals


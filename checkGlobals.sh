#!/bin/bash

(
	TMPFILES=""
	trap 'rm $TMPFILES' EXIT
	for SRC in "$@"; do
		OUTPUT="$(mktemp checkGlobals.XXXXXX)"
		TMPFILES="$OUTPUT $TMPFILES"
		if luac -p -l "$SRC" >"$OUTPUT"; then
			GLOBALS="$(perl -ne '/^--\s+GLOBALS:\s+(.*)\s*$/ and print $1;' $SRC)"
			EXCLUDE="$(mktemp checkGlobals.exclude.XXXXXX)"
			TMPFILES="$EXCLUDE $TMPFILES"
			echo _G >"$EXCLUDE"
			for G in $GLOBALS; do
				echo "$G" >>"$EXCLUDE"
			done
			perl -ne '/\[(\d+)\].*[SG]ETGLOBAL.*?; (.*)$/ and print "'"$SRC"':$1: $2\n"' "$OUTPUT" | fgrep -v -f "$EXCLUDE" | sort -t" " -k2 -u | sort -t: -k2 -n
			sed -e '0,/^function/d' "$OUTPUT" | perl -ne '/GETGLOBAL.*?; (.*)$/ and print "$1\n"' | fgrep -v -f "$EXCLUDE" >"$OUTPUT.2"
			TMPFILES="$OUTPUT.2 $TMPFILES"
			if [ -s "$OUTPUT.2" ]; then
				echo "local _G = _G"
				sort -f -u "$OUTPUT.2" | while read WORD; do
					echo "local $WORD = _G.$WORD"
				done
			fi
		fi
	done
) 2>&1 | sed -e 's/^luac: //'

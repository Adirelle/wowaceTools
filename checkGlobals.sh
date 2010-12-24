#!/bin/bash

for SRC in "$@"; do
	luac -p -l "$SRC" | perl -ne '/\[(\d+)\].*[SG]ETGLOBAL.*?; (.*)$/ and print "'"$SRC"':$1: $2\n"' 
done | fgrep -v -f "$(dirname $0)/wowglobals.txt"


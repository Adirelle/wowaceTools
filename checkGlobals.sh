#!/bin/bash

luac -p -l "$@" | perl -ne '/[SG]ETGLOBAL.*?; (.*)$/ and print "$1\n"' | fgrep -v -f "$(dirname $0)/wowglobals.txt" | sort -u

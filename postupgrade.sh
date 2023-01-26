#!/bin/bash

# To use important variables from command line use the following code:
PTEMPDIR=$1   # First argument is temp folder during install
PDIR=$3       # Third argument is Plugin installation folder

# Combine them with /etc/environment
PCONFIG=$LBPCONFIG/$PDIR

echo "<INFO> Copy back existing config files"
cp -f -r /tmp/$PTEMPDIR\_upgrade/config/$PDIR/* $LBHOMEDIR/config/plugins/$PDIR/ 

echo "<INFO> Remove temporary folders"
rm -r /tmp/$PTEMPDIR\_upgrade

# Exit with Status 0
exit 0

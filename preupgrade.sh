#!/bin/bash

# To use important variables from command line use the following code:
PTEMPDIR=$1   # First argument is temp folder during install
PDIR=$3       # Third argument is Plugin installation folder

# Combine them with /etc/environment
PCONFIG=$LBPCONFIG/$PDIR

echo "<INFO> Creating temporary folder for upgrading"
mkdir -p /tmp/$PTEMPDIR\_upgrade/config

echo "<INFO> Backing up existing config files"
cp -v -r $PCONFIG/ /tmp/$PTEMPDIR\_upgrade/config

# Exit with Status 0
exit 0
